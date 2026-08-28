<?php
/**
 * Plugin Name: CloudScale Cleanup
 * Plugin URI:  https://cloudscale.consulting
 * Description: Database and media library cleanup with dry-run preview, image optimisation, PNG to JPEG conversion, and chunked processing safe on any server. Free, open source, no subscriptions.
 * Version:     2.5.106
 * Author:      CloudScale
 * Author URI:  https://cloudscale.consulting
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cloudscale-cleanup
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Our UI uses plain native emoji characters everywhere. WP's built-in twemoji
// polyfill replaces them with <img> tags from s.w.org when it (sometimes wrongly)
// decides a browser lacks native emoji support; if that CDN is ever unreachable
// the images break. Native rendering is all we need, so skip the polyfill outright.
add_action( 'init', function () {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'embed_head', 'print_emoji_detection_script' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    add_filter( 'tiny_mce_plugins', function ( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : $plugins;
    } );
    // Matched on the host EXACTLY: not on a substring, and not on one exact URL.
    //
    // There are two ways to get this wrong and this filter has now had both. array_diff()
    // against the single literal 'https://s.w.org' stopped removing anything the moment core
    // used the other form it has shipped, '//s.w.org' — a registered filter quietly doing
    // nothing. Replacing it with strpos() then over-matched in the other direction:
    // 'ps.w.org' CONTAINS 's.w.org', and ps.w.org is a real WordPress host, so a hint naming
    // it would have been stripped as well.
    //
    // Entries arrive in more than one shape and all of them have to be handled: a full URL,
    // a scheme-relative '//host', and a BARE host with no scheme at all, because the
    // dns-prefetch list is assembled from parsed hostnames rather than URLs. Preconnect
    // entries may instead be an attribute array keyed 'href'. Anything unrecognised is KEPT
    // — the job here is to remove one known hint, so in doubt it must not remove.
    add_filter( 'wp_resource_hints', function ( $urls ) {
        if ( ! is_array( $urls ) ) {
            return $urls;
        }
        // This is the host of the emoji CDN hint being REMOVED from wp_resource_hints: the
        // plugin's effect here is to stop a remote asset load, not to start one.
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- host of the hint being removed, never requested.
        $emoji_host = 's.w.org';
        return array_values(
            array_filter(
                $urls,
                static function ( $u ) use ( $emoji_host ) {
                    if ( is_array( $u ) ) {
                        $u = isset( $u['href'] ) ? $u['href'] : '';
                    }
                    if ( ! is_string( $u ) || '' === $u ) {
                        return true;
                    }
                    $host = (string) wp_parse_url( $u, PHP_URL_HOST );
                    if ( '' === $host ) {
                        $host = ltrim( $u, '/' ); // a bare host, carrying no scheme
                    }
                    return 0 !== strcasecmp( $host, $emoji_host );
                }
            )
        );
    } );
}, 1 );

// wp-admin/includes/admin-filters.php re-registers the admin_print_scripts/
// admin_print_styles emoji hooks, and that file loads after `init` has already
// fired — so the removal above never catches them on admin pages. Remove again
// on admin_init, which runs after admin-filters.php has loaded.
add_action( 'admin_init', function () {
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
}, 1 );

define( 'CLOUDSCALE_CLEANUP_VERSION', '2.5.106' );
define( 'CLOUDSCALE_CLEANUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLOUDSCALE_CLEANUP_URL', plugin_dir_url( __FILE__ ) );
define( 'CLOUDSCALE_CLEANUP_SLUG', 'cloudscale-cleanup' );

// Error text in the units the timeouts are set in: WordPress reports a 10-second ceiling as
// "10000 milliseconds". Required before the shared Telegram class, which uses it too.
require_once CLOUDSCALE_CLEANUP_DIR . 'includes/class-cloudscale-error-text.php';
require_once CLOUDSCALE_CLEANUP_DIR . 'includes/class-cloudscale-telegram.php';

// Clear opcode cache on activation so updated files take effect immediately
register_activation_hook( __FILE__, function() {
    if ( function_exists( 'opcache_reset' ) ) {
        opcache_reset();
    }
} );

// Also clear on every admin page load if version changed
add_action( 'admin_init', function() {
    $cached_version = get_option( 'cscc_loaded_version', '' );
    if ( $cached_version !== CLOUDSCALE_CLEANUP_VERSION ) {
        if ( function_exists( 'opcache_reset' ) ) {
            opcache_reset();
        }
        update_option( 'cscc_loaded_version', CLOUDSCALE_CLEANUP_VERSION );
    }
} );

/*
 * CHUNKED PROCESSING ARCHITECTURE
 * ─────────────────────────────────────────────────────────────────────────────
 * Every "run" operation works in three AJAX steps:
 *
 *   Step 1  cscc_*_start, Build the full list of IDs to process, store in a
 *                           transient, return the total count to JS.
 *
 *   Step 2  cscc_*_chunk, Pull the transient, process one small batch, update
 *                           the transient with the remaining IDs, return log
 *                           lines + remaining count. JS fires repeatedly until
 *                           remaining === 0.
 *
 *   Step 3  cscc_*_finish, Clean up the transient, write the last-run
 *                           timestamp, return a summary line.
 *
 * Each AJAX request completes in well under 30 seconds on any shared host.
 * Chunk sizes: 50 DB items · 25 image deletions · 5 image optimisations.
 */

define( 'CSCC_CHUNK_DB',       50 );
define( 'CSCC_CHUNK_IMAGES',   10 );
define( 'CSCC_CHUNK_OPTIMISE',  5 );

/**
 * Wall-clock deadline for one chunk of work, derived from the host's own limit.
 *
 * This replaces raising the request time limit, which WordPress.org rejects outright and which
 * was never dependable: many hosts refuse it, and a hard FastCGI or proxy ceiling is not
 * something PHP can lift at all. Bounding the work and letting the client ask for the next slice
 * is strictly more robust than assuming an unlimited request.
 *
 * The reserve is the important part. Where an item is claimed before it is processed, a request
 * killed mid-item drops that item silently — so the deadline is checked only BETWEEN items, and
 * enough time is held back for one whole worst-case item. On this plugin's target hardware a
 * single image can take 30-60s, which is why callers pass a large reserve rather than a token one.
 *
 * PHP counts only the script's own execution time here: stream operations, system calls and
 * database queries are excluded (Windows is the exception, where the measured time is real). Image
 * encoding through GD or Imagick is in-process CPU, so it DOES count, which is exactly why these
 * handlers needed the ceiling raised in the first place.
 *
 * @since 1.6.30
 * @param int $reserve Seconds to hold back for one more item.
 * @return int Unix timestamp after which no NEW item should be started.
 */
function cscc_chunk_deadline( int $reserve ): int {
    $host_limit = (int) ini_get( 'max_execution_time' );
    // 0 means "no limit configured" (CLI, or a host that removed it). Still bound the work: an
    // unbounded AJAX request that never returns looks identical to a hung one from the browser.
    $budget = $host_limit > 0 ? ( $host_limit - $reserve ) : 55;
    return time() + max( 5, $budget );
}

// PNG to JPEG converter constants
// These carried a third prefix (cspj_) alongside this plugin's own cscc_, which WordPress.org
// flags: one distinctive prefix per plugin. Renaming the CONSTANTS is free — they persist
// nothing. The option KEY is different: 'cspj_chunk_mb' is a live row in wp_options, so the new
// name is what gets written and the old one is still READ as a fallback. Nothing deletes the old
// row here, so downgrading keeps working; uninstall.php removes both.
define( 'CSCC_OPTION_CHUNK_MB',  'cscc_chunk_mb' );
define( 'CSCC_LEGACY_CHUNK_MB',  'cspj_chunk_mb' );
define( 'CSCC_DEFAULT_CHUNK_MB', 1.5 );
define( 'CSCC_MAX_TOTAL_MB',     200 );

// ─── Admin menu ──────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'cscc_add_menu' );
function cscc_add_menu() {
    add_management_page(
        'CloudScale Cleanup',
        '🌩️ CloudScale Cleanup',
        'manage_options',
        CLOUDSCALE_CLEANUP_SLUG,
        'cscc_render_page'
    );
}

// ─── Enqueue assets ──────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'cscc_enqueue_assets' );

function cscc_enqueue_assets( $hook ) {
    // Both hook suffixes: WordPress serves this page as tools_page_* when the Tools submenu is
    // visible to the user and admin_page_* when it is not (a capability that hides the Tools menu
    // detaches the submenu, and the page is registered as hidden instead). Accepting only
    // tools_page_* loaded no CSS and no JS for such a user, so the panel rendered as raw markup:
    // server-rendered elements were present and every JS-driven one stayed hidden for ever.
    if ( 'tools_page_cloudscale-cleanup' !== $hook && 'admin_page_cloudscale-cleanup' !== $hook ) {
        return;
    }

    wp_enqueue_style(
        'cs-design-system',
        CLOUDSCALE_CLEANUP_URL . 'cloudscale-admin.css',
        array(),
        CLOUDSCALE_CLEANUP_VERSION
    );
    wp_enqueue_style(
        'cloudscale-cleanup-css',
        CLOUDSCALE_CLEANUP_URL . 'admin.css',
        array( 'cs-design-system' ),
        CLOUDSCALE_CLEANUP_VERSION
    );
    wp_enqueue_script(
        'cloudscale-cleanup-js',
        CLOUDSCALE_CLEANUP_URL . 'admin.js',
        array( 'jquery' ),
        CLOUDSCALE_CLEANUP_VERSION,
        true
    );
    $cspj_chunk_mb    = cscc_get_cspj_chunk_mb();
    $cspj_server_max  = cscc_get_cspj_server_max_mb();
    $cscc_nonce = wp_create_nonce( 'cscc_nonce' );
    wp_localize_script( 'cloudscale-cleanup-js', 'CSC', array(
        'ajax_url'       => admin_url( 'admin-ajax.php' ),
        'nonce'          => $cscc_nonce,
        'cspj_chunk_mb'  => $cspj_chunk_mb,
        'cspj_server_max_mb' => $cspj_server_max,
        'cspj_max_total_mb'  => CSCC_MAX_TOTAL_MB,
        'version'        => CLOUDSCALE_CLEANUP_VERSION,
    ) );

    // Fallback: ensure CSC is always available even if wp_localize_script fails.
    $cscc_fallback_js = 'if(typeof CSC==="undefined"||!CSC.ajax_url){'
        . 'window.CSC=window.CSC||{};'
        . 'CSC.ajax_url=CSC.ajax_url||' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ';'
        . 'CSC.nonce=CSC.nonce||' . wp_json_encode( $cscc_nonce ) . ';'
        . 'CSC.cspj_chunk_mb=CSC.cspj_chunk_mb||' . wp_json_encode( $cspj_chunk_mb ) . ';'
        . 'CSC.cspj_server_max_mb=CSC.cspj_server_max_mb||' . wp_json_encode( $cspj_server_max ) . ';'
        . 'CSC.cspj_max_total_mb=CSC.cspj_max_total_mb||' . intval( CSCC_MAX_TOTAL_MB ) . ';'
        . 'CSC.version=CSC.version||' . wp_json_encode( CLOUDSCALE_CLEANUP_VERSION ) . ';'
        . 'console.log("[CSC] Fallback CSC injected inline. wp_localize_script may not have fired.");'
        . '}';
    wp_add_inline_script( 'cloudscale-cleanup-js', $cscc_fallback_js );

    // Tab colours and health metric styles, inline fallback (cache proof).
    $cscc_inline_css = '
    .csc-tab:nth-child(1) { background: linear-gradient(135deg, #4a148c 0%, #7b1fa2 100%) !important; border-top-color: #ce93d8 !important; }
    .csc-tab:nth-child(1).active { background: linear-gradient(135deg, #6a1b9a 0%, #9c27b0 100%) !important; border-top-color: #ce93d8 !important; }
    .csc-tab:nth-child(1):hover:not(.active) { border-top-color: #ce93d8 !important; }
    .csc-tab:nth-child(6) { background: linear-gradient(135deg, #0d47a1 0%, #1565c0 100%) !important; border-top-color: #90caf9 !important; }
    .csc-tab:nth-child(6).active { background: linear-gradient(135deg, #1565c0 0%, #1976d2 100%) !important; border-top-color: #90caf9 !important; }
    .csc-tab:nth-child(6):hover:not(.active) { border-top-color: #90caf9 !important; }
    .csc-tab:nth-child(7) { background: linear-gradient(135deg, #5d4037 0%, #8d6e63 100%) !important; border-top-color: #bcaaa4 !important; }
    .csc-tab:nth-child(7).active { background: linear-gradient(135deg, #4e342e 0%, #6d4c41 100%) !important; border-top-color: #bcaaa4 !important; }
    .csc-tab:nth-child(7):hover:not(.active) { border-top-color: #bcaaa4 !important; }
    @media (max-width: 782px) { .csc-help-btn span { display:none !important; } .csc-help-btn::after { content:"Help"; } .csc-help-btn { padding:6px 12px !important; font-size:12px !important; } .csc-header-version { display:none !important; } }
    div[style*="#fff3e0"] .csc-health-metric,
    div[style*="#e3f2fd"] .csc-health-metric,
    div[style*="#f3e5f5"] .csc-health-metric { background: transparent !important; border-color: transparent !important; }
    div[style*="#fff3e0"] .csc-health-metric-label { color: #e65100 !important; }
    div[style*="#fff3e0"] .csc-health-metric-value { color: #e65100 !important; }
    div[style*="#efebe9"] .csc-health-metric { background: transparent !important; border-color: transparent !important; }
    div[style*="#efebe9"] .csc-health-metric-label { color: #4e342e !important; }
    div[style*="#efebe9"] .csc-health-metric-value:not(#hm-weeks-left) { color: #4e342e !important; }
    div[style*="#f3e5f5"] .csc-health-metric-label { color: #7b1fa2 !important; }
    div[style*="#f3e5f5"] .csc-health-metric-value { color: #7b1fa2 !important; }
    .csc-health-metric { border: none !important; }';
    wp_add_inline_style( 'cloudscale-cleanup-css', $cscc_inline_css );
}

// ─── Admin dashboard widget ───────────────────────────────────────────────────

add_action( 'wp_dashboard_setup', 'cscc_register_dashboard_widget' );
function cscc_register_dashboard_widget() {
    wp_add_dashboard_widget(
        'cscc_dashboard_widget',
        '🥷 CloudScale Cleanup',
        'cscc_render_dashboard_widget'
    );
}

function cscc_render_dashboard_widget() {
    $last_db  = get_option( 'cscc_last_db_cleanup', null );
    $last_img = get_option( 'cscc_last_img_cleanup', null );
    $last_opt = get_option( 'cscc_last_img_optimise', null );

    // Health data
    $weekly = get_option( CSCC_HEALTH_WEEKLY_KEY, array() );
    $health = ( count( $weekly ) >= 2 && function_exists( 'cscc_health_calculate' ) ) ? cscc_health_calculate() : null;
    $rag    = $health ? $health['disk_rag'] : 'grey';

    // RAG palette: accent color, light bg tint, dark text
    $rag_cfg = array(
        'green' => array( 'label' => 'Healthy',    'color' => '#16a34a', 'bg' => '#dcfce7', 'text' => '#166534' ),
        'amber' => array( 'label' => 'Warning',    'color' => '#d97706', 'bg' => '#fef3c7', 'text' => '#92400e' ),
        'red'   => array( 'label' => 'Critical',   'color' => '#dc2626', 'bg' => '#fee2e2', 'text' => '#991b1b' ),
        'grey'  => array( 'label' => 'Collecting', 'color' => '#64748b', 'bg' => '#f1f5f9', 'text' => '#334155' ),
    );
    $rc = isset( $rag_cfg[ $rag ] ) ? $rag_cfg[ $rag ] : $rag_cfg['grey'];

    // URLs
    $db_url     = admin_url( 'tools.php?page=cloudscale-cleanup&tab=db-cleanup' );
    $img_url    = admin_url( 'tools.php?page=cloudscale-cleanup&tab=img-cleanup' );
    $opt_url    = admin_url( 'tools.php?page=cloudscale-cleanup&tab=img-optimise' );
    $health_url = admin_url( 'tools.php?page=cloudscale-cleanup&tab=site-health' );
    $png_url    = admin_url( 'tools.php?page=cloudscale-cleanup&tab=png-to-jpeg' );
    $main_url   = admin_url( 'tools.php?page=cloudscale-cleanup' );

    // Hover is a CSS class, not inline onmouseover/onmouseout attributes. Those had to be
    // echoed as raw HTML, which is an unescapable output by construction and one of the
    // things WordPress.org rejects; a class is escapable with esc_attr() and also keeps
    // behaviour out of markup. The rule itself is emitted once, below.
    $hov_class = 'cscc-hov';

    // Autoload RAG
    $al_bytes = $health ? cscc_get_autoload_size() : 0;
    $al_rag   = $health ? cscc_autoload_rag( $al_bytes ) : 'grey';
    $al_clr   = array( 'red' => '#dc2626', 'amber' => '#d97706', 'green' => '#16a34a', 'grey' => '#64748b' );
    $al_color = isset( $al_clr[ $al_rag ] ) ? $al_clr[ $al_rag ] : '#64748b';

    // SVG donut chart
    $r    = 30;
    $circ = 2 * M_PI * $r;
    if ( $health ) {
        $disk_total = max( 1, $health['disk_used'] + $health['disk_free'] );
        $used_frac  = $health['disk_used'] / $disk_total;
        $used_len   = round( $circ * $used_frac, 2 );
        $gap_len    = round( $circ - $used_len, 2 );
        $pct_text   = round( $used_frac * 100 ) . '%';
        $donut      = '<svg viewBox="0 0 80 80" width="76" height="76" style="display:block">'
            . '<circle cx="40" cy="40" r="' . $r . '" fill="none" stroke="#e2e8f0" stroke-width="9"/>'
            . '<circle cx="40" cy="40" r="' . $r . '" fill="none" stroke="' . esc_attr( $rc['color'] ) . '" stroke-width="9"'
            . ' stroke-dasharray="' . esc_attr( (string) $used_len ) . ' ' . esc_attr( (string) $gap_len ) . '"'
            . ' stroke-linecap="round" transform="rotate(-90,40,40)"/>'
            . '<text x="40" y="38" text-anchor="middle" dominant-baseline="middle"'
            . ' font-size="14" font-weight="800" font-family="-apple-system,sans-serif"'
            . ' fill="' . esc_attr( $rc['color'] ) . '">'
            . esc_html( $pct_text ) . '</text>'
            . '<text x="40" y="53" text-anchor="middle"'
            . ' font-size="7" font-weight="700" font-family="-apple-system,sans-serif"'
            . ' letter-spacing="1" fill="#94a3b8">USED</text>'
            . '</svg>';
    } else {
        $donut = '<svg viewBox="0 0 80 80" width="76" height="76" style="display:block">'
            . '<circle cx="40" cy="40" r="' . $r . '" fill="none" stroke="#e2e8f0" stroke-width="9"/>'
            . '<text x="40" y="40" text-anchor="middle" dominant-baseline="middle"'
            . ' font-size="11" font-weight="700" font-family="-apple-system,sans-serif"'
            . ' fill="#94a3b8">&middot;</text>'
            . '</svg>';
    }

    // Tags/attributes the donut below is allowed to emit. Declared explicitly rather than
    // trusting the string: it is assembled here today, but wp_kses() means a future edit that
    // interpolates something unexpected still cannot inject markup.
    $svg_allowed = array(
        'svg'    => array( 'viewbox' => true, 'width' => true, 'height' => true, 'style' => true ),
        'circle' => array(
            'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true,
            'stroke-width' => true, 'stroke-dasharray' => true, 'stroke-linecap' => true,
            'transform' => true,
        ),
        'text'   => array(
            'x' => true, 'y' => true, 'text-anchor' => true, 'dominant-baseline' => true,
            'font-size' => true, 'font-weight' => true, 'font-family' => true,
            'letter-spacing' => true, 'fill' => true,
        ),
    );

    // Time-ago helper
    // Returns RAW text; the caller escapes. See $metrics for why nothing is pre-escaped here.
    $ago = function( $val ) {
        return $val
            ? human_time_diff( strtotime( $val ), current_time( 'timestamp' ) ) . ' ago'
            : 'Not run';
    };

    // Disk runway value + colour
    $runway_txt = '·';
    $runway_col = '#0f172a';
    if ( $health && $health['weeks_remaining'] > 104 ) {
        $runway_txt = '≫ 2 Yrs';
        $runway_col = $rag === 'red' ? '#dc2626' : ( $rag === 'amber' ? '#d97706' : '#16a34a' );
    } elseif ( $health && $health['weeks_remaining'] > 0 ) {
        $runway_txt = round( $health['weeks_remaining'] ) . ' wks';
        $runway_col = $rag === 'red' ? '#dc2626' : ( $rag === 'amber' ? '#d97706' : '#0f172a' );
    }
    ?>
    <style>.cscc-hov{transition:opacity .15s}.cscc-hov:hover{opacity:.82}</style>
    <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#0f172a;margin:-12px -12px 0;padding:0">

        <!-- Dark header band (full-bleed) -->
        <div style="background:linear-gradient(135deg,#042f2e 0%,#134e4a 60%,#042f2e 100%);padding:14px 16px 12px;border-bottom:1px solid rgba(20,184,166,0.3)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:18px;line-height:1">🥷</span>
                    <span style="color:#ccfbf1;font-size:13px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase">CloudScale Cleanup</span>
                </div>
                <a href="<?php echo esc_url( $health_url ); ?>"
                   style="display:inline-flex;align-items:center;gap:5px;background:rgba(20,184,166,0.15);border:1px solid rgba(20,184,166,0.4);color:<?php echo esc_attr( $rc['color'] ); ?>;font-size:10px;font-weight:700;letter-spacing:0.06em;padding:2px 8px;border-radius:20px;text-decoration:none;transition:opacity 0.15s"
                   class="<?php echo esc_attr( $hov_class ); ?>">
                    <span style="width:6px;height:6px;border-radius:50%;background:<?php echo esc_attr( $rc['color'] ); ?>;flex-shrink:0;display:inline-block"></span>
                    <?php echo esc_html( $rc['label'] ); ?>
                </a>
            </div>
            <p style="margin:0;font-size:11px;color:#5eead4;line-height:1.4">DB &amp; media lean · revisions · transients · unused files</p>
        </div>

        <!-- Body -->
        <div style="padding:12px 16px 4px;background:#fff">

        <!-- Disk panel: donut chart + metric rows -->
        <div style="display:flex;align-items:center;gap:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;margin-bottom:11px">
            <div style="flex-shrink:0"><?php echo wp_kses( $donut, $svg_allowed ); ?></div>
            <div style="flex:1;min-width:0">
                <?php if ( $health ) :
                    // Values are RAW here and escaped at the echo below. They used to be
                    // escaped on the way in, which then required an unescaped echo to avoid
                    // double-escaping -- exactly the pattern WordPress.org rejects.
                    $metrics = array(
                        array( 'Disk Used',   size_format( $health['disk_used'], 1 ),  '#0f172a' ),
                        array( 'Disk Free',   size_format( $health['disk_free'], 1 ),  '#0f172a' ),
                        array( 'Growth / Wk', $health['growth_per_week'] > 0 ? size_format( $health['growth_per_week'], 1 ) : '·', '#0f172a' ),
                        array( 'Est. Runway', $runway_txt,                             $runway_col ),
                        array( 'Autoload',    size_format( $al_bytes, 1 ),             $al_color ),
                    );
                    $last_i = count( $metrics ) - 1;
                    foreach ( $metrics as $i => $m ) :
                        $sep = $i < $last_i ? 'border-bottom:1px solid #eef0f3;' : '';
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:2.5px 0;<?php echo esc_attr( $sep ); ?>">
                        <span style="font-size:9.5px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px"><?php echo esc_html( $m[0] ); ?></span>
                        <span style="font-size:12px;font-weight:700;color:<?php echo esc_attr( $m[2] ); ?>"><?php echo esc_html( $m[1] ); ?></span>
                    </div>
                    <?php endforeach;
                else : ?>
                    <p style="margin:0;font-size:10px;color:#94a3b8;padding:4px 0">Collecting, available after first weekly snapshot.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Activity tiles: last-run times -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-bottom:11px">
            <?php
            $tiles = array(
                array( $db_url,  '⚡', 'DB Cleanup',   $ago( $last_db ),  '#3b82f6' ),
                array( $img_url, '🖼', 'Media Scan',   $ago( $last_img ), '#8b5cf6' ),
                array( $opt_url, '✨', 'Img Optimise', $ago( $last_opt ), '#0d9488' ),
            );
            foreach ( $tiles as $t ) : ?>
            <a href="<?php echo esc_url( $t[0] ); ?>"
               style="display:block;text-decoration:none;background:#fff;border:1px solid #e2e8f0;border-top:3px solid <?php echo esc_attr( $t[4] ); ?>;border-radius:8px;padding:8px 5px 7px;text-align:center;transition:opacity 0.15s"
               class="<?php echo esc_attr( $hov_class ); ?>">
                <div style="font-size:15px;line-height:1;margin-bottom:4px"><?php echo esc_html( $t[1] ); ?></div>
                <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;color:<?php echo esc_attr( $t[4] ); ?>;margin-bottom:4px"><?php echo esc_html( $t[2] ); ?></div>
                <div style="font-size:10px;font-weight:600;color:#475569;line-height:1.2"><?php echo esc_html( $t[3] ); ?></div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- CTA buttons -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <a href="<?php echo esc_url( $png_url ); ?>"
               style="display:flex;align-items:center;justify-content:center;gap:5px;background:#fff;border:2px solid #d1d5db;color:#374151;font-weight:700;font-size:11.5px;padding:9px 6px;border-radius:8px;text-decoration:none;transition:opacity 0.15s"
               class="<?php echo esc_attr( $hov_class ); ?>">
                🖼 PNG → JPEG
            </a>
            <a href="<?php echo esc_url( $main_url ); ?>"
               style="display:flex;align-items:center;justify-content:center;gap:5px;background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 100%);color:#fff;font-weight:700;font-size:11.5px;padding:9px 6px;border-radius:8px;text-decoration:none;box-shadow:0 2px 8px rgba(37,99,235,0.3);transition:opacity 0.15s"
               class="<?php echo esc_attr( $hov_class ); ?>">
                ⚡ Open Plugin
            </a>
        </div>

        </div><!-- /body -->
    </div><!-- /widget -->
    <?php
}


// ─── Front-end sidebar widget ─────────────────────────────────────────────────
/*
 * Registers a widget visible in Appearance -> Widgets (classic widget screen)
 * and the block editor widget screen. Drag it into any sidebar or widget area
 * in your theme to show it on the front end of the site.
 */

add_action( 'widgets_init', function () {
    register_widget( 'CSCC_Front_Widget' );
} );

class CSCC_Front_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'cscc_front_widget',
            'CloudScale Cleanup',
            array(
                'description' => 'Shows last cleanup run times and links to the CloudScale Cleanup plugin at cloudscale.consulting.',
                'classname'   => 'widget-csc-cleanup',
            )
        );
    }

    /** Render the widget on the front end */
    public function widget( $args, $instance ) {
        $title    = ! empty( $instance['title'] ) ? $instance['title'] : 'Site Maintenance';
        $last_db  = get_option( 'cscc_last_db_cleanup',   null );
        $last_img = get_option( 'cscc_last_img_cleanup',  null );
        $last_opt = get_option( 'cscc_last_img_optimise', null );

        // Site health RAG
        $health_rag = 'grey';
        $health_label = 'Collecting';
        $weekly = get_option( CSCC_HEALTH_WEEKLY_KEY, array() );
        if ( count( $weekly ) >= 2 && function_exists( 'cscc_health_calculate' ) ) {
            $h = cscc_health_calculate();
            $health_rag = $h['disk_rag'];
            if ( $health_rag === 'green' ) { $health_label = 'Healthy'; }
            elseif ( $health_rag === 'amber' ) { $health_label = 'Warning'; }
            elseif ( $health_rag === 'red' ) { $health_label = 'Critical'; }
        }

        // Theme-supplied wrappers, so the HTML in them must survive: wp_kses_post() escapes
        // these without flattening the markup the theme relies on.
        echo wp_kses_post( $args['before_widget'] );
        echo wp_kses_post( $args['before_title'] ) . esc_html( $title ) . wp_kses_post( $args['after_title'] );
        ?>
        <div class="csc-front-widget">
            <ul class="csc-fw-list">
                <li>
                    <span class="csc-fw-label">DB Cleanup</span>
                    <span class="csc-fw-value"><?php echo $last_db  ? esc_html( human_time_diff( strtotime( $last_db  ), current_time( 'timestamp' ) ) . ' ago' ) : 'Never run'; ?></span>
                </li>
                <li>
                    <span class="csc-fw-label">Unused Media</span>
                    <span class="csc-fw-value"><?php echo $last_img ? esc_html( human_time_diff( strtotime( $last_img ), current_time( 'timestamp' ) ) . ' ago' ) : 'Never run'; ?></span>
                </li>
                <li>
                    <span class="csc-fw-label">Img Optimise</span>
                    <span class="csc-fw-value"><?php echo $last_opt ? esc_html( human_time_diff( strtotime( $last_opt ), current_time( 'timestamp' ) ) . ' ago' ) : 'Never run'; ?></span>
                </li>
                <li>
                    <span class="csc-fw-label">Site Health</span>
                    <?php
                    $rag_colors = array( 'green' => '#2e7d32', 'amber' => '#e65100', 'red' => '#c62828', 'grey' => '#78909c' );
                    $rag_color  = isset( $rag_colors[ $health_rag ] ) ? $rag_colors[ $health_rag ] : '#78909c';
                    ?>
                    <span class="csc-fw-value" style="color:<?php echo esc_attr( $rag_color ); ?>">&#9679; <?php echo esc_html( $health_label ); ?></span>
                </li>
            </ul>
            <div class="csc-fw-links">
                <a href="https://cloudscale.consulting" target="_blank" rel="noopener" class="csc-fw-link">cloudscale.consulting</a>
                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=cloudscale-cleanup' ) ); ?>" class="csc-fw-link csc-fw-link-admin">Run Cleanup</a>
                <?php endif; ?>
            </div>
            <?php if ( ! empty( $instance['show_credit'] ) ) : ?>
            <p class="csc-fw-credit">Powered by <a href="https://cloudscale.consulting" target="_blank" rel="noopener">CloudScale Cleanup</a></p>
            <?php endif; ?>
        </div>
        <?php
        echo wp_kses_post( $args['after_widget'] );
    }

    /** Settings form in Appearance -> Widgets */
    public function form( $instance ) {
        $title       = ! empty( $instance['title'] ) ? $instance['title'] : 'Site Maintenance';
        $show_credit = ! empty( $instance['show_credit'] );
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">Title:</label>
            <input class="widefat"
                   id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                   type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <input type="checkbox"
                   id="<?php echo esc_attr( $this->get_field_id( 'show_credit' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'show_credit' ) ); ?>"
                   value="1"<?php checked( $show_credit ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id( 'show_credit' ) ); ?>"><?php esc_html_e( 'Show credit link', 'cloudscale-cleanup' ); ?></label>
        </p>
        <?php
    }

    /** Save widget settings */
    public function update( $new_instance, $old_instance ) {
        $instance               = $old_instance;
        $instance['title']       = sanitize_text_field( $new_instance['title'] );
        $instance['show_credit'] = ! empty( $new_instance['show_credit'] ) ? 1 : 0;
        return $instance;
    }
}

// Inline CSS for the front-end widget, only loaded when widget is active
add_action( 'wp_enqueue_scripts', 'cscc_enqueue_front_widget_styles' );
function cscc_enqueue_front_widget_styles() {
    if ( ! is_active_widget( false, false, 'cscc_front_widget', true ) ) {
        return;
    }
    wp_add_inline_style( 'wp-block-library', '
.csc-front-widget{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13.5px}
.csc-fw-list{margin:0 0 12px;padding:0;list-style:none}
.csc-fw-list li{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.07)}
.csc-fw-list li:last-child{border-bottom:none}
.csc-fw-label{color:#555;font-size:12.5px}
.csc-fw-value{font-weight:600;color:#1a1f2e;font-size:12.5px}
.csc-fw-links{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.csc-fw-link{display:inline-block;font-size:12px;font-weight:600;padding:6px 12px;border-radius:5px;text-decoration:none;background:#1a1f2e;color:#fff!important;transition:background .15s}
.csc-fw-link:hover{background:#4a9eff;color:#fff!important}
.csc-fw-link-admin{background:#27ae60}
.csc-fw-link-admin:hover{background:#219150}
.csc-fw-credit{font-size:11px;color:#999;margin:0}
.csc-fw-credit a{color:#4a9eff;text-decoration:none}
    ' );
}

// ─── Settings save ────────────────────────────────────────────────────────────

add_action( 'wp_ajax_cscc_save_settings', 'cscc_ajax_save_settings' );
function cscc_ajax_save_settings() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $scalars = array(
        'cscc_post_revisions_age', 'cscc_drafts_age', 'cscc_trash_age',
        'cscc_autodraft_age', 'cscc_spam_comments_age', 'cscc_trash_comments_age',
        'cscc_img_max_width', 'cscc_img_max_height', 'cscc_img_quality',
        'cscc_img_min_size_kb', 'cscc_img_min_gain_pct',
        'cscc_schedule_db_hour', 'cscc_schedule_img_hour',
        'cscc_clean_revisions', 'cscc_clean_drafts', 'cscc_clean_trashed', 'cscc_clean_autodrafts',
        'cscc_clean_transients', 'cscc_clean_orphan_post', 'cscc_clean_orphan_user',
        'cscc_clean_spam_comments', 'cscc_clean_trash_comments',
    );
    $bools = array(
        'cscc_schedule_db_enabled', 'cscc_schedule_img_enabled', 'cscc_convert_png_to_jpg',
    );
    $arrays = array( 'cscc_schedule_db_days', 'cscc_schedule_img_days' );

    foreach ( $scalars as $f ) {
        if ( isset( $_POST[ $f ] ) ) {
            $val = sanitize_text_field( wp_unslash( $_POST[ $f ] ) );
            // Toggle fields: only accept '0' or '1'
            if ( in_array( $f, array(
                'cscc_clean_revisions', 'cscc_clean_drafts', 'cscc_clean_trashed', 'cscc_clean_autodrafts',
                'cscc_clean_transients', 'cscc_clean_orphan_post', 'cscc_clean_orphan_user',
                'cscc_clean_spam_comments', 'cscc_clean_trash_comments',
            ), true ) ) {
                $val = $val === '1' ? '1' : '0';
            }
            update_option( $f, $val );
        }
    }
    foreach ( $bools as $f ) {
        update_option( $f, isset( $_POST[ $f ] ) ? '1' : '0' );
    }
    foreach ( $arrays as $f ) {
        update_option( $f, isset( $_POST[ $f ] ) && is_array( $_POST[ $f ] )
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised via array_map/sanitize_text_field after wp_unslash
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST[ $f ] ) )
            : array()
        );
    }

    cscc_schedule_crons();
    wp_send_json_success( 'Settings saved.' );
}

// ─── Cron scheduling ─────────────────────────────────────────────────────────

function cscc_schedule_crons() {
    wp_clear_scheduled_hook( 'cscc_scheduled_db_cleanup' );
    if ( get_option( 'cscc_schedule_db_enabled', '0' ) === '1' ) {
        $ts = cscc_next_run_timestamp(
            (array) get_option( 'cscc_schedule_db_days', array() ),
            intval( get_option( 'cscc_schedule_db_hour', 3 ) )
        );
        if ( $ts ) { wp_schedule_single_event( $ts, 'cscc_scheduled_db_cleanup' ); }
    }

    wp_clear_scheduled_hook( 'cscc_scheduled_img_cleanup' );
    if ( get_option( 'cscc_schedule_img_enabled', '0' ) === '1' ) {
        $ts = cscc_next_run_timestamp(
            (array) get_option( 'cscc_schedule_img_days', array() ),
            intval( get_option( 'cscc_schedule_img_hour', 4 ) )
        );
        if ( $ts ) { wp_schedule_single_event( $ts, 'cscc_scheduled_img_cleanup' ); }
    }
}

function cscc_next_run_timestamp( $days, $hour ) {
    $map = array(
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
        'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    );
    $now  = current_time( 'timestamp' );
    $best = null;
    foreach ( $days as $d ) {
        $d = strtolower( trim( $d ) );
        if ( ! isset( $map[ $d ] ) ) { continue; }
        $candidate = strtotime( 'next ' . $map[ $d ], $now );
        $candidate = mktime( $hour, 0, 0, gmdate( 'n', $candidate ), gmdate( 'j', $candidate ), gmdate( 'Y', $candidate ) );
        if ( $candidate <= $now ) { $candidate += WEEK_IN_SECONDS; }
        if ( $best === null || $candidate < $best ) { $best = $candidate; }
    }
    return $best;
}

// Cron handlers, run synchronously (no HTTP chunking needed in a cron context)
add_action( 'cscc_scheduled_db_cleanup', 'cscc_cron_db_cleanup' );
function cscc_cron_db_cleanup() {
    try {
        $ids = cscc_build_db_id_list();
        foreach ( $ids['revisions']      as $id ) { wp_delete_post( intval( $id ), true ); }
        foreach ( $ids['drafts']         as $id ) { wp_delete_post( intval( $id ), true ); }
        foreach ( $ids['trashed']        as $id ) { wp_delete_post( intval( $id ), true ); }
        foreach ( $ids['autodrafts']     as $id ) { wp_delete_post( intval( $id ), true ); }
        cscc_delete_expired_transients();
        cscc_delete_orphaned_postmeta();
        cscc_delete_orphaned_usermeta();
        foreach ( $ids['spam_comments']  as $id ) { wp_delete_comment( intval( $id ), true ); }
        foreach ( $ids['trash_comments'] as $id ) { wp_delete_comment( intval( $id ), true ); }
        update_option( 'cscc_last_db_cleanup', current_time( 'mysql' ) );
        update_option( 'cscc_last_scheduled_db_cleanup', current_time( 'mysql' ) );
        cscc_schedule_crons();
    } catch ( \Throwable $e ) {
        error_log( sprintf( '[CSC] cron "cscc_scheduled_db_cleanup" exception (%s): %s in %s line %d', get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational cron logging
        CloudScale_Telegram::send(
            "Scheduled database cleanup cron failed.\n\nError: " . $e->getMessage(),
            'CloudScale Cleanup',
            'error'
        );
    }
}

add_action( 'cscc_scheduled_img_cleanup', 'cscc_cron_img_cleanup' );
function cscc_cron_img_cleanup() {
    try {
        $used = cscc_get_used_attachment_ids();
        $all  = get_posts( array(
            'post_type' => 'attachment', 'post_status' => 'inherit',
            'posts_per_page' => -1, 'fields' => 'ids',
        ) );

        // Load existing media recycle manifest
        if ( ! cscc_media_recycle_ensure_dir() ) {
            return;
        }
        $manifest = cscc_media_recycle_read_manifest();

        $recycled = 0;
        foreach ( $all as $id ) {
            if ( isset( $used[ $id ] ) ) { continue; }
            try {
                $result = cscc_media_recycle_save_attachment( intval( $id ) );
                if ( ! empty( $result['error'] ) ) {
                    error_log( '[CSC] Cron recycle error for ID ' . $id . ': ' . $result['error'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational cron logging
                    continue;
                }
                $manifest[ (string) $id ] = array(
                    'post'        => $result['post'],
                    'meta'        => $result['meta'],
                    'files_moved' => $result['files_moved'],
                    'recycled_at' => current_time( 'mysql' ),
                );
                wp_delete_attachment( $id, true );
                $recycled++;
            } catch ( \Throwable $e ) {
                error_log( '[CSC] Cron recycle fatal for ID ' . $id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational cron logging
            }
        }

        if ( ! cscc_media_recycle_write_manifest( $manifest ) ) {
            error_log( '[CSC] Cron: Failed to write media recycle manifest.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational cron logging
        }

        error_log( '[CSC] Cron: Recycled ' . $recycled . ' unused attachment(s). Total in recycle bin: ' . count( $manifest ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational cron logging
        update_option( 'cscc_last_img_cleanup', current_time( 'mysql' ) );
        update_option( 'cscc_last_scheduled_img_cleanup', current_time( 'mysql' ) );
        cscc_schedule_crons();
    } catch ( \Throwable $e ) {
        error_log( sprintf( '[CSC] cron "cscc_scheduled_img_cleanup" exception (%s): %s in %s line %d', get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational cron logging
        CloudScale_Telegram::send(
            "Scheduled image cleanup cron failed.\n\nError: " . $e->getMessage(),
            'CloudScale Cleanup',
            'error'
        );
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// DATABASE CLEANUP
// ═════════════════════════════════════════════════════════════════════════════

function cscc_build_db_id_list( $overrides = array() ) {
    global $wpdb;
    $ra  = intval( get_option( 'cscc_post_revisions_age', 30 ) );
    $da  = intval( get_option( 'cscc_drafts_age', 90 ) );
    $ta  = intval( get_option( 'cscc_trash_age', 30 ) );
    $aa  = intval( get_option( 'cscc_autodraft_age', 7 ) );
    $sa  = intval( get_option( 'cscc_spam_comments_age', 30 ) );
    $tca = intval( get_option( 'cscc_trash_comments_age', 30 ) );

    $tog = function( $opt ) use ( $overrides ) {
        if ( ! empty( $overrides ) ) {
            // Full UI submission passed, absent key means toggled off
            return isset( $overrides[ $opt ] ) && $overrides[ $opt ] === '1';
        }
        return get_option( $opt, '1' ) === '1';
    };

    return array(
        'revisions'      => $tog( 'cscc_clean_revisions' )      ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='revision' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $ra ) ) : array(),
        'drafts'         => $tog( 'cscc_clean_drafts' )         ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status='draft' AND post_type='post' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $da ) ) : array(),
        'trashed'        => $tog( 'cscc_clean_trashed' )        ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status='trash' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $ta ) ) : array(),
        'autodrafts'     => $tog( 'cscc_clean_autodrafts' )     ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status='auto-draft' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $aa ) ) : array(),
        'spam_comments'  => $tog( 'cscc_clean_spam_comments' )  ? $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved='spam' AND comment_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $sa ) ) : array(),
        'trash_comments' => $tog( 'cscc_clean_trash_comments' ) ? $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved='trash' AND comment_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $tca ) ) : array(),
    );
}

function cscc_delete_expired_transients() {
    global $wpdb;
    $keys = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );
    foreach ( $keys as $k ) { delete_transient( str_replace( '_transient_timeout_', '', $k ) ); }
    return count( $keys );
}

function cscc_delete_orphaned_postmeta() {
    global $wpdb;
    return (int) $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input; table names are trusted $wpdb properties.
}

function cscc_delete_orphaned_usermeta() {
    global $wpdb;
    return (int) $wpdb->query( "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input; table names are trusted $wpdb properties.
}

// Dry run
add_action( 'wp_ajax_cscc_scan_db', 'cscc_ajax_scan_db' );
function cscc_ajax_scan_db() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    // Read toggle state from POST if provided (live UI state), otherwise fall back to DB.
    // If ANY toggle key is present in POST, we treat this as a full UI submission, // missing keys default to '0' rather than falling back to DB, preventing stale DB
    // values from overriding the user's current screen state.
    $has_post_toggles = isset( $_POST['cscc_clean_revisions'] )
        || isset( $_POST['cscc_clean_drafts'] )
        || isset( $_POST['cscc_clean_transients'] );

    $toggle = function( $opt ) use ( $has_post_toggles ) {
        if ( $has_post_toggles ) {
            // Full UI submission, use POST value, absent = '0' (toggled off)
            return isset( $_POST[ $opt ] ) && wp_unslash( $_POST[ $opt ] ) === '1'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- boolean toggle, validated via strict comparison; nonce verified in outer function
        }
        // No UI data sent (e.g. scheduled run), use DB
        return get_option( $opt, '1' ) === '1';
    };

    global $wpdb;
    $ra  = intval( get_option( 'cscc_post_revisions_age', 30 ) );
    $da  = intval( get_option( 'cscc_drafts_age', 90 ) );
    $ta  = intval( get_option( 'cscc_trash_age', 30 ) );
    $aa  = intval( get_option( 'cscc_autodraft_age', 7 ) );
    $sa  = intval( get_option( 'cscc_spam_comments_age', 30 ) );
    $tca = intval( get_option( 'cscc_trash_comments_age', 30 ) );

    $toggle_keys = array(
        'cscc_clean_revisions', 'cscc_clean_drafts', 'cscc_clean_trashed', 'cscc_clean_autodrafts',
        'cscc_clean_transients', 'cscc_clean_orphan_post', 'cscc_clean_orphan_user',
        'cscc_clean_spam_comments', 'cscc_clean_trash_comments',
    );
    $lines = array();

    if ( $toggle( 'cscc_clean_revisions' ) ) {
        $revisions = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type='revision' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY post_date DESC LIMIT 1000", $ra ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Post Revisions (older than ' . $ra . ' days)' );
        foreach ( $revisions as $r ) { $lines[] = array( 'type' => 'item', 'text' => '  [REVISION] ID ' . $r->ID . ', ' . esc_html( $r->post_title ) . ' (' . $r->post_date . ')' ); }
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . count( $revisions ) );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Post Revisions, SKIPPED (disabled)' );
    }

    if ( $toggle( 'cscc_clean_drafts' ) ) {
        $drafts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_status='draft' AND post_type='post' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY post_date DESC LIMIT 500", $da ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Draft Posts (older than ' . $da . ' days)' );
        foreach ( $drafts as $d ) { $lines[] = array( 'type' => 'item', 'text' => '  [DRAFT] ID ' . $d->ID . ', ' . esc_html( $d->post_title ) . ' (' . $d->post_date . ')' ); }
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . count( $drafts ) );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Draft Posts, SKIPPED (disabled)' );
    }

    if ( $toggle( 'cscc_clean_trashed' ) ) {
        $trashed = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_modified FROM {$wpdb->posts} WHERE post_status='trash' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY post_modified DESC LIMIT 500", $ta ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Trashed Posts (older than ' . $ta . ' days)' );
        foreach ( $trashed as $t ) { $lines[] = array( 'type' => 'item', 'text' => '  [TRASH] ID ' . $t->ID . ', ' . esc_html( $t->post_title ) . ' (' . $t->post_modified . ')' ); }
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . count( $trashed ) );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Trashed Posts, SKIPPED (disabled)' );
    }

    if ( $toggle( 'cscc_clean_autodrafts' ) ) {
        $cnt_auto = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='auto-draft' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $aa ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Auto-Drafts (older than ' . $aa . ' days)' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_auto );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Auto-Drafts, SKIPPED (disabled)' );
    }

    if ( $toggle( 'cscc_clean_transients' ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $cnt_t = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input, table name is trusted $wpdb property
        $lines[] = array( 'type' => 'section', 'text' => 'Expired Transients' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_t );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Expired Transients, SKIPPED (disabled)' );
    }

    if ( $toggle( 'cscc_clean_orphan_post' ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $cnt_pm = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" ); // no user input, table names are trusted $wpdb properties
        $lines[] = array( 'type' => 'section', 'text' => 'Orphaned Post Meta' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_pm . ' rows' );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Orphaned Post Meta, SKIPPED (disabled)' );
    }

    if ( $toggle( 'cscc_clean_orphan_user' ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $cnt_um = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL" ); // no user input, table names are trusted $wpdb properties
        $lines[] = array( 'type' => 'section', 'text' => 'Orphaned User Meta' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_um . ' rows' );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Orphaned User Meta, SKIPPED (disabled)' );
    }

    if ( $toggle( 'cscc_clean_spam_comments' ) ) {
        $cnt_spam = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved='spam' AND comment_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $sa ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Spam Comments (older than ' . $sa . ' days)' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_spam );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Spam Comments, SKIPPED (disabled)' );
    }

    if ( $toggle( 'cscc_clean_trash_comments' ) ) {
        $cnt_tc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved='trash' AND comment_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $tca ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Trashed Comments (older than ' . $tca . ' days)' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_tc );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Trashed Comments, SKIPPED (disabled)' );
    }

    wp_send_json_success( $lines );
}

// Chunked run, Step 1: build queue
add_action( 'wp_ajax_cscc_db_start', 'cscc_ajax_db_start' );
function cscc_ajax_db_start() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    // Collect any toggle overrides sent from the live UI
    $toggle_keys = array(
        'cscc_clean_revisions', 'cscc_clean_drafts', 'cscc_clean_trashed', 'cscc_clean_autodrafts',
        'cscc_clean_transients', 'cscc_clean_orphan_post', 'cscc_clean_orphan_user',
        'cscc_clean_spam_comments', 'cscc_clean_trash_comments',
    );
    $overrides = array();
    foreach ( $toggle_keys as $k ) {
        if ( isset( $_POST[ $k ] ) ) {
            $overrides[ $k ] = wp_unslash( $_POST[ $k ] ) === '1' ? '1' : '0'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- boolean toggle, validated via strict comparison
        }
    }

    $has_post_toggles = isset( $_POST['cscc_clean_revisions'] )
        || isset( $_POST['cscc_clean_drafts'] )
        || isset( $_POST['cscc_clean_transients'] );

    $tog = function( $opt ) use ( $overrides, $has_post_toggles ) {
        if ( $has_post_toggles ) {
            return isset( $overrides[ $opt ] ) && $overrides[ $opt ] === '1';
        }
        return get_option( $opt, '1' ) === '1';
    };

    $ids   = cscc_build_db_id_list( $overrides );
    $queue = array();
    foreach ( $ids['revisions']      as $id ) { $queue[] = array( 'type' => 'post',          'id' => intval( $id ), 'label' => 'revision' ); }
    foreach ( $ids['drafts']         as $id ) { $queue[] = array( 'type' => 'post',          'id' => intval( $id ), 'label' => 'draft' ); }
    foreach ( $ids['trashed']        as $id ) { $queue[] = array( 'type' => 'post',          'id' => intval( $id ), 'label' => 'trashed post' ); }
    foreach ( $ids['autodrafts']     as $id ) { $queue[] = array( 'type' => 'post',          'id' => intval( $id ), 'label' => 'auto-draft' ); }
    foreach ( $ids['spam_comments']  as $id ) { $queue[] = array( 'type' => 'comment',       'id' => intval( $id ), 'label' => 'spam comment' ); }
    foreach ( $ids['trash_comments'] as $id ) { $queue[] = array( 'type' => 'comment',       'id' => intval( $id ), 'label' => 'trashed comment' ); }
    if ( $tog( 'cscc_clean_transients' ) )  { $queue[] = array( 'type' => 'transients',  'id' => 0, 'label' => 'expired transients' ); }
    if ( $tog( 'cscc_clean_orphan_post' ) ) { $queue[] = array( 'type' => 'orphan_post', 'id' => 0, 'label' => 'orphaned postmeta' ); }
    if ( $tog( 'cscc_clean_orphan_user' ) ) { $queue[] = array( 'type' => 'orphan_user', 'id' => 0, 'label' => 'orphaned usermeta' ); }

    set_transient( 'cscc_db_queue', $queue, HOUR_IN_SECONDS );

    wp_send_json_success( array(
        'total'     => count( $queue ),
        'remaining' => count( $queue ),
        'lines'     => array( array( 'type' => 'info', 'text' => '  Work queue built: ' . count( $queue ) . ' items.' ) ),
    ) );
}

// Step 2: process a chunk
add_action( 'wp_ajax_cscc_db_chunk', 'cscc_ajax_db_chunk' );
function cscc_ajax_db_chunk() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $queue = get_transient( 'cscc_db_queue' );
    if ( ! is_array( $queue ) ) { wp_send_json_error( 'Session expired, please start again.' ); }

    $chunk = array_splice( $queue, 0, CSCC_CHUNK_DB );
    set_transient( 'cscc_db_queue', $queue, HOUR_IN_SECONDS );

    $lines = array();
    foreach ( $chunk as $item ) {
        switch ( $item['type'] ) {
            case 'post':
                wp_delete_post( $item['id'], true );
                $lines[] = array( 'type' => 'deleted', 'text' => '  Deleted ' . $item['label'] . ' ID ' . $item['id'] );
                break;
            case 'comment':
                wp_delete_comment( $item['id'], true );
                $lines[] = array( 'type' => 'deleted', 'text' => '  Deleted ' . $item['label'] . ' ID ' . $item['id'] );
                break;
            case 'transients':
                $n = cscc_delete_expired_transients();
                $lines[] = array( 'type' => 'count', 'text' => '  Deleted ' . $n . ' expired transients.' );
                break;
            case 'orphan_post':
                $n = cscc_delete_orphaned_postmeta();
                $lines[] = array( 'type' => 'count', 'text' => '  Deleted ' . $n . ' orphaned postmeta rows.' );
                break;
            case 'orphan_user':
                $n = cscc_delete_orphaned_usermeta();
                $lines[] = array( 'type' => 'count', 'text' => '  Deleted ' . $n . ' orphaned usermeta rows.' );
                break;
        }
    }

    wp_send_json_success( array( 'remaining' => count( $queue ), 'lines' => $lines ) );
}

// Step 3: finish
add_action( 'wp_ajax_cscc_db_finish', 'cscc_ajax_db_finish' );
function cscc_ajax_db_finish() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    delete_transient( 'cscc_db_queue' );
    update_option( 'cscc_last_db_cleanup', current_time( 'mysql' ) );
    wp_send_json_success( array( 'lines' => array( array( 'type' => 'success', 'text' => 'Database cleanup complete.' ) ) ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// AUTOLOADED OPTIONS CLEANUP
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_cscc_autoload_scan', 'cscc_ajax_autoload_scan' );
/**
 * AJAX: Scan autoloaded options, returns size, count, top rows, and transient stats.
 *
 * @since 2.4.0
 * @return void Sends JSON response via wp_send_json_success/error.
 */
function cscc_ajax_autoload_scan() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $total_size  = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload NOT IN ('no','off')" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $total_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload NOT IN ('no','off')" );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload NOT IN ('no','off') ORDER BY size DESC LIMIT 20" );

    $expired_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
        '_transient_timeout_%', time()
    ) );

    $transient_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload NOT IN ('no','off')",
        '\_transient\_%'
    ) );
    $transient_size  = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload NOT IN ('no','off')",
        '\_transient\_%'
    ) );

    $rag       = cscc_autoload_rag( $total_size );
    $rag_label = array( 'green' => '✅ Healthy', 'amber' => '⚠️  Warning', 'red' => '🔴 Critical' );

    $lines   = array();
    $lines[] = array( 'type' => 'section', 'text' => 'Autoloaded wp_options Summary' );
    $lines[] = array( 'type' => 'info',    'text' => '  Total autoload size : ' . size_format( $total_size ) . '  (' . $total_count . ' rows), ' . $rag_label[ $rag ] );
    $lines[] = array( 'type' => 'info',    'text' => '  Expired transients  : ' . $expired_count . ' (will be deleted)' );
    $lines[] = array( 'type' => 'info',    'text' => '  Autoloaded transient rows : ' . $transient_count . ' (' . size_format( $transient_size ) . '), autoload will be disabled' );
    $lines[] = array( 'type' => 'section', 'text' => 'Top 20 Autoloaded Rows by Size' );

    foreach ( $rows as $row ) {
        $flag = '';
        if ( strpos( $row->option_name, '_transient_' ) === 0 )      { $flag = ' [transient]'; }
        elseif ( strpos( $row->option_name, '_site_transient_' ) === 0 ) { $flag = ' [site transient]'; }
        $lines[] = array( 'type' => 'item', 'text' => sprintf( '  %-52s  %s%s', $row->option_name, size_format( (int) $row->size ), $flag ) );
    }

    $lines[] = array( 'type' => 'section', 'text' => 'What Cleanup Will Do' );
    $lines[] = array( 'type' => 'info', 'text' => '  1. Delete all expired transients from the options table.' );
    $lines[] = array( 'type' => 'info', 'text' => '  2. Set autoload=no on all transient rows (cached data, not needed at startup).' );
    $lines[] = array( 'type' => 'info', 'text' => '  Note: no options are deleted except expired transients. All data remains usable.' );

    wp_send_json_success( $lines );
}

add_action( 'wp_ajax_cscc_autoload_start', 'cscc_ajax_autoload_start' );
/**
 * AJAX: Build the autoload cleanup queue and store it as a transient.
 *
 * @since 2.4.0
 * @return void Sends JSON response with total item count.
 */
function cscc_ajax_autoload_start() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $queue = array(
        array( 'type' => 'delete_expired_transients',  'id' => 0, 'label' => 'delete expired transients' ),
        array( 'type' => 'disable_transient_autoload', 'id' => 0, 'label' => 'disable autoload on transient rows' ),
    );
    set_transient( 'cscc_autoload_queue', $queue, HOUR_IN_SECONDS );

    wp_send_json_success( array(
        'total'     => count( $queue ),
        'remaining' => count( $queue ),
        'lines'     => array( array( 'type' => 'info', 'text' => '  Work queue: ' . count( $queue ) . ' tasks.' ) ),
    ) );
}

add_action( 'wp_ajax_cscc_autoload_chunk', 'cscc_ajax_autoload_chunk' );
/**
 * AJAX: Process one chunk of the autoload cleanup queue.
 *
 * Disables autoload on expired transient rows, then deletes them in batches.
 *
 * @since 2.4.0
 * @return void Sends JSON response with remaining count and log lines.
 */
function cscc_ajax_autoload_chunk() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $queue = get_transient( 'cscc_autoload_queue' );
    if ( ! is_array( $queue ) ) { wp_send_json_error( 'Session expired, please start again.' ); }

    global $wpdb;
    $chunk = array_splice( $queue, 0, 1 );
    set_transient( 'cscc_autoload_queue', $queue, HOUR_IN_SECONDS );

    $lines = array();
    foreach ( $chunk as $item ) {
        switch ( $item['type'] ) {
            case 'delete_expired_transients':
                $n       = cscc_delete_expired_transients();
                $lines[] = array( 'type' => 'count', 'text' => '  Deleted ' . $n . ' expired transients.' );
                break;
            case 'disable_transient_autoload':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $n       = (int) $wpdb->query( "UPDATE {$wpdb->options} SET autoload='no' WHERE (option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%') AND autoload NOT IN ('no','off')" );
                $lines[] = array( 'type' => 'count', 'text' => '  Disabled autoload on ' . $n . ' transient rows.' );
                break;
        }
    }

    wp_send_json_success( array( 'remaining' => count( $queue ), 'lines' => $lines ) );
}

add_action( 'wp_ajax_cscc_autoload_finish', 'cscc_ajax_autoload_finish' );
/**
 * AJAX: Finalise the autoload cleanup run, returns new size and RAG status.
 *
 * @since 2.4.0
 * @return void Sends JSON response with new_size, new_rag, and summary line.
 */
function cscc_ajax_autoload_finish() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
    delete_transient( 'cscc_autoload_queue' );
    $new_size = cscc_get_autoload_size();
    wp_send_json_success( array(
        'lines'       => array( array( 'type' => 'success', 'text' => 'Autoload cleanup complete. New total: ' . size_format( $new_size ) . '.' ) ),
        'new_size'    => $new_size,
        'new_size_fmt' => size_format( $new_size ),
        'new_rag'     => cscc_autoload_rag( $new_size ),
    ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// ORPHANED PLUGIN OPTIONS
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Return option name prefixes that belong to WordPress core and should never be flagged as orphaned.
 *
 * @since 2.4.20
 * @return string[] List of prefix strings.
 */
function cscc_orphan_core_prefixes(): array {
    return array(
        '_transient_', '_site_transient_', 'widget_', 'wp_', '_wp_', 'theme_mods_',
    );
}

/**
 * Return exact option names that belong to WordPress core.
 *
 * @since 2.4.20
 * @return string[] List of option names.
 */
function cscc_orphan_core_names(): array {
    return array(
        'siteurl', 'blogname', 'blogdescription', 'blogpublic', 'admin_email',
        'wp_user_roles', 'rewrite_rules', 'cron', 'active_plugins',
        'active_sitewide_plugins', 'sidebars_widgets', 'db_version',
        'initial_db_version', 'template', 'stylesheet', 'current_theme',
        'theme_switched', 'upload_path', 'upload_url_path',
        'uploads_use_yearmonth_folders', 'permalink_structure',
        'category_base', 'tag_base', 'date_format', 'time_format',
        'start_of_week', 'timezone_string', 'gmt_offset',
        'users_can_register', 'default_role', 'blog_charset', 'blog_public',
        'use_smilies', 'show_avatars', 'avatar_rating', 'avatar_default',
        'posts_per_page', 'posts_per_rss', 'rss_use_excerpt',
        'default_category', 'default_comment_status', 'default_ping_status',
        'comment_moderation', 'require_name_email', 'comment_max_links',
        'disallowed_keys', 'blacklist_keys', 'moderation_keys',
        'html_type', 'home', 'page_on_front', 'page_for_posts', 'show_on_front',
        'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
        'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
        'can_compress_scripts', 'recently_activated', 'uninstall_plugins',
        'ms_files_rewriting',
    );
}

/**
 * Check whether an option name belongs to WordPress core.
 *
 * @since 2.4.20
 * @param string $name Option name to test.
 * @return bool True if the option is a known WP core option.
 */
function cscc_orphan_is_core( string $name ): bool {
    if ( in_array( $name, cscc_orphan_core_names(), true ) ) { return true; }
    foreach ( cscc_orphan_core_prefixes() as $prefix ) {
        if ( strpos( $name, $prefix ) === 0 ) { return true; }
    }
    return false;
}

/**
 * Return a map of known plugin option name prefixes to human-readable plugin names.
 *
 * Used to attribute orphaned options to a specific deleted plugin rather than showing "Unknown".
 *
 * @since 2.4.20
 * @return array<string,string> Map of prefix → plugin label.
 */
function cscc_orphan_known_prefix_map(): array {
    return array(
        'wpseo'           => 'Yoast SEO',
        'jetpack'         => 'Jetpack',
        'jp_'             => 'Jetpack',
        'stats_cache'     => 'Jetpack Stats',
        'fs_'             => 'Freemius SDK',
        'googlesitekit'   => 'Google Site Kit',
        'rank_math'       => 'Rank Math SEO',
        'rank-math'       => 'Rank Math SEO',
        'aioseo'          => 'All in One SEO',
        'monsterinsights' => 'MonsterInsights',
        'spio_'           => 'ShortPixel Image Optimizer',
        'shortpixel'      => 'ShortPixel',
        'elementor'       => 'Elementor',
        'wpforms'         => 'WPForms',
        'gform_'          => 'Gravity Forms',
        'gravityforms'    => 'Gravity Forms',
        'wordfence'       => 'Wordfence',
        'wfwaf_'          => 'Wordfence',
        'itsec_'          => 'iThemes Security',
        'updraftplus'     => 'UpdraftPlus',
        'mc4wp'           => 'Mailchimp for WP',
        'wpcf7'           => 'Contact Form 7',
        'woocommerce'     => 'WooCommerce',
        'wc_'             => 'WooCommerce',
        'bbpress'         => 'bbPress',
        'buddypress'      => 'BuddyPress',
        'bp_'             => 'BuddyPress',
        'ninja_forms'     => 'Ninja Forms',
        'searchwp'        => 'SearchWP',
        'generatepress'   => 'GeneratePress',
        'astra_'          => 'Astra Theme',
        'neve_'           => 'Neve Theme',
        'oceanwp'         => 'OceanWP Theme',
        'et_'             => 'Divi / Extra',
        'vc_'             => 'WPBakery',
        'brizy'           => 'Brizy Builder',
        'acf_'            => 'Advanced Custom Fields',
        'acf-'            => 'Advanced Custom Fields',
        'newsletter'      => 'Newsletter',
        'popup_maker'     => 'Popup Maker',
        'cs_'             => 'CloudScale Consulting',
        'cscc_'            => 'CloudScale Consulting',
        'ab_seo_'         => 'SEO Plugin',
    );
}

/**
 * Scan wp_options for rows left behind by deleted plugins.
 *
 * Compares option name prefixes against installed plugin slugs and the
 * known prefix map. Options not claimed by any installed plugin are
 * returned as candidates for removal.
 *
 * @since 2.4.20
 * @return array<int, array{name: string, plugin: string, size: int, autoload: string}> Orphaned option rows.
 */
function cscc_find_orphaned_options(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results(
        "SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} ORDER BY size DESC"
    );

    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $installed_slugs = array();
    foreach ( get_plugins() as $file => $data ) {
        $dir  = dirname( $file );
        $slug = $dir === '.' ? basename( $file, '.php' ) : $dir;
        $installed_slugs[] = strtolower( $slug );
        $installed_slugs[] = strtolower( str_replace( '-', '_', $slug ) );
        if ( ! empty( $data['TextDomain'] ) ) {
            $installed_slugs[] = strtolower( $data['TextDomain'] );
            $installed_slugs[] = strtolower( str_replace( '-', '_', $data['TextDomain'] ) );
        }
    }
    // Always protect this plugin's own cscc_ namespace
    $installed_slugs[] = 'csc';
    $installed_slugs = array_unique( array_filter( $installed_slugs ) );

    $known_map  = cscc_orphan_known_prefix_map();
    $candidates = array();

    foreach ( $rows as $row ) {
        $name = $row->option_name;
        if ( cscc_orphan_is_core( $name ) ) { continue; }

        $norm = strtolower( $name );

        // Skip if any installed plugin slug is a prefix of this option name
        $claimed = false;
        foreach ( $installed_slugs as $slug ) {
            if ( strlen( $slug ) < 3 ) { continue; }
            if ( strpos( $norm, $slug ) === 0 ) { $claimed = true; break; }
        }
        if ( $claimed ) { continue; }

        // Identify the likely plugin via known prefix map
        $guessed = 'Unknown plugin';
        foreach ( $known_map as $prefix => $plugin_name ) {
            if ( strpos( $norm, strtolower( $prefix ) ) === 0 ) {
                $guessed = $plugin_name;
                break;
            }
        }

        $candidates[] = array(
            'name'   => $name,
            'size'   => (int) $row->size,
            'plugin' => $guessed,
        );
    }

    return $candidates;
}

add_action( 'wp_ajax_cscc_orphan_scan', 'cscc_ajax_orphan_scan' );
function cscc_ajax_orphan_scan() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
    wp_send_json_success( cscc_find_orphaned_options() );
}

// ── Orphaned options recycle bin ─────────────────────────────────────────────

define( 'CSCC_ORPHAN_BIN_KEY', 'cscc_orphan_recycle_bin' );

/**
 * Return the current contents of the orphan options recycle bin.
 *
 * @since 2.4.20
 * @return array<string, mixed> Bin contents keyed by option name.
 */
function cscc_orphan_bin_get(): array {
    $bin = get_option( CSCC_ORPHAN_BIN_KEY, array() );
    return is_array( $bin ) ? $bin : array();
}

/**
 * Persist the orphan options recycle bin to the database (not autoloaded).
 *
 * @since 2.4.20
 * @param array<string, mixed> $bin Bin contents to save.
 * @return void
 */
function cscc_orphan_bin_save( array $bin ): void {
    update_option( CSCC_ORPHAN_BIN_KEY, $bin, 'no' );
}

add_action( 'wp_ajax_cscc_orphan_delete', 'cscc_ajax_orphan_delete' );
/**
 * AJAX: Move selected orphaned options to the recycle bin.
 *
 * Saves the raw value, autoload flag, and a batch timestamp before calling delete_option().
 *
 * @since 2.4.20
 * @return void Sends JSON response with bin count and batch timestamp.
 */
function cscc_ajax_orphan_delete() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    global $wpdb;
    $names  = isset( $_POST['options'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['options'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $batch  = time();
    $bin    = cscc_orphan_bin_get();
    $moved  = 0;

    foreach ( $names as $name ) {
        $name = sanitize_text_field( wp_unslash( $name ) );
        if ( empty( $name ) || cscc_orphan_is_core( $name ) ) { continue; }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
            $name
        ) );
        if ( ! $row ) { continue; }

        $bin[] = array(
            'name'       => $name,
            'raw_value'  => $row->option_value,
            'autoload'   => $row->autoload,
            'deleted_at' => current_time( 'mysql' ),
            'batch'      => $batch,
        );

        delete_option( $name );
        $moved++;
    }

    cscc_orphan_bin_save( $bin );
    wp_send_json_success( array( 'moved' => $moved, 'bin_count' => count( $bin ), 'batch' => $batch ) );
}

add_action( 'wp_ajax_cscc_orphan_restore', 'cscc_ajax_orphan_restore' );
/**
 * AJAX: Restore orphaned options from the recycle bin.
 *
 * Restores by single name, by batch timestamp, or all when no parameters are supplied.
 *
 * @since 2.4.20
 * @return void Sends JSON response with restored count and remaining bin count.
 */
function cscc_ajax_orphan_restore() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    global $wpdb;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $batch     = isset( $_POST['batch'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : 0;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $single    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $bin       = cscc_orphan_bin_get();
    $restored  = 0;
    $new_bin   = array();

    foreach ( $bin as $entry ) {
        // Restore if: single name matches, or batch matches, or restore-all (batch=0, no single)
        $should_restore = ( $single && $entry['name'] === $single )
                       || ( ! $single && $batch && (int) $entry['batch'] === $batch )
                       || ( ! $single && ! $batch );

        if ( $should_restore ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert( $wpdb->options, array(
                'option_name'  => $entry['name'],
                'option_value' => $entry['raw_value'],
                'autoload'     => $entry['autoload'],
            ) );
            wp_cache_delete( $entry['name'], 'options' );
            $restored++;
        } else {
            $new_bin[] = $entry;
        }
    }

    cscc_orphan_bin_save( $new_bin );
    wp_send_json_success( array( 'restored' => $restored, 'bin_count' => count( $new_bin ) ) );
}

add_action( 'wp_ajax_cscc_orphan_empty', 'cscc_ajax_orphan_empty' );
/**
 * AJAX: Permanently empty the orphan options recycle bin.
 *
 * @since 2.4.20
 * @return void Sends JSON success response.
 */
function cscc_ajax_orphan_empty() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $bin   = cscc_orphan_bin_get();
    $count = count( $bin );
    cscc_orphan_bin_save( array() );
    wp_send_json_success( array( 'emptied' => $count ) );
}

add_action( 'wp_ajax_cscc_orphan_bin_list', 'cscc_ajax_orphan_bin_list' );
/**
 * AJAX: Return a summary of the orphan options recycle bin (size only, no raw values).
 *
 * @since 2.4.20
 * @return void Sends JSON response with item list and total count.
 */
function cscc_ajax_orphan_bin_list() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
    $bin = cscc_orphan_bin_get();
    // Strip raw_value from list response (can be large)
    $safe = array_map( function( $e ) {
        return array(
            'name'       => $e['name'],
            'size'       => strlen( $e['raw_value'] ),
            'deleted_at' => $e['deleted_at'],
            'batch'      => $e['batch'],
        );
    }, $bin );
    wp_send_json_success( array( 'items' => $safe, 'count' => count( $bin ) ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// TABLE OVERHEAD REPAIR
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Return the RAG status for total table overhead.
 *
 * Thresholds: green < 12 MB, amber 12 to 28 MB, red > 28 MB.
 *
 * @since 2.4.30
 * @param int $bytes Total overhead in bytes.
 * @return string 'green', 'amber', or 'red'.
 */
function cscc_table_overhead_rag( int $bytes ): string {
    if ( $bytes > 28 * MB_IN_BYTES ) { return 'red'; }
    if ( $bytes > 12 * MB_IN_BYTES ) { return 'amber'; }
    return 'green';
}

/**
 * Return the total reclaimable overhead across all site tables.
 *
 * Sums the Data_free column from SHOW TABLE STATUS for all tables matching the site prefix.
 *
 * @since 2.4.30
 * @return int Total overhead in bytes.
 */
function cscc_table_overhead_total(): int {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
    $rows  = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE `Name` LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
    $total = 0;
    foreach ( $rows as $row ) { $total += (int) $row->Data_free; }
    return $total;
}

add_action( 'wp_ajax_cscc_table_scan', 'cscc_ajax_table_scan' );
/**
 * AJAX: Dry-run scan, returns a list of tables with significant overhead.
 *
 * @since 2.4.30
 * @return void Sends JSON response with table list, total overhead, and RAG status.
 */
function cscc_ajax_table_scan() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
    $rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE `Name` LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
    $tables = array();
    $total  = 0;

    foreach ( $rows as $row ) {
        $free = (int) $row->Data_free;
        if ( $free < 100 * KB_IN_BYTES ) { continue; }
        $tables[] = array(
            'name'     => $row->Name,
            'data'     => (int) $row->Data_length,
            'index'    => (int) $row->Index_length,
            'overhead' => $free,
            'engine'   => $row->Engine,
        );
        $total += $free;
    }
    usort( $tables, fn( $a, $b ) => $b['overhead'] - $a['overhead'] );

    $rag       = cscc_table_overhead_rag( $total );
    $rag_label = array( 'green' => '✅ Healthy', 'amber' => '⚠️ Warning', 'red' => '🔴 Critical' );

    $lines   = array();
    $lines[] = array( 'type' => 'section', 'text' => 'Table Overhead Summary' );
    $lines[] = array( 'type' => 'info',    'text' => '  Total reclaimable overhead : ' . size_format( $total ) . ', ' . $rag_label[ $rag ] );
    $lines[] = array( 'type' => 'info',    'text' => '  Tables with > 100 KB overhead : ' . count( $tables ) );
    $lines[] = array( 'type' => 'info',    'text' => '  Note: InnoDB Data_free is an estimate, actual savings may vary slightly.' );

    if ( ! empty( $tables ) ) {
        $lines[] = array( 'type' => 'section', 'text' => 'Tables to Optimise (sorted by overhead)' );
        foreach ( $tables as $t ) {
            $lines[] = array( 'type' => 'item', 'text' => sprintf(
                '  %-45s  overhead: %-8s  data: %-8s  idx: %-8s  engine: %s',
                $t['name'], size_format( $t['overhead'] ), size_format( $t['data'] ), size_format( $t['index'] ), $t['engine']
            ) );
        }
        $lines[] = array( 'type' => 'section', 'text' => 'What OPTIMIZE TABLE does' );
        $lines[] = array( 'type' => 'info',    'text' => '  Rewrites the table compactly, reclaiming gaps left by DELETE operations.' );
        $lines[] = array( 'type' => 'info',    'text' => '  InnoDB uses online DDL (no table lock on MySQL 5.6+), safe on live sites.' );
        $lines[] = array( 'type' => 'info',    'text' => '  MyISAM tables are briefly locked during optimisation.' );
    } else {
        $lines[] = array( 'type' => 'success', 'text' => '  No tables with significant overhead. Nothing to optimise.' );
    }

    wp_send_json_success( $lines );
}

add_action( 'wp_ajax_cscc_table_start', 'cscc_ajax_table_start' );
/**
 * AJAX: Build the OPTIMIZE TABLE queue for all tables with overhead > 100 KB.
 *
 * @since 2.4.30
 * @return void Sends JSON response with total queue count.
 */
function cscc_ajax_table_start() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
    $rows  = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE `Name` LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
    $queue = array();
    foreach ( $rows as $row ) {
        if ( (int) $row->Data_free >= 100 * KB_IN_BYTES ) {
            $queue[] = array( 'type' => 'optimize', 'table' => $row->Name, 'label' => $row->Name );
        }
    }

    set_transient( 'cscc_table_queue', $queue, HOUR_IN_SECONDS );
    wp_send_json_success( array(
        'total'     => count( $queue ),
        'remaining' => count( $queue ),
        'lines'     => array( array( 'type' => 'info', 'text' => '  ' . count( $queue ) . ' table(s) queued for optimisation.' ) ),
    ) );
}

add_action( 'wp_ajax_cscc_table_chunk', 'cscc_ajax_table_chunk' );
/**
 * AJAX: Run OPTIMIZE TABLE on one table from the queue.
 *
 * Records before/after Data_free values and reports the bytes saved.
 *
 * @since 2.4.30
 * @return void Sends JSON response with remaining count and log lines.
 */
function cscc_ajax_table_chunk() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $queue = get_transient( 'cscc_table_queue' );
    if ( ! is_array( $queue ) ) { wp_send_json_error( 'Session expired, please start again.' ); }

    global $wpdb;
    $item = array_shift( $queue );
    set_transient( 'cscc_table_queue', $queue, HOUR_IN_SECONDS );

    $lines = array();
    if ( $item ) {
        $table = $item['table'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT Data_free FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s', DB_NAME, $table ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $table ) . '`' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $after   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT Data_free FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s', DB_NAME, $table ) );
        $saved   = max( 0, $before - $after );
        $lines[] = array( 'type' => 'count', 'text' => sprintf( '  %-45s  saved: %s', $table, size_format( $saved ) ) );
    }

    wp_send_json_success( array( 'remaining' => count( $queue ), 'lines' => $lines ) );
}

add_action( 'wp_ajax_cscc_table_finish', 'cscc_ajax_table_finish' );
/**
 * AJAX: Finalise the table repair run, returns new total overhead and RAG status.
 *
 * @since 2.4.30
 * @return void Sends JSON response with new_overhead, new_rag, and summary line.
 */
function cscc_ajax_table_finish() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    delete_transient( 'cscc_table_queue' );
    $new_overhead = cscc_table_overhead_total();
    $new_rag      = cscc_table_overhead_rag( $new_overhead );

    wp_send_json_success( array(
        'lines'        => array( array( 'type' => 'success', 'text' => 'Optimisation complete. Remaining overhead: ' . size_format( $new_overhead ) . '.' ) ),
        'new_overhead' => $new_overhead,
        'new_rag'      => $new_rag,
    ) );
}

// ─── Generate Missing Thumbnails ─────────────────────────────────────────────

add_action( 'wp_ajax_cscc_regen_thumb_scan', 'cscc_ajax_regen_thumb_scan' );
function cscc_ajax_regen_thumb_scan() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    // No request-time extension is asked for here. WordPress.org rejects the PHP call that
    // raises it outright, and this handler only READS (its single write is one transient, at
    // the very end): if it does hit the limit on a large site nothing is left half-done, the
    // request simply fails and can be retried. The write handlers are a different case.

    $sizes  = wp_get_registered_image_subsizes();
    $upload = wp_upload_dir();
    global $wpdb;

    // Only check images actively in use as featured images, site logo, or site icon.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
    $candidate_ids = array_map( 'intval', $wpdb->get_col(
        "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value != '0'"
    ) );
    $logo = get_theme_mod( 'custom_logo' );
    if ( $logo ) { $candidate_ids[] = intval( $logo ); }
    $icon = intval( get_option( 'site_icon', 0 ) );
    if ( $icon ) { $candidate_ids[] = $icon; }
    $candidate_ids = array_values( array_unique( $candidate_ids ) );

    $total   = count( $candidate_ids );
    $missing = array();

    foreach ( $candidate_ids as $id ) {
        $meta = wp_get_attachment_metadata( $id );
        if ( ! $meta || empty( $meta['file'] ) ) { continue; }
        $dir      = trailingslashit( $upload['basedir'] ) . trailingslashit( dirname( $meta['file'] ) );
        $img_w    = (int) ( $meta['width']  ?? 0 );
        $img_h    = (int) ( $meta['height'] ?? 0 );
        $has_miss = false;
        foreach ( $sizes as $size_name => $size_data ) {
            if ( isset( $meta['sizes'][ $size_name ] ) ) {
                if ( ! file_exists( $dir . $meta['sizes'][ $size_name ]['file'] ) ) {
                    $has_miss = true;
                    break;
                }
            } else {
                $sz_w  = (int) ( $size_data['width']  ?? 0 );
                $sz_h  = (int) ( $size_data['height'] ?? 0 );
                $crop  = ! empty( $size_data['crop'] );
                $large_enough = $crop
                    ? ( $sz_w > 0 && $sz_h > 0 && $img_w >= $sz_w && $img_h >= $sz_h )
                    : ( ( $sz_w > 0 && $img_w > $sz_w ) || ( $sz_h > 0 && $img_h > $sz_h ) );
                if ( $large_enough ) {
                    $has_miss = true;
                    break;
                }
            }
        }
        if ( $has_miss ) {
            $missing[] = array(
                'id'    => $id,
                'used'  => true,
                'title' => get_the_title( $id ) ?: basename( get_attached_file( $id ) ?: '' ),
            );
        }
    }

    // Store queue for the batch endpoint.
    set_transient( 'cscc_regen_thumb_queue', array_column( $missing, 'id' ), HOUR_IN_SECONDS );

    wp_send_json_success( array(
        'total'   => $total,
        'missing' => count( $missing ),
        'images'  => $missing,
    ) );
}

add_action( 'wp_ajax_cscc_regen_thumb_batch', 'cscc_ajax_regen_thumb_batch' );
function cscc_ajax_regen_thumb_batch() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    $queue = get_transient( 'cscc_regen_thumb_queue' );
    if ( ! is_array( $queue ) ) {
        wp_send_json_error( 'Queue expired, please scan again.' );
    }

    $offset     = absint( isset( $_POST['offset'] ) ? $_POST['offset'] : 0 );
    $batch_size = 5;
    $total      = count( $queue );
    $slice      = array_slice( $queue, $offset, $batch_size );

    $batch       = array();
    $regenerated = 0;
    $processed   = 0;

    // Regenerating one attachment's thumbnails is CPU-bound and can take tens of seconds on
    // low-power hardware, so the batch stops on the host's own clock rather than raising it.
    // The reserve covers one more whole image; the deadline is only consulted between images
    // because there is no safe way to abandon a half-written thumbnail set.
    $deadline = cscc_chunk_deadline( 60 );

    foreach ( $slice as $id ) {
        // Out of budget with images left: return what was done and let the client ask again.
        // Checked before starting an image, never during one.
        if ( $processed > 0 && time() >= $deadline ) {
            break;
        }
        ++$processed;

        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            $batch[] = array( 'id' => $id, 'ok' => false, 'skipped' => true, 'error' => 'file_missing' );
            continue;
        }
        $new_meta = wp_generate_attachment_metadata( $id, $file );
        if ( is_wp_error( $new_meta ) ) {
            $batch[] = array( 'id' => $id, 'ok' => false, 'skipped' => false, 'error' => CloudScale_Error_Text::in_seconds( $new_meta->get_error_message() ) );
        } else {
            wp_update_attachment_metadata( $id, $new_meta );
            $batch[]  = array( 'id' => $id, 'ok' => true, 'skipped' => false, 'regenerated' => true );
            $regenerated++;
        }
    }

    // ACTUAL work done, not the nominal batch size. This used to advance by $batch_size on the
    // assumption that every image in the slice was handled; now that the loop can stop early,
    // that assumption would silently SKIP the unprocessed images rather than retry them — a
    // worse outcome than the timeout this replaced, and invisible in the UI.
    $next_offset = $offset + max( 1, $processed );
    wp_send_json_success( array(
        'batch'       => $batch,
        'next_offset' => $next_offset,
        'has_more'    => $next_offset < $total,
        'total'       => $total,
        'regenerated' => $regenerated,
    ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// IMAGE CLEANUP
// ═════════════════════════════════════════════════════════════════════════════

function cscc_get_used_attachment_ids() {
    global $wpdb;
    $used = array();

    // Featured images
    foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id'" ) as $id ) {
        $used[ intval( $id ) ] = true;
    }

    // Gutenberg block IDs and legacy class-based image references, batch to avoid loading all content at once
    $post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type NOT IN ('attachment','revision')" );
    $contents = array();
    foreach ( array_chunk( $post_ids, 50 ) as $batch ) {
        $placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->posts (trusted), placeholders are generated %d strings
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID IN ($placeholders)", $batch ) );
        $contents = array_merge( $contents, $rows );
        unset( $rows );
    }

    // Build a lookup of upload filenames to attachment IDs for URL matching
    $upload_dir  = wp_upload_dir();
    $upload_url  = trailingslashit( $upload_dir['baseurl'] );
    $file_to_id  = array();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
    $rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file'" ); // no user input, table name is trusted $wpdb property
    foreach ( $rows as $row ) {
        $file_to_id[ $row->meta_value ] = intval( $row->post_id );
        // Also index the basename without extension for thumbnail matching
        $base = pathinfo( $row->meta_value, PATHINFO_FILENAME );
        $dir  = dirname( $row->meta_value );
        $file_to_id[ '__base__' . $dir . '/' . $base ] = intval( $row->post_id );
    }

    foreach ( $contents as $c ) {
        // Standard wp-image-NNN class detection
        if ( preg_match_all( '/wp-image-(\d+)/i', $c, $m ) ) {
            foreach ( $m[1] as $id ) { $used[ intval( $id ) ] = true; }
        }
        // Gutenberg block "id":NNN detection
        if ( preg_match_all( '/"id"\s*:\s*(\d+)/i', $c, $m ) ) {
            foreach ( $m[1] as $id ) { $used[ intval( $id ) ] = true; }
        }

        // URL based detection: find all image URLs in wp-content/uploads and resolve to attachment IDs
        if ( preg_match_all( '/(?:src|href)=["\']([^"\']*wp-content\/uploads\/[^"\']+)/i', $c, $url_matches ) ) {
            foreach ( $url_matches[1] as $url ) {
                // Extract relative path after uploads/
                $pos = strpos( $url, 'wp-content/uploads/' );
                if ( $pos === false ) { continue; }
                $rel = substr( $url, $pos + strlen( 'wp-content/uploads/' ) );

                // Direct match (original file referenced by URL)
                if ( isset( $file_to_id[ $rel ] ) ) {
                    $used[ $file_to_id[ $rel ] ] = true;
                    continue;
                }

                // Thumbnail match: strip -NNNxNNN from filename and match to original
                $stripped = preg_replace( '/-\d{2,5}x\d{2,5}(\.[a-zA-Z]+)$/', '$1', $rel );
                if ( $stripped !== $rel && isset( $file_to_id[ $stripped ] ) ) {
                    $used[ $file_to_id[ $stripped ] ] = true;
                    continue;
                }

                // Basename match: use directory + basename without dimensions
                $dir  = dirname( $rel );
                $base = pathinfo( $stripped, PATHINFO_FILENAME );
                $key  = '__base__' . $dir . '/' . $base;
                if ( isset( $file_to_id[ $key ] ) ) {
                    $used[ $file_to_id[ $key ] ] = true;
                }
            }
        }
    }

    // Widget options and theme mods
    $opts = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget_%' OR option_name LIKE 'theme_mods_%'" );
    foreach ( $opts as $v ) {
        if ( preg_match_all( '/"id"\s*:\s*(\d+)/i', $v, $m ) ) {
            foreach ( $m[1] as $id ) { $used[ intval( $id ) ] = true; }
        }
    }

    // Site logo and site icon are always protected
    $logo = get_theme_mod( 'custom_logo' );
    if ( $logo ) { $used[ intval( $logo ) ] = true; }
    $icon = get_option( 'site_icon' );
    if ( $icon ) { $used[ intval( $icon ) ] = true; }

    return $used;
}

// Dry run
add_action( 'wp_ajax_cscc_scan_images', 'cscc_ajax_scan_images' );
function cscc_ajax_scan_images() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    // No request-time extension is asked for here. WordPress.org rejects the PHP call that
    // raises it outright, and this handler only READS (its single write is one transient, at
    // the very end): if it does hit the limit on a large site nothing is left half-done, the
    // request simply fails and can be retried. The write handlers are a different case.

    $used = cscc_get_used_attachment_ids();
    $all  = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) );
    $lines = array();
    $lines[] = array( 'type' => 'section', 'text' => 'Unused Media Attachments' );
    $lines[] = array( 'type' => 'info',    'text' => '  Total in library: ' . count( $all ) . '   Confirmed in use: ' . count( $used ) );

    $unused = array();
    foreach ( $all as $id ) {
        if ( ! isset( $used[ $id ] ) ) { $unused[] = $id; }
    }

    // Collect metadata for each unused attachment
    $total_unused_size = 0;
    $items = array();
    foreach ( $unused as $id ) {
        $file      = get_attached_file( $id );
        $file_size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
        $total_unused_size += $file_size;
        $ext      = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
        $basename = $file ? ( pathinfo( $file, PATHINFO_FILENAME ) . ( $ext ? '.' . $ext : '' ) ) : get_the_title( $id );
        $items[]  = array(
            'id'       => $id,
            'basename' => $basename,
            'size'     => $file_size,
            'size_str' => $file_size > 0 ? size_format( $file_size ) : 'file missing',
        );
    }

    // Group by basename, show duplicates section first, then unique files
    $groups = array();
    foreach ( $items as $item ) {
        $groups[ $item['basename'] ][] = $item;
    }

    $duplicate_groups = array_filter( $groups, function( $g ) { return count( $g ) > 1; } );
    $unique_items     = array_filter( $groups, function( $g ) { return count( $g ) === 1; } );

    if ( ! empty( $duplicate_groups ) ) {
        $lines[] = array( 'type' => 'section', 'text' => 'Duplicate Filenames (' . count( $duplicate_groups ) . ' groups)' );
        foreach ( $duplicate_groups as $basename => $copies ) {
            $group_size = array_sum( array_column( $copies, 'size' ) );
            $ids        = implode( ', ', array_column( $copies, 'id' ) );
            $lines[]    = array( 'type' => 'item', 'text' => '  ' . $basename . ', ' . count( $copies ) . ' copies  IDs: ' . $ids . '  (' . size_format( $group_size ) . ' total)' );
        }
    }

    $lines[] = array( 'type' => 'section', 'text' => 'Unique Unused Files (' . count( $unique_items ) . ')' );
    foreach ( $unique_items as $basename => $copies ) {
        $item    = $copies[0];
        $lines[] = array( 'type' => 'item', 'text' => '  ID ' . $item['id'] . ', ' . $basename . ' (' . $item['size_str'] . ')' );
    }

    $lines[] = array( 'type' => 'count', 'text' => '  Total unused: ' . count( $unused ) . ' (' . count( $duplicate_groups ) . ' duplicate groups, ' . count( $unique_items ) . ' unique)' );
    $lines[] = array( 'type' => 'count', 'text' => '  Total size on disk: ' . size_format( $total_unused_size ) );

    wp_send_json_success( $lines );
}

// ─── Media Recycle Bin helpers ────────────────────────────────────────────────

/**
 * Resolve the physical stored path (relative to files/) for a manifest entry's file.
 * New entries use stored_map; old entries fall back to the original relative path.
 */
function cscc_media_recycle_stored_rel( array $entry, string $original_rel ): string {
    if ( ! empty( $entry['stored_map'] ) && isset( $entry['stored_map'][ $original_rel ] ) ) {
        return $entry['stored_map'][ $original_rel ];
    }
    return $original_rel;
}

function cscc_media_recycle_dir(): string {
    return trailingslashit( wp_upload_dir()['basedir'] ) . '.csc-media-recycle/';
}

function cscc_media_recycle_manifest(): string {
    return cscc_media_recycle_dir() . 'manifest.json';
}

function cscc_media_recycle_count(): int {
    $manifest = cscc_media_recycle_manifest();
    if ( ! file_exists( $manifest ) ) { return 0; }
    $data = json_decode( file_get_contents( $manifest ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files, not remote URLs
    return is_array( $data ) ? count( $data ) : 0;
}

/**
 * Ensure the media recycle directory exists, is protected from direct web
 * access, and contains index.php / .htaccess guards.
 */
function cscc_media_recycle_ensure_dir(): bool {
    $dir = cscc_media_recycle_dir();
    if ( ! wp_mkdir_p( $dir ) ) {
        error_log( '[CSC] Cannot create media recycle directory: ' . $dir ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- filesystem error logging
        return false;
    }
    // Pre-create the files/ subdirectory so individual file moves don't need to create it
    $files_dir = $dir . 'files/';
    if ( ! wp_mkdir_p( $files_dir ) ) {
        error_log( '[CSC] Cannot create media recycle files directory: ' . $files_dir ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- filesystem error logging
        return false;
    }
    // Verify files/ is actually writable, a write-test catches permission issues early
    $test_path = $files_dir . '.write-test';
    if ( false === @file_put_contents( $test_path, '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- write test, no WP_Filesystem equivalent
        // Attempt chmod in case the dir was created by a different process/user
        @chmod( $dir, 0755 );
        @chmod( $files_dir, 0755 );
        if ( false === @file_put_contents( $test_path, '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- write test after chmod
            error_log( '[CSC] Media recycle files directory is not writable (chmod attempted): ' . $files_dir ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- filesystem error logging
            return false;
        }
    }
    @unlink( $test_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleanup of write-test file
    // Prevent directory listing and direct file access
    $htaccess = $dir . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        @file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes plugin-owned local manifest/meta files
    }
    return true;
}

/**
 * Read the media recycle manifest with corruption detection.
 * If manifest.json is corrupted, try the backup. If both fail, return empty.
 */
function cscc_media_recycle_read_manifest(): array {
    $path   = cscc_media_recycle_manifest();
    $backup = $path . '.bak';

    // Try primary manifest
    if ( file_exists( $path ) ) {
        $raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files
        $data = json_decode( $raw, true );
        if ( is_array( $data ) ) {
            return $data;
        }
        error_log( '[CSC] Media recycle manifest.json corrupted (json_last_error=' . json_last_error() . '). Trying backup.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- manifest integrity logging
    }

    // Try backup manifest
    if ( file_exists( $backup ) ) {
        $raw  = file_get_contents( $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem read, not a remote URL
        $data = json_decode( $raw, true );
        if ( is_array( $data ) ) {
            error_log( '[CSC] Recovered media recycle manifest from backup.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- manifest integrity logging
            // Restore the primary from backup
            copy( $backup, $path );
            return $data;
        }
        error_log( '[CSC] Media recycle backup manifest also corrupted.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- manifest integrity logging
    }

    return array();
}

/**
 * Write the media recycle manifest atomically with a backup copy.
 * Returns true on success, false on failure.
 */
function cscc_media_recycle_write_manifest( array $manifest ): bool {
    $path   = cscc_media_recycle_manifest();
    $backup = $path . '.bak';
    $json   = wp_json_encode( $manifest, JSON_PRETTY_PRINT );

    if ( $json === false ) {
        error_log( '[CSC] Failed to encode media recycle manifest (json_last_error=' . json_last_error() . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- manifest integrity logging
        return false;
    }

    // Backup current manifest before overwriting
    if ( file_exists( $path ) ) {
        copy( $path, $backup );
    }

    // Write atomically: write to temp file then rename
    $tmp = $path . '.tmp';
    $written = file_put_contents( $tmp, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local atomic write, WP Filesystem does not support rename
    if ( $written === false ) {
        error_log( '[CSC] Failed to write media recycle manifest temp file.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- manifest integrity logging
        return false;
    }

    if ( ! @rename( $tmp, $path ) ) {
        // Fallback: direct write if rename fails (cross device)
        $written = file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local atomic write fallback
        wp_delete_file( $tmp );
        if ( $written === false ) {
            error_log( '[CSC] Failed to write media recycle manifest (direct write also failed).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- manifest integrity logging
            return false;
        }
    }

    return true;
}

/**
 * Save a single attachment's complete data (post row + meta + files) into the
 * media recycle manifest so it can be fully restored later.
 */
function cscc_media_recycle_save_attachment( int $id ): array {
    global $wpdb;
    $post = get_post( $id, ARRAY_A );
    if ( ! $post ) { return array( 'error' => 'Attachment post not found for ID ' . $id ); }

    $meta = $wpdb->get_results(
        $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id ),
        ARRAY_A
    );

    $file   = get_attached_file( $id );
    $upload = wp_upload_dir();
    $base   = trailingslashit( $upload['basedir'] );
    $recycle = cscc_media_recycle_dir() . 'files/';

    // Collect all physical files: original + thumbnails
    $files_moved = array();
    $errors      = array();

    // Build list of all files for this attachment
    $all_files = array();
    if ( $file && file_exists( $file ) ) {
        $all_files[] = $file;
    }
    $attachment_meta = wp_get_attachment_metadata( $id );
    if ( is_array( $attachment_meta ) && ! empty( $attachment_meta['file'] ) ) {
        $dir = trailingslashit( $base . dirname( $attachment_meta['file'] ) );
        if ( ! empty( $attachment_meta['sizes'] ) && is_array( $attachment_meta['sizes'] ) ) {
            foreach ( $attachment_meta['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $thumb_path = $dir . $size_data['file'];
                    if ( file_exists( $thumb_path ) ) {
                        $all_files[] = $thumb_path;
                    }
                }
            }
        }
        // Scaled original backup (WP 5.3+)
        if ( ! empty( $attachment_meta['original_image'] ) ) {
            $orig_path = $dir . $attachment_meta['original_image'];
            if ( file_exists( $orig_path ) ) {
                $all_files[] = $orig_path;
            }
        }
    }

    $all_files = array_unique( $all_files );

    // stored_map: original_rel -> stored_rel (physical path within files/)
    // Using files/{id}/{basename} avoids stale nested date-dirs with wrong permissions.
    $stored_map = array();

    // Move each file to recycle dir
    foreach ( $all_files as $src_path ) {
        $rel        = str_replace( $base, '', $src_path );
        // Store under files/{id}/ to avoid date-subdirectory permission issues
        $stored_rel = $id . '/' . basename( $src_path );
        $dest       = $recycle . $stored_rel;
        if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
            $errors[] = 'Cannot create dir for: ' . $rel;
            continue;
        }
        if ( @rename( $src_path, $dest ) ) {
            $files_moved[]        = $rel;
            $stored_map[ $rel ]   = $stored_rel;
        } elseif ( @copy( $src_path, $dest ) ) {
            // copy succeeded; best-effort remove source (non-fatal if unlink fails)
            @unlink( $src_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- plugin-owned file, path validated against upload dir
            $files_moved[]        = $rel;
            $stored_map[ $rel ]   = $stored_rel;
        } else {
            // Both rename and copy failed, include path diagnostics to aid debugging
            $src_ok   = file_exists( $src_path ) ? 'Y' : 'N';
            $dest_dir = dirname( $dest );
            $dir_ok   = is_dir( $dest_dir ) ? 'Y' : 'N';
            $dir_w    = is_writable( $dest_dir ) ? 'Y' : 'N';
            $errors[] = 'Failed to move: ' . $rel . ' [src=' . $src_ok . ',dir=' . $dir_ok . ',w=' . $dir_w . ']';
            error_log( '[CSC] Cannot move: ' . $src_path . ' -> ' . $dest . ' src=' . $src_ok . ' dir=' . $dir_ok . ' writable=' . $dir_w ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- filesystem diagnostic logging
        }
    }

    return array(
        'id'          => $id,
        'post'        => $post,
        'meta'        => $meta,
        'files_moved' => $files_moved,
        'stored_map'  => $stored_map,
        'errors'      => $errors,
    );
}

// ─── Chunked Move to Recycle, Step 1: build queue ───────────────────────────

add_action( 'wp_ajax_cscc_img_start', 'cscc_ajax_img_start' );
function cscc_ajax_img_start() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    // No request-time extension is asked for here. WordPress.org rejects the PHP call that
    // raises it outright, and this handler only READS (its single write is one transient, at
    // the very end): if it does hit the limit on a large site nothing is left half-done, the
    // request simply fails and can be retried. The write handlers are a different case.

    $used  = cscc_get_used_attachment_ids();
    $all   = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) );
    $queue = array();
    foreach ( $all as $id ) {
        if ( ! isset( $used[ $id ] ) ) { $queue[] = intval( $id ); }
    }

    set_transient( 'cscc_img_queue', $queue, HOUR_IN_SECONDS );
    wp_send_json_success( array(
        'total'     => count( $queue ),
        'remaining' => count( $queue ),
        'lines'     => array( array( 'type' => 'info', 'text' => '  Found ' . count( $queue ) . ' unused attachments. Moving to recycle bin.' ) ),
    ) );
}

// Step 2: process a chunk, move to recycle instead of deleting
add_action( 'wp_ajax_cscc_img_chunk', 'cscc_ajax_img_chunk' );
function cscc_ajax_img_chunk() {
    global $wpdb;
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    $queue = get_transient( 'cscc_img_queue' );
    if ( ! is_array( $queue ) ) { wp_send_json_error( 'Session expired, please start again.' ); }

    // Load existing manifest
    if ( ! cscc_media_recycle_ensure_dir() ) {
        wp_send_json_error( 'Cannot create media recycle directory.' );
    }
    $manifest = cscc_media_recycle_read_manifest();

    $lines = array();

    // Moving an attachment's files is I/O and its thumbnails can be numerous, so the chunk stops
    // on the host's own clock instead of raising the request limit. Reserve covers one more whole
    // attachment; the deadline is only consulted between attachments, never inside one.
    $deadline = cscc_chunk_deadline( 30 );
    $handled  = 0;

    // ONE ITEM AT A TIME, popped only once it is finished, and the manifest flushed to disk
    // BEFORE its database rows are removed. That ordering is the whole point of this loop:
    //
    //   - It used to array_splice() the whole chunk off the queue and persist that immediately,
    //     so a request killed part-way through lost every remaining ID in the chunk. They were
    //     never retried and nothing said so.
    //   - It used to write the manifest ONCE, after the loop. A kill between deleting an
    //     attachment's rows and that write left the files sitting in the recycle directory with
    //     no manifest entry — deleted from the media library and NOT restorable. That is the one
    //     outcome this feature must never produce, since "recycle" is a promise it can be undone.
    //
    // Now: files moved -> manifest written -> rows deleted -> item popped. A kill at any point
    // leaves either an untouched attachment still queued, or a restorable manifest entry.
    while ( $handled < CSCC_CHUNK_IMAGES && ! empty( $queue ) ) {
        if ( $handled > 0 && time() >= $deadline ) {
            break;
        }

        $id    = (int) $queue[0];
        $title = get_the_title( $id );
        try {
            $result = cscc_media_recycle_save_attachment( $id );
            if ( ! empty( $result['error'] ) ) {
                $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $id . ', ' . $result['error'] );
            } else {
                $file_count = count( $result['files_moved'] );
                $err_count  = count( $result['errors'] );

                // Store in manifest keyed by attachment ID
                $manifest[ (string) $id ] = array(
                    'post'        => $result['post'],
                    'meta'        => $result['meta'],
                    'files_moved' => $result['files_moved'],
                    'stored_map'  => $result['stored_map'],
                    'recycled_at' => current_time( 'mysql' ),
                );

                // Persisted before the rows go, so the item is restorable from this moment on.
                // If this write fails the rows are deliberately LEFT ALONE: an attachment still in
                // the library is a visible non-event, an unrestorable one is silent data loss.
                if ( ! cscc_media_recycle_write_manifest( $manifest ) ) {
                    $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $id . ', manifest write failed; attachment left in place.' );
                    unset( $manifest[ (string) $id ] );
                    array_shift( $queue );
                    set_transient( 'cscc_img_queue', $queue, HOUR_IN_SECONDS );
                    ++$handled;
                    continue;
                }

                // Remove DB records directly, files are already moved, so skip wp_delete_attachment()
                // which is very slow due to hook firing (thumbnail deletion, cache clearing, etc.)
                $wpdb->delete( $wpdb->posts,    array( 'ID'      => $id ), array( '%d' ) );
                $wpdb->delete( $wpdb->postmeta, array( 'post_id' => $id ), array( '%d' ) );
                clean_post_cache( $id );

                $msg = '  [RECYCLED] ID ' . $id . ', ' . esc_html( $title ) . ' (' . $file_count . ' file(s))';
                if ( $err_count > 0 ) {
                    $msg .= ' ⚠ ' . $err_count . ' error(s): ' . implode( '; ', $result['errors'] );
                }
                $lines[] = array( 'type' => 'deleted', 'text' => $msg );
            }
        } catch ( Exception $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [EXCEPTION] ID ' . $id . ', ' . $e->getMessage() );
        } catch ( Throwable $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [FATAL] ID ' . $id . ', ' . $e->getMessage() );
        }

        // Popped whether it succeeded or errored — an item that cannot be processed must not sit
        // at the head of the queue forever, because the client loops on `remaining` and would
        // retry it indefinitely. The failure is reported in `lines` either way.
        array_shift( $queue );
        set_transient( 'cscc_img_queue', $queue, HOUR_IN_SECONDS );
        ++$handled;
    }

    wp_send_json_success( array( 'remaining' => count( $queue ), 'lines' => $lines ) );
}

// Step 3: finish
add_action( 'wp_ajax_cscc_img_finish', 'cscc_ajax_img_finish' );
function cscc_ajax_img_finish() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    delete_transient( 'cscc_img_queue' );
    update_option( 'cscc_last_img_cleanup', current_time( 'mysql' ) );
    $recycle_count = cscc_media_recycle_count();
    wp_send_json_success( array(
        'lines' => array(
            array( 'type' => 'success', 'text' => 'Unused media moved to recycle bin.' ),
            array( 'type' => 'info',    'text' => '  ♻️ ' . $recycle_count . ' item(s) in media recycle bin.' ),
            array( 'type' => 'info',    'text' => '  Use Restore to put them back, or Permanently Delete to remove them.' ),
        ),
        'recycle' => $recycle_count,
    ) );
}

// ─── Media Recycle: Status ───────────────────────────────────────────────────

add_action( 'wp_ajax_cscc_media_recycle_status', 'cscc_ajax_media_recycle_status' );
function cscc_ajax_media_recycle_status() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    wp_send_json_success( array( 'recycle' => cscc_media_recycle_count() ) );
}

// ─── Media Recycle: Browse ───────────────────────────────────────────────────

add_action( 'wp_ajax_cscc_media_recycle_browse', 'cscc_ajax_media_recycle_browse' );
function cscc_ajax_media_recycle_browse() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $manifest = cscc_media_recycle_read_manifest();
    if ( empty( $manifest ) ) {
        wp_send_json_success( array( 'files' => array(), 'total' => 0, 'total_size' => 0 ) );
        return;
    }

    $recycle    = cscc_media_recycle_dir() . 'files/';
    $files      = array();
    $total_size = 0;

    foreach ( $manifest as $att_id => $entry ) {
        $title = isset( $entry['post']['post_title'] ) ? $entry['post']['post_title'] : 'Untitled';
        $size  = 0;
        foreach ( $entry['files_moved'] as $rel ) {
            $path = $recycle . cscc_media_recycle_stored_rel( $entry, $rel );
            if ( file_exists( $path ) ) { $size += filesize( $path ); }
        }
        $total_size += $size;
        $files[] = array(
            'id'        => $att_id,
            'name'      => $title,
            'file_count' => count( $entry['files_moved'] ),
            'size'      => $size,
            'size_fmt'  => size_format( $size ),
            'recycled'  => isset( $entry['recycled_at'] ) ? $entry['recycled_at'] : '',
        );
    }

    wp_send_json_success( array(
        'files'      => $files,
        'total'      => count( $files ),
        'total_size' => size_format( $total_size ),
    ) );
}

// ─── Media Recycle: Restore All ──────────────────────────────────────────────

add_action( 'wp_ajax_cscc_media_restore', 'cscc_ajax_media_restore' );
function cscc_ajax_media_restore() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $lines = array();
    $lines[] = array( 'type' => 'section', 'text' => '=== RESTORING MEDIA FROM RECYCLE BIN ===' );

    $manifest = cscc_media_recycle_read_manifest();
    if ( empty( $manifest ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Media recycle bin is empty.' );
        wp_send_json_success( array( 'lines' => $lines, 'restored' => 0, 'recycle' => 0 ) );
        return;
    }

    $recycle  = cscc_media_recycle_dir() . 'files/';
    $base     = trailingslashit( wp_upload_dir()['basedir'] );
    $restored = 0;
    $errors   = 0;

    foreach ( $manifest as $att_id => $entry ) {
        try {
            $title = isset( $entry['post']['post_title'] ) ? $entry['post']['post_title'] : 'ID ' . $att_id;

            // 1. Move files back
            $file_errors = array();
            foreach ( $entry['files_moved'] as $rel ) {
                $stored = cscc_media_recycle_stored_rel( $entry, $rel );
                $src    = $recycle . $stored;
                $dest   = $base . $rel;
                if ( ! file_exists( $src ) ) {
                    $file_errors[] = 'Missing: ' . $rel;
                    continue;
                }
                if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
                    $file_errors[] = 'Cannot create dir for: ' . $rel;
                    continue;
                }
                if ( ! @rename( $src, $dest ) ) {
                    if ( ! ( copy( $src, $dest ) && unlink( $src ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- plugin-owned file, path validated against upload dir
                        $file_errors[] = 'Move failed: ' . $rel;
                    }
                }
            }

            // 2. Re-insert the attachment post
            $post_data = $entry['post'];
            unset( $post_data['ID'] ); // let WP assign a new ID
            $post_data['import_id'] = intval( $att_id ); // try to keep original ID
            $new_id = wp_insert_post( $post_data, true );

            if ( is_wp_error( $new_id ) ) {
                $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $att_id . ', ' . esc_html( $title ) . ': ' . CloudScale_Error_Text::in_seconds( $new_id->get_error_message() ) );
                $errors++;
                continue;
            }

            // 3. Restore meta rows
            if ( ! empty( $entry['meta'] ) ) {
                global $wpdb;
                foreach ( $entry['meta'] as $row ) {
                    $wpdb->insert( $wpdb->postmeta, array(
                        'post_id'    => $new_id,
                        'meta_key'   => $row['meta_key'],
                        'meta_value' => $row['meta_value'],
                    ) );
                }
            }

            $msg = '  [RESTORED] ID ' . $att_id . ', ' . esc_html( $title );
            if ( ! empty( $file_errors ) ) {
                $msg .= ' ⚠ ' . implode( '; ', $file_errors );
            }
            $lines[] = array( 'type' => 'success', 'text' => $msg );
            unset( $manifest[ $att_id ] );
            $restored++;

        } catch ( Exception $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [EXCEPTION] ID ' . $att_id . ', ' . $e->getMessage() );
            $errors++;
        } catch ( Throwable $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [FATAL] ID ' . $att_id . ', ' . $e->getMessage() );
            $errors++;
        }
    }

    // Update or remove manifest
    if ( empty( $manifest ) ) {
        wp_delete_file( cscc_media_recycle_manifest() );
        cscc_rmdir_recursive( cscc_media_recycle_dir() );
    } else {
        cscc_media_recycle_write_manifest( $manifest );
    }

    $lines[] = array( 'type' => 'success', 'text' => '  ✅ Restored ' . $restored . ' attachment(s).' . ( $errors ? ' ' . $errors . ' error(s).' : '' ) );
    wp_send_json_success( array( 'lines' => $lines, 'restored' => $restored, 'recycle' => count( $manifest ) ) );
}

// ─── Media Recycle: Restore Single ───────────────────────────────────────────

add_action( 'wp_ajax_cscc_media_restore_single', 'cscc_ajax_media_restore_single' );
function cscc_ajax_media_restore_single() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $att_id = sanitize_text_field( wp_unslash( $_POST['att_id'] ?? '' ) );
    if ( empty( $att_id ) ) { wp_send_json_error( 'No attachment ID specified.' ); }

    $manifest = cscc_media_recycle_read_manifest();
    if ( empty( $manifest ) ) { wp_send_json_error( 'Media recycle bin is empty.' ); }
    if ( ! isset( $manifest[ $att_id ] ) ) { wp_send_json_error( 'Attachment not found in recycle bin.' ); }

    $entry   = $manifest[ $att_id ];
    $recycle = cscc_media_recycle_dir() . 'files/';
    $base    = trailingslashit( wp_upload_dir()['basedir'] );

    try {
        // Move files back
        foreach ( $entry['files_moved'] as $rel ) {
            $stored = cscc_media_recycle_stored_rel( $entry, $rel );
            $src    = $recycle . $stored;
            $dest   = $base . $rel;
            if ( file_exists( $src ) ) {
                wp_mkdir_p( dirname( $dest ) );
                if ( ! @rename( $src, $dest ) ) {
                    copy( $src, $dest ) && unlink( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- plugin-owned file, path validated against upload dir
                }
            }
        }

        // Re-insert post
        $post_data = $entry['post'];
        unset( $post_data['ID'] );
        $post_data['import_id'] = intval( $att_id );
        $new_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $new_id ) ) {
            wp_send_json_error( 'Failed to restore post: ' . CloudScale_Error_Text::in_seconds( $new_id->get_error_message() ) );
        }

        // Restore meta
        if ( ! empty( $entry['meta'] ) ) {
            global $wpdb;
            foreach ( $entry['meta'] as $row ) {
                $wpdb->insert( $wpdb->postmeta, array(
                    'post_id'    => $new_id,
                    'meta_key'   => $row['meta_key'],
                    'meta_value' => $row['meta_value'],
                ) );
            }
        }

        unset( $manifest[ $att_id ] );
        if ( empty( $manifest ) ) {
            wp_delete_file( cscc_media_recycle_manifest() );
            cscc_rmdir_recursive( cscc_media_recycle_dir() );
        } else {
            cscc_media_recycle_write_manifest( $manifest );
        }

        $title = isset( $entry['post']['post_title'] ) ? $entry['post']['post_title'] : 'ID ' . $att_id;
        wp_send_json_success( array( 'restored' => esc_html( $title ), 'remaining' => count( $manifest ) ) );

    } catch ( Exception $e ) {
        wp_send_json_error( 'Exception: ' . $e->getMessage() );
    } catch ( Throwable $e ) {
        wp_send_json_error( 'Fatal: ' . $e->getMessage() );
    }
}

// ─── Media Recycle: Permanently Delete ───────────────────────────────────────

add_action( 'wp_ajax_cscc_media_purge', 'cscc_ajax_media_purge' );
function cscc_ajax_media_purge() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $lines = array();
    $lines[] = array( 'type' => 'section', 'text' => '=== PERMANENTLY DELETING MEDIA RECYCLE BIN ===' );

    $manifest = cscc_media_recycle_read_manifest();
    if ( empty( $manifest ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Media recycle bin is empty.' );
        wp_send_json_success( array( 'lines' => $lines, 'deleted' => 0, 'recycle' => 0 ) );
        return;
    }
    $recycle  = cscc_media_recycle_dir() . 'files/';
    $deleted  = 0;
    $errors   = 0;
    $freed    = 0;

    foreach ( $manifest as $att_id => $entry ) {
        try {
            $title = isset( $entry['post']['post_title'] ) ? $entry['post']['post_title'] : 'ID ' . $att_id;
            $file_deleted = 0;
            foreach ( $entry['files_moved'] as $rel ) {
                $stored = cscc_media_recycle_stored_rel( $entry, $rel );
                $path   = $recycle . $stored;
                if ( file_exists( $path ) ) {
                    $freed += filesize( $path );
                    if ( unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- plugin-owned file, path validated against upload dir
                        $file_deleted++;
                    } else {
                        $errors++;
                    }
                } else {
                    $file_deleted++; // already gone
                }
            }
            $lines[] = array( 'type' => 'deleted', 'text' => '  [DELETED] ID ' . $att_id . ', ' . esc_html( $title ) . ' (' . $file_deleted . ' file(s))' );
            $deleted++;
        } catch ( Exception $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [EXCEPTION] ID ' . $att_id . ', ' . $e->getMessage() );
            $errors++;
        } catch ( Throwable $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [FATAL] ID ' . $att_id . ', ' . $e->getMessage() );
            $errors++;
        }
    }

    // Wipe the entire recycle directory
    cscc_rmdir_recursive( cscc_media_recycle_dir() );

    $lines[] = array( 'type' => 'success', 'text' => '  ✅ Permanently deleted ' . $deleted . ' attachment(s). Freed ' . size_format( $freed ) . '.' . ( $errors ? ' ' . $errors . ' error(s).' : '' ) );
    wp_send_json_success( array( 'lines' => $lines, 'deleted' => $deleted, 'recycle' => 0 ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// ORPHAN FILE SCAN
// ═════════════════════════════════════════════════════════════════════════════

// ── Orphan helpers ────────────────────────────────────────────────────────────

function cscc_recycle_dir(): string {
    return trailingslashit( wp_upload_dir()['basedir'] ) . '.csc-recycle/';
}

function cscc_recycle_manifest(): string {
    return cscc_recycle_dir() . 'manifest.json';
}

function cscc_orphan_ext_sets(): array {
    return array(
        'all'       => array(),
        'images'    => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg' ),
        'documents' => array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt', 'ods', 'odp' ),
        'video'     => array( 'mp4', 'mov', 'avi', 'wmv', 'mkv', 'webm', 'flv', 'm4v' ),
        'audio'     => array( 'mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a', 'wma' ),
    );
}

function cscc_get_orphan_files( string $type = 'all' ): array {
    $ext_sets   = cscc_orphan_ext_sets();
    $exts       = isset( $ext_sets[ $type ] ) ? $ext_sets[ $type ] : array();
    return cscc_scan_orphan_files_with_exts( $exts );
}

function cscc_get_orphan_files_multi( array $types ): array {
    $ext_sets = cscc_orphan_ext_sets();
    $exts = array();
    foreach ( $types as $type ) {
        if ( isset( $ext_sets[ $type ] ) ) {
            $exts = array_merge( $exts, $ext_sets[ $type ] );
        }
    }
    return cscc_scan_orphan_files_with_exts( array_unique( $exts ) );
}

/**
 * Core orphan scanner shared by both single-type and multi-type functions.
 * Builds a whitelist of known files from: attachment metadata, thumbnail sizes,
 * AND any image/file URLs referenced in published post content.
 */
function cscc_scan_orphan_files_with_exts( array $exts ): array {
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $base       = trailingslashit( $upload_dir['basedir'] );
    $recycle    = cscc_recycle_dir();

    // 1. Files registered in attachment metadata
    $db_files = array();
    foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file'" ) as $f ) {
        $db_files[ $base . $f ] = true;
    }
    foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attachment_metadata'" ) as $raw ) {
        // Deserialising WordPress's own postmeta: safe, written only by WP core attachment APIs.
        $data = maybe_unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_maybe_unserialize -- reading WP core postmeta
        if ( ! is_array( $data ) || ! isset( $data['file'] ) ) { continue; }
        $dir = trailingslashit( $base . dirname( $data['file'] ) );
        if ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
            foreach ( $data['sizes'] as $sz ) {
                if ( isset( $sz['file'] ) ) { $db_files[ $dir . $sz['file'] ] = true; }
            }
        }
    }

    // 2. Files referenced by URL in published post content
    $contents = $wpdb->get_col(
        "SELECT post_content FROM {$wpdb->posts}
         WHERE post_status = 'publish'
         AND post_type NOT IN ('attachment','revision')
         AND post_content LIKE '%wp-content/uploads/%'"
    );
    foreach ( $contents as $c ) {
        if ( preg_match_all( '/wp-content\/uploads\/([^"\'<>\s\)]+)/i', $c, $m ) ) {
            foreach ( $m[1] as $rel ) {
                $rel = strtok( $rel, '?' ); // strip query strings
                $db_files[ $base . $rel ] = true;
            }
        }
    }

    // 3. Files referenced in widget options and theme mods
    $opts = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget_%' OR option_name LIKE 'theme_mods_%'" );
    foreach ( $opts as $v ) {
        if ( preg_match_all( '/wp-content\/uploads\/([^"\'<>\s\)]+)/i', $v, $m ) ) {
            foreach ( $m[1] as $rel ) {
                $rel = strtok( $rel, '?' );
                $db_files[ $base . $rel ] = true;
            }
        }
    }

    // 4. Scan filesystem for orphans
    $orphans = array();
    try {
        $iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ) );
        foreach ( $iter as $file ) {
            if ( ! $file->isFile() ) { continue; }
            $path = $file->getRealPath();
            if ( strpos( $path, $recycle ) === 0 ) { continue; }
            $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            if ( ! empty( $exts ) && ! in_array( $ext, $exts, true ) ) { continue; }
            if ( ! isset( $db_files[ $path ] ) ) {
                $orphans[] = array( 'path' => $path, 'size' => $file->getSize() );
            }
        }
    } catch ( Exception $e ) {
        return array();
    }
    return $orphans;
}

function cscc_recycle_count(): int {
    $manifest = cscc_recycle_manifest();
    if ( ! file_exists( $manifest ) ) { return 0; }
    $data = json_decode( file_get_contents( $manifest ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files, not remote URLs
    return is_array( $data ) ? count( $data ) : 0;
}

// ── Scan ─────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_cscc_scan_orphan_files', 'cscc_ajax_scan_orphan_files' );
function cscc_ajax_scan_orphan_files() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $raw_types  = sanitize_text_field( wp_unslash( $_POST['file_type'] ?? '' ) );
    $ext_sets   = cscc_orphan_ext_sets();
    if ( empty( $raw_types ) ) {
        wp_send_json_error( 'No file type selected. Please select at least one file type.' );
        return;
    }
    $selected_types = array_filter( array_map( 'sanitize_key', explode( ',', $raw_types ) ), fn($t) => array_key_exists( $t, $ext_sets ) );
    if ( empty( $selected_types ) ) {
        wp_send_json_error( 'Invalid file type selection.' );
        return;
    }
    $type_label = implode( ' + ', array_map( fn($t) => ucfirst($t), $selected_types ) );
    $orphans    = cscc_get_orphan_files_multi( $selected_types );
    $base      = trailingslashit( wp_upload_dir()['basedir'] );
    $recycle_n = cscc_recycle_count();
    $lines     = array();
    $lines[]   = array( 'type' => 'section', 'text' => 'Orphaned Files on Disk, ' . $type_label );

    if ( empty( $orphans ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  No orphaned files found.' );
    } else {
        // Split into three groups
        $group_trash   = array();
        $group_bak     = array();
        $group_regular = array();

        foreach ( $orphans as $o ) {
            $rel = str_replace( $base, '', $o['path'] );
            if ( strpos( $rel, 'wpmc-trash/' ) === 0 || strpos( $rel, '.trash/' ) === 0 ) {
                $group_trash[] = array_merge( $o, array( 'rel' => $rel ) );
            } elseif ( preg_match( '/\.bak\.[a-z0-9]+$/i', $rel ) ) {
                $group_bak[] = array_merge( $o, array( 'rel' => $rel ) );
            } else {
                $group_regular[] = array_merge( $o, array( 'rel' => $rel ) );
            }
        }

        $render_group = function( array $items, string $tag, string $label ) use ( &$lines ) {
            if ( empty( $items ) ) { return; }
            $size = array_sum( array_column( $items, 'size' ) );
            $lines[] = array( 'type' => 'section', 'text' => '  ── ' . $label . ' (' . count( $items ) . ' files, ' . size_format( $size ) . ')' );
            foreach ( $items as $o ) {
                $lines[] = array( 'type' => 'item', 'text' => '    [' . $tag . '] ' . $o['rel'] . ' (' . size_format( $o['size'] ) . ')' );
            }
        };

        $render_group( $group_trash,   'TRASH',  'Plugin Trash Folder' );
        $render_group( $group_bak,     'BACKUP', 'Backup Files (.bak)' );
        $render_group( $group_regular, 'ORPHAN', 'Unregistered Uploads' );

        $total_size = array_sum( array_column( $orphans, 'size' ) );
        $lines[] = array( 'type' => 'count', 'text' => '  Total: ' . count( $orphans ) . ' files, ' . size_format( $total_size ) . ' recoverable' );
    }

    if ( $recycle_n > 0 ) {
        $lines[] = array( 'type' => 'info', 'text' => '  ♻️ Recycle bin: ' . $recycle_n . ' file(s) awaiting permanent deletion or restore.' );
    }

    wp_send_json_success( array( 'lines' => $lines, 'found' => count( $orphans ), 'recycle' => $recycle_n ) );
}

// ── Move to Recycle ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_cscc_recycle_orphan_files', 'cscc_ajax_recycle_orphan_files' );
function cscc_ajax_recycle_orphan_files() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $raw_types = sanitize_text_field( wp_unslash( $_POST['file_type'] ?? '' ) );
    $ext_sets  = cscc_orphan_ext_sets();
    if ( empty( $raw_types ) ) {
        wp_send_json_error( 'No file type selected.' );
        return;
    }
    $selected_types = array_filter( array_map( 'sanitize_key', explode( ',', $raw_types ) ), fn($t) => array_key_exists( $t, $ext_sets ) );
    if ( empty( $selected_types ) ) { wp_send_json_error( 'Invalid file type.' ); return; }
    $orphans = cscc_get_orphan_files_multi( $selected_types );
    $recycle = cscc_recycle_dir();
    $lines   = array();
    $lines[] = array( 'type' => 'section', 'text' => '=== MOVING ORPHANS TO RECYCLE BIN ===' );

    if ( empty( $orphans ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  No orphaned files found.' );
        wp_send_json_success( array( 'lines' => $lines, 'moved' => 0 ) );
        return;
    }

    if ( ! wp_mkdir_p( $recycle ) ) {
        wp_send_json_error( 'Could not create recycle directory: ' . $recycle );
        return;
    }

    // Load existing manifest if recycle bin already has files
    $manifest_path = cscc_recycle_manifest();
    $manifest = array();
    if ( file_exists( $manifest_path ) ) {
        $manifest = json_decode( file_get_contents( $manifest_path ), true ) ?: array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files, not remote URLs
    }

    $moved = 0;
    $errors = 0;
    $base = trailingslashit( wp_upload_dir()['basedir'] );

    foreach ( $orphans as $o ) {
        $rel         = str_replace( $base, '', $o['path'] );
        $dest        = $recycle . $rel;
        $dest_dir    = dirname( $dest );

        if ( ! wp_mkdir_p( $dest_dir ) ) {
            $lines[] = array( 'type' => 'error', 'text' => '  Could not create dir for: ' . $rel );
            $errors++;
            continue;
        }

        if ( rename( $o['path'], $dest ) ) {
            $manifest[ $rel ] = $o['path'];
            $lines[] = array( 'type' => 'deleted', 'text' => '  [RECYCLED] ' . $rel . ' (' . size_format( $o['size'] ) . ')' );
            $moved++;
        } else {
            $lines[] = array( 'type' => 'error', 'text' => '  Failed to move: ' . $rel );
            $errors++;
        }
    }

    file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes plugin-owned local manifest/meta files

    $lines[] = array( 'type' => 'success', 'text' => '  ✅ Moved ' . $moved . ' file(s) to recycle bin.' . ( $errors ? ' ' . $errors . ' error(s).' : '' ) );
    $lines[] = array( 'type' => 'info',    'text' => '  Files are in: wp-content/uploads/.csc-recycle/' );
    $lines[] = array( 'type' => 'info',    'text' => '  Use Restore to put them back, or Permanently Delete to wipe them.' );

    wp_send_json_success( array( 'lines' => $lines, 'moved' => $moved, 'recycle' => count( $manifest ) ) );
}

// ── Restore from Recycle ──────────────────────────────────────────────────────

add_action( 'wp_ajax_cscc_restore_orphan_files', 'cscc_ajax_restore_orphan_files' );
function cscc_ajax_restore_orphan_files() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $manifest_path = cscc_recycle_manifest();
    $lines         = array();
    $lines[]       = array( 'type' => 'section', 'text' => '=== RESTORING FILES FROM RECYCLE BIN ===' );

    if ( ! file_exists( $manifest_path ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Recycle bin is empty, nothing to restore.' );
        wp_send_json_success( array( 'lines' => $lines, 'restored' => 0 ) );
        return;
    }

    $manifest = json_decode( file_get_contents( $manifest_path ), true ) ?: array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files, not remote URLs
    if ( empty( $manifest ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Recycle bin is empty, nothing to restore.' );
        wp_send_json_success( array( 'lines' => $lines, 'restored' => 0 ) );
        return;
    }

    $recycle  = cscc_recycle_dir();
    $restored = 0;
    $errors   = 0;

    foreach ( $manifest as $rel => $original_path ) {
        $recycle_path = $recycle . $rel;
        if ( ! file_exists( $recycle_path ) ) {
            $lines[] = array( 'type' => 'error', 'text' => '  Missing from recycle bin: ' . $rel );
            $errors++;
            continue;
        }

        $dest_dir = dirname( $original_path );
        if ( ! wp_mkdir_p( $dest_dir ) ) {
            $lines[] = array( 'type' => 'error', 'text' => '  Could not create dir for: ' . $rel );
            $errors++;
            continue;
        }

        if ( rename( $recycle_path, $original_path ) ) {
            $lines[] = array( 'type' => 'success', 'text' => '  [RESTORED] ' . $rel );
            $restored++;
            unset( $manifest[ $rel ] );
        } else {
            $lines[] = array( 'type' => 'error', 'text' => '  Failed to restore: ' . $rel );
            $errors++;
        }
    }

    // Update or remove manifest
    if ( empty( $manifest ) ) {
        wp_delete_file( $manifest_path );
        // Clean up empty recycle dirs
        cscc_rmdir_recursive( $recycle );
    } else {
        file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes plugin-owned local manifest/meta files
    }

    $lines[] = array( 'type' => 'success', 'text' => '  ✅ Restored ' . $restored . ' file(s) to original locations.' . ( $errors ? ' ' . $errors . ' error(s).' : '' ) );

    wp_send_json_success( array( 'lines' => $lines, 'restored' => $restored, 'recycle' => count( $manifest ) ) );
}

// ── Permanently Delete Recycle Bin ────────────────────────────────────────────

add_action( 'wp_ajax_cscc_purge_orphan_files', 'cscc_ajax_purge_orphan_files' );
function cscc_ajax_purge_orphan_files() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $manifest_path = cscc_recycle_manifest();
    $lines         = array();
    $lines[]       = array( 'type' => 'section', 'text' => '=== PERMANENTLY DELETING RECYCLE BIN ===' );

    if ( ! file_exists( $manifest_path ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Recycle bin is empty, nothing to delete.' );
        wp_send_json_success( array( 'lines' => $lines, 'deleted' => 0 ) );
        return;
    }

    $manifest = json_decode( file_get_contents( $manifest_path ), true ) ?: array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files, not remote URLs
    $recycle  = cscc_recycle_dir();
    $deleted  = 0;
    $errors   = 0;
    $freed    = 0;

    foreach ( $manifest as $rel => $original_path ) {
        $recycle_path = $recycle . $rel;
        if ( file_exists( $recycle_path ) ) {
            $freed += filesize( $recycle_path );
            if ( unlink( $recycle_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- plugin-owned recycle bin file, path built from validated manifest entry
                $lines[] = array( 'type' => 'deleted', 'text' => '  [DELETED] ' . $rel );
                $deleted++;
            } else {
                $lines[] = array( 'type' => 'error', 'text' => '  Failed to delete: ' . $rel );
                $errors++;
            }
        } else {
            $lines[] = array( 'type' => 'info', 'text' => '  Already gone: ' . $rel );
            $deleted++;
        }
    }

    cscc_rmdir_recursive( $recycle );

    $lines[] = array( 'type' => 'success', 'text' => '  ✅ Permanently deleted ' . $deleted . ' file(s). Freed ' . size_format( $freed ) . '.' . ( $errors ? ' ' . $errors . ' error(s).' : '' ) );

    wp_send_json_success( array( 'lines' => $lines, 'deleted' => $deleted, 'recycle' => 0 ) );
}

// ── Recycle bin status (for page load) ───────────────────────────────────────

add_action( 'wp_ajax_cscc_recycle_status', 'cscc_ajax_recycle_status' );

// ── Browse Recycle Bin ──────────────────────────────────────────────────────

add_action( 'wp_ajax_cscc_recycle_browse', 'cscc_ajax_recycle_browse' );
function cscc_ajax_recycle_browse() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $manifest_path = cscc_recycle_manifest();
    if ( ! file_exists( $manifest_path ) ) {
        wp_send_json_success( array( 'files' => array(), 'total' => 0, 'total_size' => 0 ) );
        return;
    }

    $manifest = json_decode( file_get_contents( $manifest_path ), true ) ?: array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files, not remote URLs
    $recycle  = cscc_recycle_dir();
    $files    = array();
    $total_size = 0;

    foreach ( $manifest as $rel => $original_path ) {
        $recycle_path = $recycle . $rel;
        $size = file_exists( $recycle_path ) ? filesize( $recycle_path ) : 0;
        $total_size += $size;
        $files[] = array(
            'rel'      => $rel,
            'name'     => basename( $rel ),
            'original' => $original_path,
            'size'     => $size,
            'size_fmt' => size_format( $size ),
            'date'     => dirname( $rel ),
        );
    }

    wp_send_json_success( array(
        'files'      => $files,
        'total'      => count( $files ),
        'total_size' => size_format( $total_size ),
    ) );
}

// ── Restore Single File from Recycle Bin ─────────────────────────────────────

add_action( 'wp_ajax_cscc_recycle_restore_single', 'cscc_ajax_recycle_restore_single' );
function cscc_ajax_recycle_restore_single() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $rel = sanitize_text_field( wp_unslash( $_POST['rel'] ?? '' ) );
    if ( empty( $rel ) ) { wp_send_json_error( 'No file specified.' ); }

    $manifest_path = cscc_recycle_manifest();
    if ( ! file_exists( $manifest_path ) ) { wp_send_json_error( 'Recycle bin is empty.' ); }

    $manifest = json_decode( file_get_contents( $manifest_path ), true ) ?: array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files, not remote URLs
    if ( ! isset( $manifest[ $rel ] ) ) { wp_send_json_error( 'File not found in manifest.' ); }

    $original_path = $manifest[ $rel ];
    $recycle_path  = cscc_recycle_dir() . $rel;

    if ( ! file_exists( $recycle_path ) ) { wp_send_json_error( 'File missing from recycle bin.' ); }

    $dest_dir = dirname( $original_path );
    if ( ! wp_mkdir_p( $dest_dir ) ) { wp_send_json_error( 'Could not create destination directory.' ); }

    if ( ! rename( $recycle_path, $original_path ) ) { wp_send_json_error( 'Failed to move file.' ); }

    unset( $manifest[ $rel ] );
    if ( empty( $manifest ) ) {
        wp_delete_file( $manifest_path );
        cscc_rmdir_recursive( cscc_recycle_dir() );
    } else {
        file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes plugin-owned local manifest/meta files
    }

    wp_send_json_success( array(
        'restored' => basename( $rel ),
        'remaining' => count( $manifest ),
    ) );
}
function cscc_ajax_recycle_status() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    wp_send_json_success( array( 'recycle' => cscc_recycle_count() ) );
}

// ── Recursive directory removal ───────────────────────────────────────────────

function cscc_rmdir_recursive( string $dir ): void {
    if ( ! is_dir( $dir ) ) { return; }
    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iter as $f ) {
            $f->isDir() ? rmdir( $f->getRealPath() ) : wp_delete_file( $f->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- recursive removal of plugin-owned temp dirs
        }
    } catch ( Exception $e ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Squiz.Commenting.EmptyCatchComment.Missing -- intentional: fall through silently if operation fails
    rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- plugin-owned temp dir
}

// ═════════════════════════════════════════════════════════════════════════════
// IMAGE OPTIMISATION
// ═════════════════════════════════════════════════════════════════════════════

// Dry run scan
/**
 * Shared candidate builder for both the dry-run scan and the live optimiser.
 * Uses stored attachment metadata (no file I/O) so it runs fast on low-power hardware.
 *
 * Returns array of candidates, each:
 *   [ 'id', 'mime', 'size', 'w', 'h', 'flags', 'est_saving', 'file', 'title', 'ext' ]
 * Plus 'skipped_small' count in a separate key via the $skipped_small out-param.
 *
 * @param int  $max_w        Max width px.
 * @param int  $max_h        Max height px.
 * @param bool $convert_png  Whether PNG→JPEG conversion is enabled.
 * @param int  $min_size_b   Minimum file size in bytes (0 = no limit).
 * @param int  $min_gain_pct Minimum estimated gain % to include (0 = no limit).
 * @param int  &$skipped_small  Out-param: count of files excluded by size threshold.
 * @return array
 */
function cscc_optimise_candidates( int $max_w, int $max_h, bool $convert_png, int $min_size_b, int $min_gain_pct, int &$skipped_small ): array {
    $skipped_small = 0;
    $candidates    = array();

    $all = get_posts( array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => array( 'image/jpeg', 'image/jpg', 'image/png' ),
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );

    foreach ( $all as $id ) {
        $mime = get_post_mime_type( $id );
        if ( ! $mime ) { continue; }

        $is_jpeg = in_array( $mime, array( 'image/jpeg', 'image/jpg' ), true );
        $is_png  = ( $mime === 'image/png' );
        if ( ! $is_jpeg && ! $is_png ) { continue; }

        $meta = wp_get_attachment_metadata( $id );
        $size = (int) ( $meta['filesize'] ?? 0 );
        $w    = (int) ( $meta['width']    ?? 0 );
        $h    = (int) ( $meta['height']   ?? 0 );

        // Fall back to disk read for size only if metadata is missing.
        if ( $size === 0 ) {
            $file = get_attached_file( $id );
            if ( $file && file_exists( $file ) ) { $size = (int) filesize( $file ); }
        }

        if ( $min_size_b > 0 && $size > 0 && $size < $min_size_b ) {
            $skipped_small++;
            continue;
        }

        $flags      = array();
        $est_saving = 0;

        if ( $w > $max_w || $h > $max_h ) {
            $flags[] = 'oversized (' . $w . 'x' . $h . ')';
        }

        if ( $is_jpeg ) {
            $est     = (int) ( $size * 0.22 );
            $est_pct = $size > 0 ? ( $est / $size ) * 100 : 0;
            if ( $est > 1024 && $est_pct >= $min_gain_pct ) {
                $flags[]     = 'recompressible (~' . size_format( $est ) . ', ~' . round( $est_pct ) . '%)';
                $est_saving += $est;
            }
        }

        if ( $convert_png && $is_png ) {
            $est     = (int) ( $size * 0.55 );
            $est_pct = $size > 0 ? ( $est / $size ) * 100 : 0;
            if ( $est_pct >= $min_gain_pct ) {
                $flags[]     = 'PNG→JPEG (~' . size_format( $est ) . ', ~' . round( $est_pct ) . '%)';
                $est_saving += $est;
            }
        }

        // Include if oversized (always worth fixing) or if estimated gain passes threshold.
        $oversized = ( $w > $max_w || $h > $max_h );
        if ( empty( $flags ) && ! $oversized ) { continue; }
        if ( ! $oversized && $est_saving === 0 ) { continue; }

        $file  = $file ?? get_attached_file( $id );
        $ext   = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
        $title = get_the_title( $id );

        $candidates[] = array(
            'id'          => intval( $id ),
            'mime'        => $mime,
            'size'        => $size,
            'w'           => $w,
            'h'           => $h,
            'flags'       => $flags,
            'est_saving'  => $est_saving,
            'file'        => $file,
            'title'       => $title,
            'ext'         => $ext,
        );
    }

    return $candidates;
}

add_action( 'wp_ajax_cscc_scan_optimise', 'cscc_ajax_scan_optimise' );
function cscc_ajax_scan_optimise() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $max_w        = intval( get_option( 'cscc_img_max_width',    1920 ) );
    $max_h        = intval( get_option( 'cscc_img_max_height',   1080 ) );
    $quality      = intval( get_option( 'cscc_img_quality',      82   ) );
    $convert_png  = get_option( 'cscc_convert_png_to_jpg', '0' ) === '1';
    $min_size_kb  = max( 0, intval( get_option( 'cscc_img_min_size_kb',  100 ) ) );
    $min_gain_pct = max( 0, intval( get_option( 'cscc_img_min_gain_pct',  10 ) ) );

    $skipped_small = 0;
    $candidates    = cscc_optimise_candidates( $max_w, $max_h, $convert_png, $min_size_kb * 1024, $min_gain_pct, $skipped_small );

    $lines   = array();
    $lines[] = array( 'type' => 'section', 'text' => 'Image Optimisation Scan, max ' . $max_w . 'x' . $max_h . 'px · quality ' . $quality . ' · skip <' . $min_size_kb . ' KB · skip <' . $min_gain_pct . '% gain' );
    $lines[] = array( 'type' => 'info',    'text' => '  ' . count( $candidates ) . ' image(s) to optimise' . ( $skipped_small > 0 ? ' · ' . $skipped_small . ' excluded (under ' . $min_size_kb . ' KB)' : '' ) . '.' );

    $total_saving = 0;
    foreach ( $candidates as $c ) {
        $total_saving += $c['est_saving'];
        $label = esc_html( $c['title'] ) . ( $c['ext'] ? '.' . $c['ext'] : '' );
        $lines[] = array( 'type' => 'item', 'text' => '  [OPTIMISE] ID ' . $c['id'] . ', ' . $label . ' (' . size_format( $c['size'] ) . '), ' . implode( ', ', $c['flags'] ) );
    }

    $lines[] = array( 'type' => 'count', 'text' => '  Estimated total saving: ' . size_format( $total_saving ) );
    if ( $convert_png ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Note: PNG→JPEG conversion is ON.' );
    }
    wp_send_json_success( $lines );
}

// Chunked run, Step 1: build queue
add_action( 'wp_ajax_cscc_optimise_start', 'cscc_ajax_optimise_start' );
function cscc_ajax_optimise_start() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $max_w        = intval( get_option( 'cscc_img_max_width',    1920 ) );
    $max_h        = intval( get_option( 'cscc_img_max_height',   1080 ) );
    $convert_png  = get_option( 'cscc_convert_png_to_jpg', '0' ) === '1';
    $min_size_kb  = max( 0, intval( get_option( 'cscc_img_min_size_kb',  100 ) ) );
    $min_gain_pct = max( 0, intval( get_option( 'cscc_img_min_gain_pct',  10 ) ) );

    $skipped_small = 0;
    $candidates    = cscc_optimise_candidates( $max_w, $max_h, $convert_png, $min_size_kb * 1024, $min_gain_pct, $skipped_small );
    $queue         = array_column( $candidates, 'id' );

    // Store in a plain wp_option (autoload=no), transients are unreliable when object
    // caches are flushed or another plugin's transient cleaner runs mid-sequence.
    delete_option( 'cscc_optimise_run' );
    update_option( 'cscc_optimise_run', array(
        'queue'   => $queue,
        'saved'   => 0,
        'count'   => 0,
        'started' => time(),
    ), false );

    wp_send_json_success( array(
        'total'     => count( $queue ),
        'remaining' => count( $queue ),
        'lines'     => array_filter( array(
            array( 'type' => 'info', 'text' => '  ' . count( $queue ) . ' images queued. Processing 1 per request.' ),
            $skipped_small > 0 ? array( 'type' => 'info', 'text' => '  ' . $skipped_small . ' file(s) skipped, under ' . $min_size_kb . ' KB minimum.' ) : null,
        ) ),
    ) );
}

// Step 2: process a chunk
add_action( 'wp_ajax_cscc_optimise_chunk', 'cscc_ajax_optimise_chunk' );
function cscc_ajax_optimise_chunk() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $run = get_option( 'cscc_optimise_run' );
    if ( ! is_array( $run ) || ! isset( $run['queue'] ) ) {
        $exists = get_option( 'cscc_optimise_run', '__missing__' );
        $detail = ( $exists === '__missing__' )
            ? 'The session option was not found in the database, it may have been cleared by another plugin or a transient cleanup cron.'
            : 'The session data is present but corrupt (type: ' . gettype( $exists ) . ').';
        wp_send_json_error( 'Optimisation session lost. ' . $detail . ' Please click Optimise Images Now to start again.' );
    }

    $max_w        = intval( get_option( 'cscc_img_max_width',    1920 ) );
    $max_h        = intval( get_option( 'cscc_img_max_height',   1080 ) );
    $quality      = intval( get_option( 'cscc_img_quality',      82   ) );
    $convert_png  = get_option( 'cscc_convert_png_to_jpg', '0' ) === '1';
    $min_gain_pct = max( 0, intval( get_option( 'cscc_img_min_gain_pct', 10 ) ) );

    // NO deadline check and no time-limit lift here, and both omissions are deliberate.
    //
    // This handler does exactly ONE image per request, so there is no boundary between items to
    // yield on — the unit of work is already as small as it goes. Raising the ceiling is what
    // WordPress.org rejects, and it was protecting the wrong thing anyway: the danger was never
    // the request ending, it was WHERE it ended. So instead of buying more time, the commit
    // sequence below was made safe to be killed at any point (see the notes on each rename).
    //
    // What that leaves is: on a host whose limit is shorter than one image takes, that image is
    // never optimised and the client moves on. Slow, visible, and non-destructive.
    $queue       = $run['queue'];
    $chunk       = array_slice( $queue, 0, 1 ); // 1 image per request, wp_generate_attachment_metadata can take 30+ s on a Pi
    $total_saved = (int) ( $run['saved'] ?? 0 );
    $total_count = (int) ( $run['count'] ?? 0 );

    // The queue is NOT advanced yet. It used to be spliced and persisted before the image was
    // touched, so a request killed during the work silently skipped that image with nothing to
    // say it had been dropped. It is now advanced after the attempt, at the end of the handler.

    $lines = array();

    foreach ( $chunk as $id ) {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            $lines[] = array( 'type' => 'info', 'text' => '  Skipped ID ' . $id . ', file not found.' );
            continue;
        }

        $mime     = mime_content_type( $file );
        $size_old = filesize( $file );
        $title    = get_the_title( $id );
        $dims     = @getimagesize( $file );
        if ( ! $dims ) {
            $lines[] = array( 'type' => 'info', 'text' => '  Skipped ID ' . $id . ', cannot read image dimensions.' );
            continue;
        }
        list( $w, $h ) = $dims;

        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $id . ': ' . CloudScale_Error_Text::in_seconds( $editor->get_error_message() ) );
            continue;
        }

        $editor->set_quality( $quality );

        $resized = ( $w > $max_w || $h > $max_h );
        if ( $resized ) {
            $editor->resize( $max_w, $max_h, false );
        }

        // PNG to JPEG conversion, save to temp first, check gain, then commit or discard.
        if ( $convert_png && $mime === 'image/png' ) {
            $new_file = preg_replace( '/\.png$/i', '.jpg', $file );
            $tmp_file = $new_file . '.csc-tmp';
            $result   = $editor->save( $tmp_file, 'image/jpeg' );
            if ( is_wp_error( $result ) ) {
                $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $id . ' PNG→JPEG: ' . CloudScale_Error_Text::in_seconds( $result->get_error_message() ) );
                continue;
            }
            $actual_path = $result['path'];
            if ( $actual_path !== $tmp_file ) {
                @rename( $actual_path, $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            }
            $size_new = file_exists( $tmp_file ) ? filesize( $tmp_file ) : 0;
            $saved    = max( 0, $size_old - $size_new );
            $gain_pct = $size_old > 0 ? ( $saved / $size_old ) * 100 : 0;
            if ( $size_new >= $size_old || $gain_pct < $min_gain_pct ) {
                wp_delete_file( $tmp_file );
                $lines[] = array( 'type' => 'info', 'text' => '  Skipped ID ' . $id . ', ' . esc_html( $title ) . ' (gain only ' . round( $gain_pct ) . '%, below ' . $min_gain_pct . '% threshold).' );
                continue;
            }
            // Sufficient gain, commit the conversion.
            //
            // ORDER MATTERS, and it changed: the database is pointed at the new file BEFORE the
            // old one is deleted. It used to delete the .png first, so a request killed in the gap
            // left the attachment pointing at a file that no longer existed — a permanently broken
            // image. Now the worst a kill in that gap leaves is an orphaned .png on disk, which
            // costs a little space and breaks nothing.
            @rename( $tmp_file, $new_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            update_attached_file( $id, $new_file );
            wp_delete_file( $file );
            $meta = wp_generate_attachment_metadata( $id, $new_file );
            wp_update_attachment_metadata( $id, $meta );
            wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/jpeg' ) );
            cscc_update_image_references( $id, $file, $new_file );
        } else {
            // JPEG recompress / resize, save to temp, check gain, then commit or discard.
            $tmp_file    = $file . '.csc-tmp';
            $result      = $editor->save( $tmp_file );
            if ( is_wp_error( $result ) ) {
                $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $id . ': ' . CloudScale_Error_Text::in_seconds( $result->get_error_message() ) );
                continue;
            }
            $actual_path = $result['path'];
            if ( $actual_path !== $tmp_file ) {
                // WP image editor may save to a slightly different path (e.g. it appends the
                // correct extension).  Simply move that file to our canonical tmp name.
                // Do NOT wp_delete_file() first, that deletes the only copy we have.
                @rename( $actual_path, $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            }
            // Safety: if temp still doesn't exist after rename, abort, original must not be touched.
            if ( ! file_exists( $tmp_file ) ) {
                $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $id . ', temp file missing after save; original preserved.' );
                continue;
            }
            $size_new = filesize( $tmp_file );
            $saved    = max( 0, $size_old - $size_new );
            $gain_pct = $size_old > 0 ? ( $saved / $size_old ) * 100 : 0;
            if ( $size_new >= $size_old || $gain_pct < $min_gain_pct ) {
                wp_delete_file( $tmp_file );
                $lines[] = array( 'type' => 'info', 'text' => '  Skipped ID ' . $id . ', ' . esc_html( $title ) . ' (gain only ' . round( $gain_pct ) . '%, below ' . $min_gain_pct . '% threshold).' );
                continue;
            }
            // Sufficient gain, replace original.
            //
            // A single rename, NOT delete-then-rename. rename() over an existing path on the same
            // filesystem is atomic: the file is either the old one or the new one, never absent.
            // The previous delete-then-rename opened a window in which the original was already
            // gone and the replacement not yet in place — and a request killed in that window
            // destroyed the image outright, with no copy anywhere. That window is why this handler
            // appeared to need a raised time limit; closing it is what let the limit go.
            @rename( $tmp_file, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            if ( $resized ) {
                // Dimensions changed, regenerate thumbnails at new size.
                $meta = wp_generate_attachment_metadata( $id, $file );
                wp_update_attachment_metadata( $id, $meta );
            } else {
                // Quality-only recompression, dimensions unchanged, thumbnails still valid.
                $meta = wp_get_attachment_metadata( $id );
                if ( is_array( $meta ) ) {
                    $meta['filesize'] = $size_new;
                    wp_update_attachment_metadata( $id, $meta );
                }
            }
        }

        $saved        = max( 0, $size_old - $size_new );
        $total_saved += $saved;
        $total_count++;

        $lines[] = array( 'type' => 'deleted', 'text' => '  [OPTIMISED] ID ' . $id . ', ' . esc_html( $title ) . ' ' . size_format( $size_old ) . ' → ' . size_format( $size_new ) . ' (saved ' . size_format( $saved ) . ')' );
    }

    // Advance the queue now, after the attempt rather than before it. Popped whether the image
    // succeeded, was skipped or errored: the client loops on `remaining`, so an item left at the
    // head would be retried forever. A request killed before reaching this line leaves the image
    // still queued and gets retried once — which the commit ordering above makes safe.
    array_shift( $queue );

    $run['queue'] = $queue;
    $run['saved'] = $total_saved;
    $run['count'] = $total_count;
    update_option( 'cscc_optimise_run', $run, false );

    wp_send_json_success( array( 'remaining' => count( $queue ), 'total_saved' => $total_saved, 'lines' => $lines ) );
}

// Step 3: finish
add_action( 'wp_ajax_cscc_optimise_finish', 'cscc_ajax_optimise_finish' );
function cscc_ajax_optimise_finish() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $run         = get_option( 'cscc_optimise_run', array() );
    $total_saved = (int) ( $run['saved'] ?? 0 );
    $total_count = (int) ( $run['count'] ?? 0 );

    delete_option( 'cscc_optimise_run' );
    // Clean up old transient keys from previous plugin versions.
    delete_transient( 'cscc_optimise_queue' );
    delete_transient( 'cscc_optimise_saved' );
    delete_transient( 'cscc_optimise_count' );

    update_option( 'cscc_last_img_optimise', current_time( 'mysql' ) );
    wp_send_json_success( array( 'lines' => array(
        array( 'type' => 'count',   'text' => '  Images processed: ' . $total_count ),
        array( 'type' => 'count',   'text' => '  Total disk space saved: ' . size_format( $total_saved ) ),
        array( 'type' => 'success', 'text' => 'Image optimisation complete.' ),
    ) ) );
}

// Helper: update image URL references after PNG → JPEG conversion
function cscc_update_image_references( $attachment_id, $old_file, $new_file ) {
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $base       = trailingslashit( $upload_dir['basedir'] );
    $old_url    = $upload_dir['baseurl'] . '/' . ltrim( str_replace( $base, '', $old_file ), '/' );
    $new_url    = $upload_dir['baseurl'] . '/' . ltrim( str_replace( $base, '', $new_file ), '/' );

    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
        $old_url, $new_url, '%' . $wpdb->esc_like( basename( $old_file ) ) . '%'
    ) );
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET guid = %s WHERE ID = %d", $new_url, $attachment_id ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// PNG TO JPEG CONVERTER
// ═════════════════════════════════════════════════════════════════════════════

function cscc_get_cspj_chunk_mb() {
    // Falls back to the pre-rename key so an existing setting survives the upgrade.
    $val = floatval( get_option( CSCC_OPTION_CHUNK_MB, get_option( CSCC_LEGACY_CHUNK_MB, CSCC_DEFAULT_CHUNK_MB ) ) );
    if ( $val <= 0 ) { $val = CSCC_DEFAULT_CHUNK_MB; }
    return max( 0.25, min( 1.95, $val ) );
}

function cscc_get_cspj_server_max_mb() {
    $parse = function( $val ) {
        $val  = trim( $val );
        $last = strtolower( substr( $val, -1 ) );
        $num  = intval( $val );
        switch ( $last ) {
            case 'g':
                $num *= 1073741824; break;
            case 'm':
                $num *= 1048576;    break;
            case 'k':
                $num *= 1024;       break;
        }
        return $num;
    };
    $upload = $parse( ini_get( 'upload_max_filesize' ) );
    $post   = $parse( ini_get( 'post_max_size' ) );
    return intval( floor( min( $upload, $post ) / 1048576 ) );
}

function cscc_cspj_chunk_root_dir() {
    $u = wp_upload_dir();
    return trailingslashit( $u['basedir'] ) . 'cspj-chunks';
}

function cscc_cspj_chunk_dir( $upload_id ) {
    return trailingslashit( cscc_cspj_chunk_root_dir() ) . sanitize_file_name( $upload_id );
}

function cscc_cspj_delete_dir( $dir ) {
    if ( ! file_exists( $dir ) ) { return; }
    $it    = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
    $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $file ) {
        $file->isDir() ? rmdir( $file->getRealPath() ) : wp_delete_file( $file->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- recursive removal of plugin-owned temp dirs
    }
    rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- plugin-owned temp dir
}

function cscc_cspj_resolve_dimensions( $size, $ow, $oh, $cw, $ch, $constrain ) {
    if ( $size === 'original' ) return array( $ow, $oh );
    if ( $size === 'custom' ) {
        $w = $cw > 0 ? $cw : $ow;
        $h = $ch > 0 ? $ch : $oh;
        if ( $constrain && $cw > 0 && $ch === 0 ) $h = intval( $oh * ( $w / $ow ) );
        if ( $constrain && $ch > 0 && $cw === 0 ) $w = intval( $ow * ( $h / $oh ) );
        return array( max( 1, $w ), max( 1, $h ) );
    }
    $parts = explode( 'x', $size );
    if ( count( $parts ) === 2 ) return array( intval( $parts[0] ), intval( $parts[1] ) );
    return array( $ow, $oh );
}

function cscc_cspj_convert_png_to_jpeg( $png_path, $quality, $size, $custom_w, $custom_h, $constrain, $original_name ) {
    $src = @imagecreatefrompng( $png_path );
    if ( ! $src ) {
        return new WP_Error( 'cspj_decode', 'GD failed to decode the PNG. The file may be corrupt or use an unsupported subtype (e.g. 16 bit).' );
    }
    $orig_w = imagesx( $src );
    $orig_h = imagesy( $src );
    list( $tw, $th ) = cscc_cspj_resolve_dimensions( $size, $orig_w, $orig_h, $custom_w, $custom_h, $constrain );

    if ( $tw !== $orig_w || $th !== $orig_h ) {
        $dst = imagecreatetruecolor( $tw, $th );
        imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
        imagecopyresampled( $dst, $src, 0, 0, 0, 0, $tw, $th, $orig_w, $orig_h );
        imagedestroy( $src ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- required for PHP 7.x GD memory management; no-op on PHP 8.x where GdImage GCs automatically
        $src = $dst;
    } else {
        $dst = imagecreatetruecolor( $orig_w, $orig_h );
        imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
        imagecopy( $dst, $src, 0, 0, 0, 0, $orig_w, $orig_h );
        imagedestroy( $src ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- required for PHP 7.x GD memory management; no-op on PHP 8.x where GdImage GCs automatically
        $src = $dst;
    }

    $upload_dir = wp_upload_dir();
    $out_dir    = trailingslashit( $upload_dir['basedir'] ) . 'cspj-converted';
    wp_mkdir_p( $out_dir );

    $base = pathinfo( $original_name, PATHINFO_FILENAME );
    $base = sanitize_file_name( $base );
    if ( $base === '' ) { $base = 'converted'; }

    $name     = $base . '-' . wp_generate_password( 6, false, false ) . '.jpg';
    $out_path = trailingslashit( $out_dir ) . $name;

    if ( ! imagejpeg( $src, $out_path, $quality ) ) {
        imagedestroy( $src ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- required for PHP 7.x GD memory management; no-op on PHP 8.x where GdImage GCs automatically
        return new WP_Error( 'cspj_encode', 'Failed to encode JPEG.' );
    }
    imagedestroy( $src ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- required for PHP 7.x GD memory management; no-op on PHP 8.x where GdImage GCs automatically

    $url = trailingslashit( $upload_dir['baseurl'] ) . 'cspj-converted/' . $name;
    return array(
        'url'    => $url,
        'name'   => $name,
        'path'   => $out_path,
        'width'  => $tw,
        'height' => $th,
        'size'   => size_format( filesize( $out_path ) ),
    );
}

// AJAX: Save CSPJ chunk size setting
add_action( 'wp_ajax_cscc_pj_save_settings', 'cscc_ajax_cspj_save_settings' );
function cscc_ajax_cspj_save_settings() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    $chunk_mb = floatval( wp_unslash( $_POST['chunk_mb'] ?? CSCC_DEFAULT_CHUNK_MB ) );
    if ( $chunk_mb <= 0 ) { $chunk_mb = CSCC_DEFAULT_CHUNK_MB; }
    $chunk_mb = max( 0.25, min( 1.95, $chunk_mb ) );
    update_option( CSCC_OPTION_CHUNK_MB, $chunk_mb );
    wp_send_json_success( array( 'chunk_mb' => $chunk_mb, 'server_max' => cscc_get_cspj_server_max_mb() ) );
}

// AJAX: Chunked upload, start session
add_action( 'wp_ajax_cscc_pj_chunk_start', 'cscc_ajax_cspj_chunk_start' );
function cscc_ajax_cspj_chunk_start() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $filename   = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    $total_size = intval( wp_unslash( $_POST['total_size'] ?? 0 ) );
    $total      = intval( wp_unslash( $_POST['total_chunks'] ?? 0 ) );

    if ( $filename === '' || $total_size <= 0 || $total <= 0 ) {
        wp_send_json_error( 'Invalid upload session parameters.' );
    }
    if ( $total_size > CSCC_MAX_TOTAL_MB * 1048576 ) {
        wp_send_json_error( 'File exceeds the maximum allowed size of ' . CSCC_MAX_TOTAL_MB . ' MB.' );
    }

    $upload_id = wp_generate_uuid4();
    $dir       = cscc_cspj_chunk_dir( $upload_id );
    wp_mkdir_p( $dir );

    $meta = array(
        'filename'     => $filename,
        'total_size'   => $total_size,
        'total_chunks' => $total,
        'created'      => time(),
        'user_id'      => get_current_user_id(),
    );
    file_put_contents( trailingslashit( $dir ) . 'meta.json', wp_json_encode( $meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes plugin-owned local manifest/meta files
    wp_send_json_success( array( 'upload_id' => $upload_id ) );
}

// AJAX: Chunked upload, receive a chunk
add_action( 'wp_ajax_cscc_pj_chunk_upload', 'cscc_ajax_cspj_chunk_upload' );
function cscc_ajax_cspj_chunk_upload() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $upload_id = sanitize_text_field( wp_unslash( $_POST['upload_id'] ?? '' ) );
    $index     = intval( wp_unslash( $_POST['chunk_index'] ?? -1 ) );
    $total     = intval( wp_unslash( $_POST['total_chunks'] ?? 0 ) );

    if ( $upload_id === '' || $index < 0 || $total <= 0 ) {
        wp_send_json_error( 'Invalid chunk parameters.' );
    }
    if ( empty( $_FILES['chunk'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- tmp_name is a server-generated path from PHP's upload mechanism, not user-supplied
        wp_send_json_error( 'No chunk received by the server.' );
    }

    $dir = cscc_cspj_chunk_dir( $upload_id );
    if ( ! file_exists( $dir ) ) {
        wp_send_json_error( 'Upload session not found (expired or invalid).' );
    }

    // Verify session ownership and bound $index against declared total_chunks.
    $meta_path = trailingslashit( $dir ) . 'meta.json';
    if ( ! file_exists( $meta_path ) ) {
        wp_send_json_error( 'Upload session metadata missing.' );
    }
    $meta = json_decode( file_get_contents( $meta_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files, not remote URLs
    if ( intval( $meta['user_id'] ?? 0 ) !== get_current_user_id() ) {
        wp_send_json_error( 'Upload session owner mismatch.' );
    }
    $meta_total = intval( $meta['total_chunks'] ?? 0 );
    if ( $meta_total <= 0 || $index >= $meta_total ) {
        wp_send_json_error( 'Chunk index out of bounds.' );
    }

    $chunk_mb    = cscc_get_cspj_chunk_mb();
    $chunk_bytes = intval( floor( $chunk_mb * 1048576 ) );
    if ( $_FILES['chunk']['size'] > $chunk_bytes + 4096 ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- size is a server-generated integer from PHP's upload mechanism, not user-supplied
        wp_send_json_error( 'Chunk exceeds configured chunk size of ' . $chunk_mb . ' MB.' );
    }

    $part     = trailingslashit( $dir ) . sprintf( 'chunk-%06d.part', $index );
    $real_dir = realpath( $dir );
    if ( $real_dir === false || strpos( realpath( dirname( $part ) ) . DIRECTORY_SEPARATOR, $real_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
        wp_send_json_error( 'Invalid chunk destination.' );
    }
    if ( ! move_uploaded_file( $_FILES['chunk']['tmp_name'], $part ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- tmp_name is a server-generated path from PHP's upload mechanism, not user-supplied
        wp_send_json_error( 'Server failed to write chunk to disk.' );
    }
    wp_send_json_success( array( 'index' => $index ) );
}

// AJAX: Chunked upload, finish and convert
add_action( 'wp_ajax_cscc_pj_chunk_finish', 'cscc_ajax_cspj_chunk_finish' );
function cscc_ajax_cspj_chunk_finish() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $upload_id  = sanitize_text_field( wp_unslash( $_POST['upload_id'] ?? '' ) );
    $quality    = max( 1, min( 100, intval( wp_unslash( $_POST['quality'] ?? 90 ) ) ) );
    $size       = sanitize_text_field( wp_unslash( $_POST['size'] ?? 'original' ) );
    $custom_w   = intval( wp_unslash( $_POST['custom_w'] ?? 0 ) );
    $custom_h   = intval( wp_unslash( $_POST['custom_h'] ?? 0 ) );
    $constrain  = isset( $_POST['constrain'] ) && wp_unslash( $_POST['constrain'] ) === '1'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- boolean toggle, validated via strict comparison

    $dir       = cscc_cspj_chunk_dir( $upload_id );
    $meta_path = trailingslashit( $dir ) . 'meta.json';
    if ( $upload_id === '' || ! file_exists( $meta_path ) ) {
        wp_send_json_error( 'Upload session not found (expired or invalid).' );
    }

    $meta = json_decode( file_get_contents( $meta_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files, not remote URLs
    if ( empty( $meta['total_chunks'] ) ) {
        wp_send_json_error( 'Upload session metadata missing.' );
    }
    if ( intval( $meta['user_id'] ) !== get_current_user_id() ) {
        wp_send_json_error( 'Upload session owner mismatch.' );
    }

    $total = intval( $meta['total_chunks'] );
    for ( $i = 0; $i < $total; $i++ ) {
        $part = trailingslashit( $dir ) . sprintf( 'chunk-%06d.part', $i );
        if ( ! file_exists( $part ) ) {
            wp_send_json_error( 'Missing chunk ' . $i . ' of ' . $total . '.' );
        }
    }

    $assembled = trailingslashit( $dir ) . 'assembled.png';
    $out = fopen( $assembled, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local binary chunk assembly, WP_Filesystem does not support binary stream operations
    if ( ! $out ) { wp_send_json_error( 'Failed to create assembled file.' ); }

    for ( $i = 0; $i < $total; $i++ ) {
        $part = trailingslashit( $dir ) . sprintf( 'chunk-%06d.part', $i );
        $in   = fopen( $part, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local binary chunk assembly, WP_Filesystem does not support binary stream operations
        if ( ! $in ) { fclose( $out ); wp_send_json_error( 'Failed to read chunk ' . $i . '.' ); }
        stream_copy_to_stream( $in, $out );
        fclose( $in );
    }
    fclose( $out );

    $finfo = finfo_open( FILEINFO_MIME_TYPE );
    $mime  = finfo_file( $finfo, $assembled );
    finfo_close( $finfo ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- finfo_close() still valid in PHP 8.x with explicit finfo resource
    if ( $mime !== 'image/png' ) {
        cscc_cspj_delete_dir( $dir );
        wp_send_json_error( 'Assembled file is not a valid PNG (detected: ' . esc_html( $mime ) . ').' );
    }

    $result = cscc_cspj_convert_png_to_jpeg( $assembled, $quality, $size, $custom_w, $custom_h, $constrain, $meta['filename'] );
    cscc_cspj_delete_dir( $dir );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( CloudScale_Error_Text::in_seconds( $result->get_error_message() ) );
    }

    // Track conversions
    $count = intval( get_option( 'cscc_total_png_conversions', 0 ) );
    update_option( 'cscc_total_png_conversions', $count + 1 );

    wp_send_json_success( $result );
}

// AJAX: Add converted file to Media Library
add_action( 'wp_ajax_cscc_pj_add_to_library', 'cscc_ajax_cspj_add_to_library' );
function cscc_ajax_cspj_add_to_library() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $path     = sanitize_text_field( wp_unslash( $_POST['path']     ?? '' ) );
    $url      = esc_url_raw(  wp_unslash( $_POST['url']      ?? '' ) );
    $new_name = sanitize_text_field( wp_unslash( $_POST['new_name'] ?? '' ) );

    if ( ! file_exists( $path ) ) { wp_send_json_error( 'File not found on disk.' ); }

    $upload_dir = wp_upload_dir();
    if ( strpos( $path, $upload_dir['basedir'] ) !== 0 ) {
        wp_send_json_error( 'Invalid file path.' );
    }

    $final_path = $path;
    $final_url  = $url;

    if ( $new_name !== '' ) {
        $clean    = sanitize_file_name( $new_name );
        $clean    = preg_replace( '/\.jpe?g$/i', '', $clean ) . '.jpg';
        $dir      = trailingslashit( dirname( $path ) );
        $proposed = $dir . $clean;

        if ( file_exists( $proposed ) && $proposed !== $path ) {
            $base    = pathinfo( $clean, PATHINFO_FILENAME );
            $counter = 1;
            while ( file_exists( $dir . $base . '-' . $counter . '.jpg' ) ) { $counter++; }
            $clean    = $base . '-' . $counter . '.jpg';
            $proposed = $dir . $clean;
        }

        if ( $proposed !== $path ) {
            if ( ! rename( $path, $proposed ) ) {
                wp_send_json_error( 'Could not rename the file on disk.' );
            }
            $final_path = $proposed;
            $final_url  = trailingslashit( $upload_dir['url'] ) . $clean;
        }
    }

    $title      = pathinfo( $final_path, PATHINFO_FILENAME );
    $attachment  = array(
        'post_mime_type' => 'image/jpeg',
        'post_title'     => $title,
        'post_content'   => '',
        'post_status'    => 'inherit',
    );
    $attach_id = wp_insert_attachment( $attachment, $final_path );
    if ( is_wp_error( $attach_id ) ) { wp_send_json_error( CloudScale_Error_Text::in_seconds( $attach_id->get_error_message() ) ); }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $final_path ) );

    wp_send_json_success( array(
        'attach_id'  => $attach_id,
        'edit_url'   => admin_url( 'post.php?post=' . $attach_id . '&action=edit' ),
        'final_name' => basename( $final_path ),
        'final_url'  => $final_url,
    ) );
}

// AJAX: Scan for broken image links in post content
add_action( 'wp_ajax_cscc_scan_broken_images', 'cscc_ajax_scan_broken_images' );
function cscc_ajax_scan_broken_images() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $offset = intval( wp_unslash( $_POST['offset'] ?? 0 ) );
    $batch  = 50;

    global $wpdb;
    $upload_dir = wp_upload_dir();
    $upload_url = trailingslashit( $upload_dir['baseurl'] );
    $upload_base = trailingslashit( $upload_dir['basedir'] );

    // Get total count on first call
    $total = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_status = 'publish'
         AND post_type IN ('post', 'page')
         AND post_content LIKE '%<img%'"
    );

    // Get batch of posts containing images
    $like_img = '%' . $wpdb->esc_like( '<img' ) . '%';
    $posts = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->posts, not user input
        "SELECT ID, post_title, post_content FROM {$wpdb->posts}
         WHERE post_status = 'publish'
         AND post_type IN ('post', 'page')
         AND post_content LIKE %s
         ORDER BY ID ASC
         LIMIT %d OFFSET %d",
        $like_img, $batch, $offset
    ) );

    $broken = array();

    foreach ( $posts as $post ) {
        // Extract all img src URLs
        if ( ! preg_match_all( '/src=["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
            continue;
        }

        foreach ( $matches[1] as $url ) {
            // Only check URLs from our uploads directory
            if ( strpos( $url, 'wp-content/uploads/' ) === false ) {
                continue;
            }

            // Convert URL to file path
            $rel_path = preg_replace( '#^https?://[^/]+/#', '', $url );
            $file_path = ABSPATH . $rel_path;

            // Also try matching via upload_url
            if ( strpos( $url, $upload_url ) === 0 ) {
                $file_path = $upload_base . substr( $url, strlen( $upload_url ) );
            }

            if ( ! file_exists( $file_path ) ) {
                $broken[] = array(
                    'post_id'    => $post->ID,
                    'post_title' => $post->post_title,
                    'edit_url'   => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
                    'image_url'  => $url,
                    'expected'   => $file_path,
                );
            }
        }
    }

    $next_offset = $offset + $batch;
    $has_more    = $next_offset < $total;

    wp_send_json_success( array(
        'broken'   => $broken,
        'offset'   => $next_offset,
        'total'    => (int) $total,
        'has_more' => $has_more,
    ) );
}

// AJAX: Delete a converted JPEG file from disk
add_action( 'wp_ajax_cscc_pj_delete_converted', 'cscc_ajax_cspj_delete_converted' );
function cscc_ajax_cspj_delete_converted() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $path = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
    if ( empty( $path ) ) { wp_send_json_error( 'No path provided.' ); }

    $upload_dir = wp_upload_dir();
    if ( strpos( $path, $upload_dir['basedir'] ) !== 0 ) {
        wp_send_json_error( 'Invalid file path.' );
    }
    // Only allow deletion from the cspj-converted directory
    if ( strpos( $path, 'cspj-converted' ) === false ) {
        wp_send_json_error( 'Can only delete files from the cspj-converted folder.' );
    }

    if ( file_exists( $path ) ) {
        wp_delete_file( $path );
        wp_send_json_success( 'File deleted.' );
    } else {
        wp_send_json_error( 'File not found.' );
    }
}

// Cron: cleanup stale chunks.
// Both names are hooked during the transition: an install upgraded mid-cycle still has the
// legacy event sitting in cron, and a scheduled event whose handler has been renamed away runs
// forever doing nothing — which is how the chunk directory silently stops being cleaned.
add_action( 'cscc_cleanup_chunks', 'cscc_cspj_cron_cleanup' );
add_action( 'cspj_cleanup_chunks', 'cscc_cspj_cron_cleanup' );
function cscc_cspj_cron_cleanup() {
    try {
        $root = cscc_cspj_chunk_root_dir();
        if ( ! file_exists( $root ) ) { return; }
        $now     = time();
        $max_age = 6 * 3600;
        foreach ( glob( trailingslashit( $root ) . '*' ) as $dir ) {
            if ( ! is_dir( $dir ) ) { continue; }
            $meta    = trailingslashit( $dir ) . 'meta.json';
            $created = file_exists( $meta ) ? intval( json_decode( file_get_contents( $meta ), true )['created'] ?? 0 ) : 0; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads plugin-owned local files, not remote URLs
            $age     = $created > 0 ? ( $now - $created ) : ( $now - filemtime( $dir ) );
            if ( $age > $max_age ) { cscc_cspj_delete_dir( $dir ); }
        }
    } catch ( \Throwable $e ) {
        error_log( sprintf( '[CSC] cron "cscc_cleanup_chunks" exception (%s): %s in %s line %d', get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational cron logging
    }
}

// Schedule chunk cleanup cron on activation, and retire the pre-rename event if it is still
// scheduled. Unscheduling is not optional: leaving it would double-run the cleanup on every
// upgraded install, because both names are hooked to the same callback above.
register_activation_hook( __FILE__, function() {
    $legacy = wp_next_scheduled( 'cspj_cleanup_chunks' );
    if ( $legacy ) {
        wp_unschedule_event( $legacy, 'cspj_cleanup_chunks' );
    }
    if ( ! wp_next_scheduled( 'cscc_cleanup_chunks' ) ) {
        wp_schedule_event( time() + 3600, 'hourly', 'cscc_cleanup_chunks' );
    }
} );

// ═════════════════════════════════════════════════════════════════════════════
// SITE HEALTH METRICS
// ═════════════════════════════════════════════════════════════════════════════

// Option keys for stored metrics
define( 'CSCC_HEALTH_HOURLY_KEY',  'cscc_health_hourly_metrics' );
define( 'CSCC_HEALTH_WEEKLY_KEY',  'cscc_health_weekly_snapshots' );
define( 'CSCC_HEALTH_MAX_AGE',     180 ); // days, expire data older than 6 months

// ─── Metric collection helpers ────────────────────────────────────────────────

/**
 * Get total disk usage of the WordPress installation in bytes.
 * Falls back to du on the wp-content directory if available.
 */
function cscc_health_get_disk_usage_bytes(): int {
    return cscc_health_dir_size( WP_CONTENT_DIR );
}

/**
 * Recursive directory size in bytes (PHP fallback).
 */
function cscc_health_dir_size( string $dir ): int {
    $size = 0;
    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $iter as $file ) {
            if ( $file->isFile() ) {
                $size += $file->getSize();
            }
        }
    } catch ( Exception $e ) {
        error_log( '[CSC] health dir_size error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- filesystem error logging
    }
    return $size;
}

/**
 * Return total size (bytes) of all autoloaded wp_options rows.
 */
function cscc_get_autoload_size(): int {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    return (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload NOT IN ('no','off')" );
}

/**
 * RAG status for autoload size.
 */
function cscc_autoload_rag( int $bytes ): string {
    if ( $bytes > 2 * MB_IN_BYTES )   return 'red';
    if ( $bytes > 800 * KB_IN_BYTES ) return 'amber';
    return 'green';
}

/**
 * Get free disk space on the partition containing wp-content.
 */
function cscc_health_get_disk_free_bytes(): int {
    $free = @disk_free_space( WP_CONTENT_DIR );
    return $free !== false ? intval( $free ) : 0;
}

/**
 * Get total disk space on the partition containing wp-content.
 */
function cscc_health_get_disk_total_bytes(): int {
    $total = @disk_total_space( WP_CONTENT_DIR );
    return $total !== false ? intval( $total ) : 0;
}

/**
 * Get current CPU load average (1 min).
 * Uses sys_getloadavg() on Linux, falls back to /proc/loadavg.
 */
function cscc_health_get_cpu_load(): float {
    if ( function_exists( 'sys_getloadavg' ) ) {
        $load = @sys_getloadavg();
        if ( is_array( $load ) && isset( $load[0] ) ) {
            return round( floatval( $load[0] ), 2 );
        }
    }
    // Fallback: read /proc/loadavg
    if ( is_readable( '/proc/loadavg' ) ) {
        $raw = @file_get_contents( '/proc/loadavg' );
        if ( $raw !== false ) {
            $parts = explode( ' ', trim( $raw ) );
            return round( floatval( $parts[0] ), 2 );
        }
    }
    return -1; // unavailable
}

/**
 * Get current memory usage in bytes.
 * Reads /proc/meminfo on Linux, falls back to shell free command.
 */
function cscc_health_get_memory_used_bytes(): int {
    // Linux: parse /proc/meminfo
    if ( is_readable( '/proc/meminfo' ) ) {
        $raw = @file_get_contents( '/proc/meminfo' );
        if ( $raw !== false ) {
            $mem_total    = 0;
            $mem_free     = 0;
            $mem_buffers  = 0;
            $mem_cached   = 0;
            foreach ( explode( "\n", $raw ) as $line ) {
                if ( preg_match( '/^MemTotal:\s+(\d+)\s+kB/', $line, $m ) )  { $mem_total   = intval( $m[1] ) * 1024; }
                if ( preg_match( '/^MemFree:\s+(\d+)\s+kB/', $line, $m ) )   { $mem_free    = intval( $m[1] ) * 1024; }
                if ( preg_match( '/^Buffers:\s+(\d+)\s+kB/', $line, $m ) )   { $mem_buffers = intval( $m[1] ) * 1024; }
                if ( preg_match( '/^Cached:\s+(\d+)\s+kB/', $line, $m ) )    { $mem_cached  = intval( $m[1] ) * 1024; }
            }
            if ( $mem_total > 0 ) {
                return $mem_total - $mem_free - $mem_buffers - $mem_cached;
            }
        }
    }
    return -1; // unavailable
}

/**
 * Get total system memory in bytes.
 */
function cscc_health_get_memory_total_bytes(): int {
    if ( is_readable( '/proc/meminfo' ) ) {
        $raw = @file_get_contents( '/proc/meminfo' );
        if ( $raw !== false && preg_match( '/^MemTotal:\s+(\d+)\s+kB/m', $raw, $m ) ) {
            return intval( $m[1] ) * 1024;
        }
    }
    return -1;
}

/**
 * Find the sar binary path. Apache's PATH is often restricted, so we check common locations.
 */
/**
 * Get the system timezone for sar queries. WordPress may override PHP's timezone
 * via date_default_timezone_set(), but sar uses the OS timezone. We need to match.
 */
function cscc_health_system_time( string $fmt, int $ts = 0 ): string {
    static $sys_tz = null;
    if ( $sys_tz === null ) {
        // Read system timezone, not WordPress timezone
        $tz_file = @file_get_contents( '/etc/timezone' );
        if ( $tz_file ) {
            $sys_tz = trim( $tz_file );
        } else {
            $link = @readlink( '/etc/localtime' );
            if ( $link && preg_match( '#zoneinfo/(.+)$#', $link, $m ) ) {
                $sys_tz = $m[1];
            } else {
                $sys_tz = 'UTC';
            }
        }
    }
    $old_tz = date_default_timezone_get();
    date_default_timezone_set( $sys_tz ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- required to display server timezone info in system health; timezone is restored immediately after
    $result = gmdate( $fmt, $ts ?: time() );
    date_default_timezone_set( $old_tz ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- required to display server timezone info in system health; timezone is restored immediately after
    return $result;
}

function cscc_health_find_sar(): string {
    static $cached = null;
    if ( $cached !== null ) { return $cached; }
    $paths = array( '/usr/bin/sar', '/usr/sbin/sar', '/usr/local/bin/sar' );
    foreach ( $paths as $p ) {
        if ( @is_executable( $p ) ) { $cached = $p; return $p; }
    }
    $cached = '';
    return '';
}

/**
 * Get the number of CPU cores.
 */
function cscc_health_get_cpu_count(): int {
    if ( is_readable( '/proc/cpuinfo' ) ) {
        $raw = @file_get_contents( '/proc/cpuinfo' );
        if ( $raw !== false ) {
            return max( 1, substr_count( $raw, 'processor' ) );
        }
    }
    return 1;
}

/**
 * Get MAX CPU usage percentage over the last hour.
 *
 * Uses sar (sysstat) if available to read per minute samples from the last 60 minutes
 * and returns the highest CPU% seen. This captures spikes that a single point in time
 * snapshot would miss. Falls back to instantaneous load average conversion if sar
 * is not installed.
 *
 * sar -u output lines look like:
 *   14:01:01  all  0.50  0.00  0.25  0.00  0.00  99.25
 * Columns: time, CPU, %user, %nice, %system, %iowait, %steal, %idle
 * CPU% = 100 - %idle
 */
function cscc_health_get_cpu_pct(): float {
    $load = cscc_health_get_cpu_load();
    if ( $load < 0 ) { return -1; }
    $cpus = cscc_health_get_cpu_count();
    return round( min( 100, ( $load / $cpus ) * 100 ), 1 );
}

/**
 * Get MAX memory usage percentage over the last hour.
 *
 * Uses sar -r (sysstat) if available to read per minute memory samples and returns
 * the highest memory% seen. Falls back to instantaneous /proc/meminfo reading.
 *
 * sar -r output lines look like:
 *   14:01:01  1024000  512000  50.00  128000  384000  ...
 * Columns: time, kbmemfree, kbavail, %memused, kbbuffers, kbcached, ...
 * We use the %memused column directly.
 */
function cscc_health_get_mem_pct(): float {
    $used  = cscc_health_get_memory_used_bytes();
    $total = cscc_health_get_memory_total_bytes();
    if ( $used < 0 || $total <= 0 ) { return -1; }
    return round( ( $used / $total ) * 100, 1 );
}

/**
 * Get database size in bytes.
 */
function cscc_health_get_db_size_bytes(): int {
    global $wpdb;
    $db_name = DB_NAME;
    $row = $wpdb->get_row( $wpdb->prepare(
        'SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = %s',
        $db_name
    ) );
    return ( $row && $row->size ) ? intval( $row->size ) : 0;
}

// ─── Hourly metric collection cron ───────────────────────────────────────────

add_action( 'cscc_health_hourly_collect', 'cscc_health_collect_hourly' );
function cscc_health_collect_hourly() {
    try {
        $metrics = get_option( CSCC_HEALTH_HOURLY_KEY, array() );

        $cpu_pct      = cscc_health_get_cpu_pct();
        $mem_pct      = cscc_health_get_mem_pct();
        $max_resource = max( $cpu_pct, $mem_pct );

        $entry = array(
            'ts'               => current_time( 'mysql' ),
            'ts_unix'          => time(),
            'disk_used'        => cscc_health_get_disk_usage_bytes(),
            'disk_free'        => cscc_health_get_disk_free_bytes(),
            'cpu_load'         => cscc_health_get_cpu_load(),
            'cpu_pct'          => $cpu_pct,
            'mem_used'         => cscc_health_get_memory_used_bytes(),
            'mem_total'        => cscc_health_get_memory_total_bytes(),
            'mem_pct'          => $mem_pct,
            'max_resource_pct' => $max_resource,
            'source'           => 'snapshot',
            'db_size'          => cscc_health_get_db_size_bytes(),
        );

        $metrics[] = $entry;

        // Expire entries older than 6 months
        $cutoff = time() - ( CSCC_HEALTH_MAX_AGE * DAY_IN_SECONDS );
        $metrics = array_values( array_filter( $metrics, function( $m ) use ( $cutoff ) {
            return isset( $m['ts_unix'] ) && $m['ts_unix'] >= $cutoff;
        } ) );

        update_option( CSCC_HEALTH_HOURLY_KEY, $metrics, false ); // autoload=false (can be large)
    } catch ( \Throwable $e ) {
        error_log( sprintf( '[CSC] cron "cscc_health_hourly_collect" exception (%s): %s in %s line %d', get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational cron logging
    }
}

// ─── Weekly disk snapshot cron ───────────────────────────────────────────────

add_action( 'cscc_health_weekly_snapshot', 'cscc_health_collect_weekly' );
function cscc_health_collect_weekly() {
    try {
        $snapshots = get_option( CSCC_HEALTH_WEEKLY_KEY, array() );

        $snapshots[] = array(
            'ts'         => current_time( 'mysql' ),
            'ts_unix'    => time(),
            'disk_used'  => cscc_health_get_disk_usage_bytes(),
            'disk_free'  => cscc_health_get_disk_free_bytes(),
            'disk_total' => cscc_health_get_disk_total_bytes(),
            'db_size'    => cscc_health_get_db_size_bytes(),
        );

        // Expire entries older than 6 months
        $cutoff    = time() - ( CSCC_HEALTH_MAX_AGE * DAY_IN_SECONDS );
        $snapshots = array_values( array_filter( $snapshots, function( $s ) use ( $cutoff ) {
            return isset( $s['ts_unix'] ) && $s['ts_unix'] >= $cutoff;
        } ) );

        update_option( CSCC_HEALTH_WEEKLY_KEY, $snapshots, false );
    } catch ( \Throwable $e ) {
        error_log( sprintf( '[CSC] cron "cscc_health_weekly_snapshot" exception (%s): %s in %s line %d', get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational cron logging
    }
}

// ─── Schedule health crons on activation ─────────────────────────────────────

register_activation_hook( __FILE__, 'cscc_health_schedule_crons' );
add_action( 'admin_init', 'cscc_health_ensure_crons' );

function cscc_health_schedule_crons() {
    if ( ! wp_next_scheduled( 'cscc_health_hourly_collect' ) ) {
        wp_schedule_event( time(), 'hourly', 'cscc_health_hourly_collect' );
    }
    if ( ! wp_next_scheduled( 'cscc_health_weekly_snapshot' ) ) {
        wp_schedule_event( time(), 'weekly', 'cscc_health_weekly_snapshot' );
    }
}

function cscc_health_ensure_crons() {
    if ( ! wp_next_scheduled( 'cscc_health_hourly_collect' ) ) {
        wp_schedule_event( time(), 'hourly', 'cscc_health_hourly_collect' );
    }
    if ( ! wp_next_scheduled( 'cscc_health_weekly_snapshot' ) ) {
        wp_schedule_event( time(), 'weekly', 'cscc_health_weekly_snapshot' );
    }
}

// Register 'weekly' interval (WordPress doesn't have one by default)
add_filter( 'cron_schedules', 'cscc_health_cron_schedules' );
function cscc_health_cron_schedules( $schedules ) {
    if ( ! isset( $schedules['weekly'] ) ) {
        $schedules['weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => 'Once Weekly',
        );
    }
    return $schedules;
}

// ─── Health score calculation ────────────────────────────────────────────────

/**
 * Calculate site health summary.
 * Returns array with disk, cpu, memory stats and RAG status.
 */
function cscc_health_calculate(): array {
    $hourly    = get_option( CSCC_HEALTH_HOURLY_KEY, array() );
    $weekly    = get_option( CSCC_HEALTH_WEEKLY_KEY, array() );
    $now       = time();

    // Current disk state
    $disk_used  = cscc_health_get_disk_usage_bytes();
    $disk_free  = cscc_health_get_disk_free_bytes();
    $disk_total = cscc_health_get_disk_total_bytes();
    $db_size    = cscc_health_get_db_size_bytes();

    // Weekly growth rate: max disk_used across any rolling 7 day window over last 3 months
    // Then compare oldest vs newest weekly snapshot
    $growth_per_week = 0;
    $weeks_of_data   = 0;
    if ( count( $weekly ) >= 2 ) {
        // Use the 3 month window (13 weeks) for growth calculation
        $three_months_ago = $now - ( 90 * DAY_IN_SECONDS );
        $recent = array_filter( $weekly, function( $s ) use ( $three_months_ago ) {
            return $s['ts_unix'] >= $three_months_ago;
        } );
        $recent = array_values( $recent );

        if ( count( $recent ) >= 2 ) {
            $oldest = $recent[0];
            $newest = end( $recent );
            $elapsed_weeks = max( 1, ( $newest['ts_unix'] - $oldest['ts_unix'] ) / WEEK_IN_SECONDS );
            $weeks_of_data = round( $elapsed_weeks, 1 );

            // Find the maximum disk_used in any weekly snapshot (worst case growth)
            $max_used = 0;
            foreach ( $recent as $s ) {
                if ( $s['disk_used'] > $max_used ) { $max_used = $s['disk_used']; }
            }

            // Growth = (max_used - oldest) / elapsed weeks
            // This captures worst case peak, not just current
            $total_growth = $max_used - $oldest['disk_used'];
            if ( $total_growth > 0 ) {
                $growth_per_week = $total_growth / $elapsed_weeks;
            }
        }
    }

    // Weeks remaining until disk full
    $weeks_remaining = -1; // -1 = insufficient data
    if ( $growth_per_week > 0 && $disk_free > 0 ) {
        $weeks_remaining = $disk_free / $growth_per_week;
    }

    // RAG status for disk
    $disk_rag = 'grey'; // insufficient data
    if ( $weeks_remaining > 0 ) {
        if ( $weeks_remaining < 13 ) {       // < 3 months
            $disk_rag = 'red';
        } elseif ( $weeks_remaining < 26 ) { // < 6 months
            $disk_rag = 'amber';
        } else {
            $disk_rag = 'green';
        }
    } elseif ( count( $weekly ) >= 2 && $growth_per_week <= 0 ) {
        // Disk is shrinking or stable, green
        $disk_rag = 'green';
    }

    // CPU and Memory: max percentages over last 24h and 7d
    $cpu_max_24h     = -1;
    $cpu_max_7d      = -1;
    $cpu_pct_max_24h = -1;
    $cpu_pct_max_7d  = -1;
    $mem_max_24h     = -1;
    $mem_max_7d      = -1;
    $mem_pct_max_24h = -1;
    $mem_pct_max_7d  = -1;
    $max_res_24h     = -1;
    $max_res_7d      = -1;
    $cutoff_24h      = $now - DAY_IN_SECONDS;
    $cutoff_7d       = $now - WEEK_IN_SECONDS;
    $mem_total       = cscc_health_get_memory_total_bytes();

    foreach ( $hourly as $h ) {
        $in_24h = isset( $h['ts_unix'] ) && $h['ts_unix'] >= $cutoff_24h;
        $in_7d  = isset( $h['ts_unix'] ) && $h['ts_unix'] >= $cutoff_7d;

        // CPU load average (raw)
        if ( isset( $h['cpu_load'] ) && $h['cpu_load'] >= 0 ) {
            if ( $in_24h && $h['cpu_load'] > $cpu_max_24h ) { $cpu_max_24h = $h['cpu_load']; }
            if ( $in_7d  && $h['cpu_load'] > $cpu_max_7d )  { $cpu_max_7d  = $h['cpu_load']; }
        }
        // CPU percentage
        if ( isset( $h['cpu_pct'] ) && $h['cpu_pct'] >= 0 ) {
            if ( $in_24h && $h['cpu_pct'] > $cpu_pct_max_24h ) { $cpu_pct_max_24h = $h['cpu_pct']; }
            if ( $in_7d  && $h['cpu_pct'] > $cpu_pct_max_7d )  { $cpu_pct_max_7d  = $h['cpu_pct']; }
        }
        // Memory bytes (raw)
        if ( isset( $h['mem_used'] ) && $h['mem_used'] >= 0 ) {
            if ( $in_24h && $h['mem_used'] > $mem_max_24h ) { $mem_max_24h = $h['mem_used']; }
            if ( $in_7d  && $h['mem_used'] > $mem_max_7d )  { $mem_max_7d  = $h['mem_used']; }
        }
        // Memory percentage
        if ( isset( $h['mem_pct'] ) && $h['mem_pct'] >= 0 ) {
            if ( $in_24h && $h['mem_pct'] > $mem_pct_max_24h ) { $mem_pct_max_24h = $h['mem_pct']; }
            if ( $in_7d  && $h['mem_pct'] > $mem_pct_max_7d )  { $mem_pct_max_7d  = $h['mem_pct']; }
        }
        // Max resource percentage (whichever is higher: cpu or memory)
        if ( isset( $h['max_resource_pct'] ) && $h['max_resource_pct'] >= 0 ) {
            if ( $in_24h && $h['max_resource_pct'] > $max_res_24h ) { $max_res_24h = $h['max_resource_pct']; }
            if ( $in_7d  && $h['max_resource_pct'] > $max_res_7d )  { $max_res_7d  = $h['max_resource_pct']; }
        }
    }

    return array(
        'disk_used'          => $disk_used,
        'disk_free'          => $disk_free,
        'disk_total'         => $disk_total,
        'db_size'            => $db_size,
        'growth_per_week'    => $growth_per_week,
        'weeks_remaining'    => $weeks_remaining,
        'weeks_of_data'      => $weeks_of_data,
        'disk_rag'           => $disk_rag,
        'cpu_load_now'       => cscc_health_get_cpu_load(),
        'cpu_pct_now'        => cscc_health_get_cpu_pct(),
        'cpu_max_24h'        => $cpu_max_24h,
        'cpu_max_7d'         => $cpu_max_7d,
        'cpu_pct_max_24h'    => $cpu_pct_max_24h,
        'cpu_pct_max_7d'     => $cpu_pct_max_7d,
        'mem_used_now'       => cscc_health_get_memory_used_bytes(),
        'mem_pct_now'        => cscc_health_get_mem_pct(),
        'mem_total'          => $mem_total,
        'mem_max_24h'        => $mem_max_24h,
        'mem_max_7d'         => $mem_max_7d,
        'mem_pct_max_24h'    => $mem_pct_max_24h,
        'mem_pct_max_7d'     => $mem_pct_max_7d,
        'max_resource_now'   => max( cscc_health_get_cpu_pct(), cscc_health_get_mem_pct() ),
        'max_resource_24h'   => $max_res_24h,
        'max_resource_7d'    => $max_res_7d,
        'hourly_count'       => count( $hourly ),
        'weekly_count'       => count( $weekly ),
        'last_hourly'        => ! empty( $hourly ) ? end( $hourly )['ts'] : null,
        'last_weekly'        => ! empty( $weekly ) ? end( $weekly )['ts'] : null,
    );
}

// ─── AJAX: Force collect now (for first run / testing) ───────────────────────

add_action( 'wp_ajax_cscc_health_collect_now', 'cscc_ajax_health_collect_now' );
function cscc_ajax_health_collect_now() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    cscc_health_collect_hourly();
    cscc_health_collect_weekly();
    wp_send_json_success( array( 'message' => 'Metrics collected.', 'health' => cscc_health_calculate() ) );
}

// ─── AJAX: Get health data ───────────────────────────────────────────────────

add_action( 'wp_ajax_cscc_health_get', 'cscc_ajax_health_get' );
function cscc_ajax_health_get() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    wp_send_json_success( cscc_health_calculate() );
}

// ─── AJAX: Get raw hourly data for charts ────────────────────────────────────

add_action( 'wp_ajax_cscc_health_hourly_data', 'cscc_ajax_health_hourly_data' );
function cscc_ajax_health_hourly_data() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $days    = intval( wp_unslash( $_POST['days'] ?? 7 ) );
    $days    = max( 1, min( 180, $days ) );
    $cutoff  = time() - ( $days * DAY_IN_SECONDS );
    $hourly  = get_option( CSCC_HEALTH_HOURLY_KEY, array() );
    $filtered = array_values( array_filter( $hourly, function( $h ) use ( $cutoff ) {
        return isset( $h['ts_unix'] ) && $h['ts_unix'] >= $cutoff;
    } ) );

    wp_send_json_success( array( 'data' => $filtered, 'total' => count( $filtered ) ) );
}

// ─── AJAX: Get weekly snapshots for charts ───────────────────────────────────

add_action( 'wp_ajax_cscc_health_weekly_data', 'cscc_ajax_health_weekly_data' );
function cscc_ajax_health_weekly_data() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }
    wp_send_json_success( array( 'data' => get_option( CSCC_HEALTH_WEEKLY_KEY, array() ) ) );
}

// ─── AJAX: Test sysstat availability ─────────────────────────────────────────

add_action( 'wp_ajax_cscc_health_sysstat_test', 'cscc_ajax_health_sysstat_test' );
function cscc_ajax_health_sysstat_test() {
    check_ajax_referer( 'cscc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    wp_send_json_success( array(
        'source'      => 'snapshot',
        'cpu_count'   => cscc_health_get_cpu_count(),
        'cpu_pct_now' => cscc_health_get_cpu_pct(),
        'mem_pct_now' => cscc_health_get_mem_pct(),
    ) );
}

// ─── Cron Management ─────────────────────────────────────────────────────────

// ── Cron execution timing ────────────────────────────────────────────────────

add_action( 'init', 'cscc_cron_register_timing_hooks' );
/**
 * During a real cron run, add before/after hooks to every scheduled job
 * so we can record how long each one took.
 *
 * @since 2.5.39
 * @return void
 */
function cscc_cron_register_timing_hooks() {
	if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
		return;
	}
	$cron_array = _get_cron_array(); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	if ( ! is_array( $cron_array ) ) {
		return;
	}
	$hooks = array();
	foreach ( $cron_array as $jobs ) {
		foreach ( array_keys( $jobs ) as $hook ) {
			$hooks[ $hook ] = true;
		}
	}
	foreach ( array_keys( $hooks ) as $hook ) {
		add_action( $hook, 'cscc_cron_time_start', -9999, 0 );
		add_action( $hook, 'cscc_cron_time_end',    9999, 0 );
	}
}

/**
 * Records the start time for a cron hook execution.
 *
 * @since 2.5.39
 * @return void
 */
function cscc_cron_time_start() {
	$GLOBALS['cscc_cron_t'][ current_action() ] = microtime( true );
}

/**
 * Records duration for a cron hook execution and persists to the run log.
 *
 * @since 2.5.39
 * @return void
 */
function cscc_cron_time_end() {
	$hook = current_action();
	if ( ! isset( $GLOBALS['cscc_cron_t'][ $hook ] ) ) {
		return;
	}
	$ms  = (int) round( ( microtime( true ) - $GLOBALS['cscc_cron_t'][ $hook ] ) * 1000 );
	$log = get_option( 'cscc_cron_run_log', array() );
	$log[ $hook ] = array(
		'last_run'    => time(),
		'duration_ms' => $ms,
	);
	if ( count( $log ) > 300 ) {
		uasort( $log, function ( $a, $b ) { return $b['last_run'] - $a['last_run']; } );
		$log = array_slice( $log, 0, 300, true );
	}
	update_option( 'cscc_cron_run_log', $log, false );
}

// ── Cron delete / restore / purge ────────────────────────────────────────────

add_action( 'wp_ajax_cscc_cron_delete', 'cscc_ajax_cron_delete' );
/**
 * Moves all instances of a scheduled cron hook into the recycle bin.
 *
 * @since 2.5.39
 * @return void
 */
function cscc_ajax_cron_delete() {
	check_ajax_referer( 'cscc_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Insufficient permissions.' );
	}

	$hook = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
	if ( empty( $hook ) ) {
		wp_send_json_error( 'Missing hook name.' );
	}

	$cron_array = _get_cron_array(); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	$saved      = array();

	foreach ( (array) $cron_array as $timestamp => $hooks ) {
		if ( isset( $hooks[ $hook ] ) ) {
			foreach ( $hooks[ $hook ] as $data ) {
				$saved[] = array(
					'hook'       => $hook,
					'args'       => isset( $data['args'] ) ? $data['args'] : array(),
					'schedule'   => isset( $data['schedule'] ) ? $data['schedule'] : false,
					'interval'   => isset( $data['interval'] ) ? (int) $data['interval'] : 0,
					'next_run'   => (int) $timestamp,
					'deleted_at' => time(),
					'id'         => wp_generate_uuid4(),
				);
			}
		}
	}

	if ( empty( $saved ) ) {
		wp_send_json_error( 'Hook not found in cron schedule.' );
	}

	$bin = get_option( 'cscc_cron_recycle_bin', array() );
	$bin = array_merge( $bin, $saved );
	if ( count( $bin ) > 200 ) {
		$bin = array_slice( $bin, - 200 );
	}
	update_option( 'cscc_cron_recycle_bin', $bin );

	foreach ( $saved as $entry ) {
		wp_clear_scheduled_hook( $entry['hook'], $entry['args'] );
	}

	wp_send_json_success( array( 'deleted' => count( $saved ), 'hook' => $hook ) );
}

add_action( 'wp_ajax_cscc_cron_restore', 'cscc_ajax_cron_restore' );
/**
 * Restores a cron entry from the recycle bin back into the WP cron schedule.
 *
 * @since 2.5.39
 * @return void
 */
function cscc_ajax_cron_restore() {
	check_ajax_referer( 'cscc_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Insufficient permissions.' );
	}

	$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
	if ( empty( $id ) ) {
		wp_send_json_error( 'Missing entry ID.' );
	}

	$bin     = get_option( 'cscc_cron_recycle_bin', array() );
	$entry   = null;
	$bin_new = array();

	foreach ( $bin as $item ) {
		if ( isset( $item['id'] ) && $item['id'] === $id ) {
			$entry = $item;
		} else {
			$bin_new[] = $item;
		}
	}

	if ( ! $entry ) {
		wp_send_json_error( 'Entry not found in recycle bin.' );
	}

	$next = max( (int) $entry['next_run'], time() + 30 );

	if ( ! empty( $entry['schedule'] ) && $entry['interval'] > 0 ) {
		wp_schedule_event( $next, $entry['schedule'], $entry['hook'], (array) $entry['args'] );
	} else {
		wp_schedule_single_event( $next, $entry['hook'], (array) $entry['args'] );
	}

	update_option( 'cscc_cron_recycle_bin', array_values( $bin_new ) );
	wp_send_json_success( array( 'restored' => $entry['hook'] ) );
}

add_action( 'wp_ajax_cscc_cron_purge_bin', 'cscc_ajax_cron_purge_bin' );
/**
 * Permanently removes a single entry from the cron recycle bin.
 *
 * @since 2.5.39
 * @return void
 */
function cscc_ajax_cron_purge_bin() {
	check_ajax_referer( 'cscc_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Insufficient permissions.' );
	}

	$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
	if ( empty( $id ) ) {
		wp_send_json_error( 'Missing entry ID.' );
	}

	$bin     = get_option( 'cscc_cron_recycle_bin', array() );
	$found   = false;
	$bin_new = array();

	foreach ( $bin as $item ) {
		if ( isset( $item['id'] ) && $item['id'] === $id ) {
			$found = true;
		} else {
			$bin_new[] = $item;
		}
	}

	if ( ! $found ) {
		wp_send_json_error( 'Entry not found.' );
	}

	update_option( 'cscc_cron_recycle_bin', array_values( $bin_new ) );
	wp_send_json_success( array( 'purged' => $id ) );
}

// ── Plugin lookup for cron hooks ─────────────────────────────────────────────

/**
 * Is a plugin with this directory slug installed?
 *
 * Asks WordPress for its own inventory of installed plugins rather than testing a path built
 * from the plugins-directory constant. Two reasons: that constant misses installs where the
 * plugins directory has been relocated, and WordPress.org rejects building paths from it.
 *
 * Both callers are admin-only diagnostic screens, where get_plugins() is loaded. Returns null
 * rather than false if it is somehow unavailable, so a caller can tell "not installed" from
 * "could not determine" instead of mislabelling one as the other.
 *
 * @since 2.5.40
 * @param string $slug Plugin directory name.
 * @return bool|null True installed, false not installed, null indeterminable.
 */
function cscc_plugin_slug_installed( $slug ) {
	$slug = (string) $slug;
	if ( '' === $slug || ! function_exists( 'get_plugins' ) ) {
		return null;
	}
	static $slugs = null;
	if ( null === $slugs ) {
		$slugs = array();
		foreach ( array_keys( (array) get_plugins() ) as $plugin_file ) {
			$dir = strtok( (string) $plugin_file, '/' );
			if ( $dir && $dir !== $plugin_file ) {
				$slugs[ $dir ] = true;
			}
		}
	}
	return isset( $slugs[ $slug ] );
}

/**
 * Maps a cron hook name to its origin plugin via prefix matching.
 * Rules are sorted longest-prefix-first so more specific entries win.
 *
 * @since 2.5.39
 * @param string $hook Cron hook name.
 * @return array|null ['n' => name, 's' => slug, 'c' => is_core] or null.
 */
function cscc_resolve_cron_hook_plugin( $hook ) {
	static $rules = null;
	if ( null === $rules ) {
		// Each entry: 'p' = prefix, 'n' = display name, 's' = plugin slug (dir), 'c' = WP core
		$raw = array(
			// ── WordPress Core ────────────────────────────────────────────────
			array( 'p' => 'wp_site_health_scheduled_check',      'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_privacy_delete_old_export_files',  'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_delete_temp_updater_backups',      'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_scheduled_auto_draft_delete',      'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'recovery_mode_clean_expired_keys',    'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'delete_expired_transients',           'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_scheduled_delete',                 'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_update_user_counts',               'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_version_check',                    'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_update_plugins',                   'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_update_themes',                    'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_delete_',                          'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_update_',                          'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_privacy_',                         'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_scheduled_',                       'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			array( 'p' => 'wp_site_health_',                     'n' => 'WordPress Core',            's' => '',                                          'c' => true ),
			// ── CloudScale (this plugin family) ──────────────────────────────
			array( 'p' => 'cscc_',                                'n' => 'CloudScale Cleanup',        's' => 'cloudscale-cleanup',                        'c' => false ),
			array( 'p' => 'cspj_',                               'n' => 'CloudScale Post Janitor',   's' => 'cloudscale-post-janitor',                   'c' => false ),
			array( 'p' => 'cs_seo_',                             'n' => 'CloudScale SEO',            's' => 'cloudscale-seo',                            'c' => false ),
			array( 'p' => 'cs_scheduled_ami_backup',             'n' => 'CloudScale Backup & Restore', 's' => 'cloudscale-backup',                       'c' => false ),
			array( 'p' => 'cs_scheduled_backup',                 'n' => 'CloudScale Backup & Restore', 's' => 'cloudscale-backup',                       'c' => false ),
			array( 'p' => 'cs_backup_',                          'n' => 'CloudScale Backup & Restore', 's' => 'cloudscale-backup',                       'c' => false ),
			array( 'p' => 'cs_',                                 'n' => 'CloudScale',                's' => 'cloudscale',                                'c' => false ),
			// ── WooCommerce family ────────────────────────────────────────────
			array( 'p' => 'woocommerce_subscriptions_',          'n' => 'WC Subscriptions',          's' => 'woocommerce-subscriptions',                 'c' => false ),
			array( 'p' => 'wc_bookings_',                        'n' => 'WC Bookings',               's' => 'woocommerce-bookings',                      'c' => false ),
			array( 'p' => 'wc_stripe_',                          'n' => 'WC Stripe Gateway',         's' => 'woocommerce-gateway-stripe',                'c' => false ),
			array( 'p' => 'woocommerce_',                        'n' => 'WooCommerce',               's' => 'woocommerce',                               'c' => false ),
			array( 'p' => 'wcpay_',                              'n' => 'WooCommerce Payments',      's' => 'woocommerce-payments',                      'c' => false ),
			array( 'p' => 'wcs_',                                'n' => 'WC Subscriptions',          's' => 'woocommerce-subscriptions',                 'c' => false ),
			array( 'p' => 'wc_',                                 'n' => 'WooCommerce',               's' => 'woocommerce',                               'c' => false ),
			// ── SEO ───────────────────────────────────────────────────────────
			array( 'p' => 'wpseo',                               'n' => 'Yoast SEO',                 's' => 'wordpress-seo',                             'c' => false ),
			array( 'p' => 'aioseo_',                             'n' => 'All in One SEO',            's' => 'all-in-one-seo-pack',                       'c' => false ),
			array( 'p' => 'aioseop_',                            'n' => 'All in One SEO',            's' => 'all-in-one-seo-pack',                       'c' => false ),
			array( 'p' => 'rank_math_',                          'n' => 'Rank Math SEO',             's' => 'seo-by-rank-math',                          'c' => false ),
			array( 'p' => 'rankmath_',                           'n' => 'Rank Math SEO',             's' => 'seo-by-rank-math',                          'c' => false ),
			array( 'p' => 'seopress_',                           'n' => 'SEOPress',                  's' => 'wp-seopress',                               'c' => false ),
			array( 'p' => 'the_seo_framework_',                  'n' => 'The SEO Framework',         's' => 'autodescription',                           'c' => false ),
			array( 'p' => 'slim_seo_',                           'n' => 'Slim SEO',                  's' => 'slim-seo',                                  'c' => false ),
			array( 'p' => 'smartcrawl_',                         'n' => 'SmartCrawl SEO',            's' => 'smartcrawl-seo',                            'c' => false ),
			// ── Security ──────────────────────────────────────────────────────
			array( 'p' => 'wordfence',                           'n' => 'Wordfence Security',        's' => 'wordfence',                                 'c' => false ),
			array( 'p' => 'wfls_',                               'n' => 'Wordfence Security',        's' => 'wordfence',                                 'c' => false ),
			array( 'p' => 'itsec_',                              'n' => 'Solid Security',            's' => 'better-wp-security',                        'c' => false ),
			array( 'p' => 'solid_security_',                     'n' => 'Solid Security',            's' => 'solid-security',                            'c' => false ),
			array( 'p' => 'sucuriscan_',                         'n' => 'Sucuri Security',           's' => 'sucuri-scanner',                            'c' => false ),
			array( 'p' => 'aio_wp_security_',                    'n' => 'All In One WP Security',    's' => 'all-in-one-wp-security-and-firewall',       'c' => false ),
			array( 'p' => 'cerber_',                             'n' => 'WP Cerber Security',        's' => 'wp-cerber',                                 'c' => false ),
			array( 'p' => 'wp_defender_',                        'n' => 'Defender Security',         's' => 'defender-security',                         'c' => false ),
			array( 'p' => 'rsssl_',                              'n' => 'Really Simple SSL',         's' => 'really-simple-ssl',                         'c' => false ),
			// ── Backup ────────────────────────────────────────────────────────
			array( 'p' => 'updraftplus_',                        'n' => 'UpdraftPlus',               's' => 'updraftplus',                               'c' => false ),
			array( 'p' => 'updraft_',                            'n' => 'UpdraftPlus',               's' => 'updraftplus',                               'c' => false ),
			array( 'p' => 'backwpup_',                           'n' => 'BackWPup',                  's' => 'backwpup',                                  'c' => false ),
			array( 'p' => 'duplicator_',                         'n' => 'Duplicator',                's' => 'duplicator',                                'c' => false ),
			array( 'p' => 'it_backup_',                          'n' => 'BackupBuddy',               's' => 'backupbuddy',                               'c' => false ),
			array( 'p' => 'it_storage_',                         'n' => 'BackupBuddy',               's' => 'backupbuddy',                               'c' => false ),
			array( 'p' => 'vaultpress_',                         'n' => 'VaultPress / Jetpack',      's' => 'vaultpress',                                'c' => false ),
			array( 'p' => 'as3cf_',                              'n' => 'WP Offload Media',          's' => 'amazon-s3-and-cloudfront',                  'c' => false ),
			// ── Caching / Performance ─────────────────────────────────────────
			array( 'p' => 'litespeed_',                          'n' => 'LiteSpeed Cache',           's' => 'litespeed-cache',                           'c' => false ),
			array( 'p' => 'wphb_',                               'n' => 'Hummingbird',               's' => 'hummingbird-performance',                   'c' => false ),
			array( 'p' => 'rocket_',                             'n' => 'WP Rocket',                 's' => 'wp-rocket',                                 'c' => false ),
			array( 'p' => 'autoptimize_',                        'n' => 'Autoptimize',               's' => 'autoptimize',                               'c' => false ),
			array( 'p' => 'wpfc_',                               'n' => 'WP Fastest Cache',          's' => 'wp-fastest-cache',                          'c' => false ),
			array( 'p' => 'w3tc_',                               'n' => 'W3 Total Cache',            's' => 'w3-total-cache',                            'c' => false ),
			array( 'p' => 'w3_',                                 'n' => 'W3 Total Cache',            's' => 'w3-total-cache',                            'c' => false ),
			array( 'p' => 'wp_cache_',                           'n' => 'WP Super Cache',            's' => 'wp-super-cache',                            'c' => false ),
			array( 'p' => 'rediscache_',                         'n' => 'Redis Object Cache',        's' => 'redis-cache',                               'c' => false ),
			// ── Image Optimisation ────────────────────────────────────────────
			array( 'p' => 'imagify_',                            'n' => 'Imagify',                   's' => 'imagify',                                   'c' => false ),
			array( 'p' => 'ewww_image_',                         'n' => 'EWWW Image Optimizer',      's' => 'ewww-image-optimizer',                      'c' => false ),
			array( 'p' => 'ewwwio_',                             'n' => 'EWWW Image Optimizer',      's' => 'ewww-image-optimizer',                      'c' => false ),
			array( 'p' => 'shortpixel_',                         'n' => 'ShortPixel',                's' => 'shortpixel-image-optimiser',                'c' => false ),
			array( 'p' => 'spai_',                               'n' => 'ShortPixel Adaptive Images','s' => 'shortpixel-adaptive-images',                'c' => false ),
			array( 'p' => 'wp_smush_',                           'n' => 'Smush',                     's' => 'wp-smushit',                                'c' => false ),
			array( 'p' => 'wdev_',                               'n' => 'WPMU Dev (shared logger)',  's' => 'wp-smushit',                                'c' => false ),
			// ── Analytics ─────────────────────────────────────────────────────
			array( 'p' => 'monsterinsights_',                    'n' => 'MonsterInsights',           's' => 'google-analytics-for-wordpress',            'c' => false ),
			array( 'p' => 'exactmetrics_',                       'n' => 'ExactMetrics',              's' => 'exactmetrics',                              'c' => false ),
			array( 'p' => 'googlesitekit_',                      'n' => 'Site Kit by Google',        's' => 'google-site-kit',                           'c' => false ),
			array( 'p' => 'wp_statistics_',                      'n' => 'WP Statistics',             's' => 'wp-statistics',                             'c' => false ),
			// ── Email / CRM ───────────────────────────────────────────────────
			array( 'p' => 'wp_mail_smtp_',                       'n' => 'WP Mail SMTP',              's' => 'wp-mail-smtp',                              'c' => false ),
			array( 'p' => 'postsmtp_',                           'n' => 'Post SMTP',                 's' => 'post-smtp',                                 'c' => false ),
			array( 'p' => 'mailpoet_',                           'n' => 'MailPoet',                  's' => 'mailpoet',                                  'c' => false ),
			array( 'p' => 'wysija_',                             'n' => 'MailPoet (legacy)',          's' => 'mailpoet',                                  'c' => false ),
			array( 'p' => 'newsletter_',                         'n' => 'Newsletter',                's' => 'newsletter',                                'c' => false ),
			array( 'p' => 'mc4wp_',                              'n' => 'Mailchimp for WP',          's' => 'mailchimp-for-wp',                          'c' => false ),
			array( 'p' => 'fluentcrm_',                          'n' => 'FluentCRM',                 's' => 'fluent-crm',                                'c' => false ),
			array( 'p' => 'sendinblue_',                         'n' => 'Brevo (Sendinblue)',         's' => 'mailin',                                    'c' => false ),
			array( 'p' => 'sib_',                                'n' => 'Brevo (Sendinblue)',         's' => 'mailin',                                    'c' => false ),
			array( 'p' => 'leadin_',                             'n' => 'HubSpot',                   's' => 'leadin',                                    'c' => false ),
			array( 'p' => 'activecampaign_',                     'n' => 'ActiveCampaign',            's' => 'activecampaign-subscription-forms',         'c' => false ),
			// ── Forms ─────────────────────────────────────────────────────────
			array( 'p' => 'wpcf7_',                              'n' => 'Contact Form 7',            's' => 'contact-form-7',                            'c' => false ),
			array( 'p' => 'wpforms_',                            'n' => 'WPForms',                   's' => 'wpforms-lite',                              'c' => false ),
			array( 'p' => 'gform_',                              'n' => 'Gravity Forms',             's' => 'gravityforms',                              'c' => false ),
			array( 'p' => 'ninja_forms_',                        'n' => 'Ninja Forms',               's' => 'ninja-forms',                               'c' => false ),
			array( 'p' => 'frm_',                                'n' => 'Formidable Forms',          's' => 'formidable',                                'c' => false ),
			array( 'p' => 'fluentform_',                         'n' => 'Fluent Forms',              's' => 'fluentform',                                'c' => false ),
			array( 'p' => 'fluent_form_',                        'n' => 'Fluent Forms',              's' => 'fluentform',                                'c' => false ),
			array( 'p' => 'forminator_',                         'n' => 'Forminator',                's' => 'forminator',                                'c' => false ),
			// ── Page Builders ─────────────────────────────────────────────────
			array( 'p' => 'elementor_',                          'n' => 'Elementor',                 's' => 'elementor',                                 'c' => false ),
			array( 'p' => 'fl_',                                 'n' => 'Beaver Builder',            's' => 'beaver-builder-lite-version',               'c' => false ),
			array( 'p' => 'vc_',                                 'n' => 'WPBakery Page Builder',     's' => 'js_composer',                               'c' => false ),
			array( 'p' => 'gutenberg_',                          'n' => 'Gutenberg',                 's' => 'gutenberg',                                 'c' => false ),
			// ── Membership / LMS ──────────────────────────────────────────────
			array( 'p' => 'learndash_',                          'n' => 'LearnDash LMS',             's' => 'sfwd-lms',                                  'c' => false ),
			array( 'p' => 'llms_',                               'n' => 'LifterLMS',                 's' => 'lifterlms',                                 'c' => false ),
			array( 'p' => 'mepr_',                               'n' => 'MemberPress',               's' => 'memberpress',                               'c' => false ),
			array( 'p' => 'rcp_',                                'n' => 'Restrict Content Pro',      's' => 'restrict-content',                          'c' => false ),
			array( 'p' => 'affwp_',                              'n' => 'AffiliateWP',               's' => 'affiliatewp',                               'c' => false ),
			array( 'p' => 'edd_',                                'n' => 'Easy Digital Downloads',    's' => 'easy-digital-downloads',                    'c' => false ),
			// ── Community ─────────────────────────────────────────────────────
			array( 'p' => 'bp_',                                 'n' => 'BuddyPress',                's' => 'buddypress',                                'c' => false ),
			array( 'p' => 'bbp_',                                'n' => 'bbPress',                   's' => 'bbpress',                                   'c' => false ),
			// ── Multilingual ──────────────────────────────────────────────────
			array( 'p' => 'wpml_',                               'n' => 'WPML',                      's' => 'sitepress-multilingual-cms',                'c' => false ),
			array( 'p' => 'icl_',                                'n' => 'WPML',                      's' => 'sitepress-multilingual-cms',                'c' => false ),
			array( 'p' => 'pll_',                                'n' => 'Polylang',                  's' => 'polylang',                                  'c' => false ),
			array( 'p' => 'trp_',                                'n' => 'TranslatePress',            's' => 'translatepress-multilingual',               'c' => false ),
			array( 'p' => 'weglot_',                             'n' => 'Weglot',                    's' => 'weglot',                                    'c' => false ),
			array( 'p' => 'loco_',                               'n' => 'Loco Translate',            's' => 'loco-translate',                            'c' => false ),
			// ── Events ────────────────────────────────────────────────────────
			array( 'p' => 'tribe_',                              'n' => 'The Events Calendar',       's' => 'the-events-calendar',                       'c' => false ),
			// ── Jetpack ───────────────────────────────────────────────────────
			array( 'p' => 'jetpack_',                            'n' => 'Jetpack',                   's' => 'jetpack',                                   'c' => false ),
			array( 'p' => 'akismet_',                            'n' => 'Akismet',                   's' => 'akismet',                                   'c' => false ),
			// ── Misc popular ──────────────────────────────────────────────────
			array( 'p' => 'blc_',                                'n' => 'Broken Link Checker',       's' => 'broken-link-checker',                       'c' => false ),
			array( 'p' => 'wplnst_',                             'n' => 'Broken Link Checker',       's' => 'broken-link-checker',                       'c' => false ),
			array( 'p' => 'redirection_',                        'n' => 'Redirection',               's' => 'redirection',                               'c' => false ),
			array( 'p' => 'acf_',                                'n' => 'Advanced Custom Fields',    's' => 'advanced-custom-fields',                    'c' => false ),
			array( 'p' => 'tablepress_',                         'n' => 'TablePress',                's' => 'tablepress',                                'c' => false ),
			array( 'p' => 'pum_',                                'n' => 'Popup Maker',               's' => 'popup-maker',                               'c' => false ),
			array( 'p' => 'mainwp_',                             'n' => 'MainWP',                    's' => 'mainwp',                                    'c' => false ),
			array( 'p' => 'wpmdb_',                              'n' => 'WP Migrate DB',             's' => 'wp-migrate-db',                             'c' => false ),
			array( 'p' => 'pmxi_',                               'n' => 'WP All Import',             's' => 'wp-all-import',                             'c' => false ),
			array( 'p' => 'crontrol_',                           'n' => 'WP Crontrol',               's' => 'wp-crontrol',                               'c' => false ),
			array( 'p' => 'optinmonster_',                       'n' => 'OptinMonster',              's' => 'optinmonster',                              'c' => false ),
			array( 'p' => 'optin_monster_',                      'n' => 'OptinMonster',              's' => 'optinmonster',                              'c' => false ),
			array( 'p' => 'surecart_',                           'n' => 'SureCart',                  's' => 'surecart',                                  'c' => false ),
			array( 'p' => 'sureforms_',                          'n' => 'SureForms',                 's' => 'sureforms',                                 'c' => false ),
			array( 'p' => 'action_scheduler_',                   'n' => 'Action Scheduler',          's' => 'action-scheduler',                          'c' => false ),
			array( 'p' => 'jet_',                                'n' => 'Crocoblock / JetPlugins',   's' => 'jet-engine',                                'c' => false ),
			array( 'p' => 'et_',                                 'n' => 'Divi / Elegant Themes',     's' => 'Divi',                                      'c' => false ),
			array( 'p' => 'wp_fusion_',                          'n' => 'WP Fusion',                 's' => 'wp-fusion-lite',                            'c' => false ),
			array( 'p' => 'ld_',                                 'n' => 'LearnDash LMS',             's' => 'sfwd-lms',                                  'c' => false ),
		);
		// Sort by prefix length DESC so most-specific prefix wins
		usort(
			$raw,
			function ( $a, $b ) { return strlen( $b['p'] ) - strlen( $a['p'] ); }
		);
		$rules = $raw;
	}

	foreach ( $rules as $r ) {
		if ( 0 === strncmp( $hook, $r['p'], strlen( $r['p'] ) ) ) {
			return $r;
		}
	}
	return null;
}

/**
 * Returns 'core', 'active', 'inactive', or 'not_installed' for a plugin slug.
 *
 * @since 2.5.39
 * @param string $slug Plugin directory slug.
 * @param bool   $core Whether this is a WordPress Core entry.
 * @return string
 */
function cscc_cron_plugin_status( $slug, $core = false ) {
	if ( $core ) {
		return 'core';
	}
	if ( empty( $slug ) ) {
		return 'unknown';
	}
	if ( false === cscc_plugin_slug_installed( $slug ) ) {
		return 'not_installed';
	}
	$active = (array) get_option( 'active_plugins', array() );
	foreach ( $active as $plugin_file ) {
		if ( 0 === strncmp( $plugin_file, $slug . '/', strlen( $slug ) + 1 ) ) {
			return 'active';
		}
	}
	// Also check network-active plugins on multisite
	if ( is_multisite() ) {
		$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
		foreach ( array_keys( $network_active ) as $plugin_file ) {
			if ( 0 === strncmp( $plugin_file, $slug . '/', strlen( $slug ) + 1 ) ) {
				return 'active';
			}
		}
	}
	return 'inactive';
}

add_action( 'wp_ajax_cscc_cron_status', 'cscc_ajax_cron_status' );
/**
 * Returns all scheduled cron events with 24-hour projection and congestion analysis.
 *
 * @since 2.5.1
 * @return void
 */
function cscc_ajax_cron_status() {
	check_ajax_referer( 'cscc_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Insufficient permissions.' );
	}

	$cron_array = _get_cron_array(); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	if ( ! is_array( $cron_array ) ) {
		$cron_array = array();
	}

	$now    = time();
	$end    = $now + DAY_IN_SECONDS;
	$events = array();

	foreach ( $cron_array as $timestamp => $hooks ) {
		foreach ( $hooks as $hook => $instances ) {
			foreach ( $instances as $data ) {
				$schedule = isset( $data['schedule'] ) ? $data['schedule'] : false;
				$interval = isset( $data['interval'] ) ? (int) $data['interval'] : 0;

				// Project occurrences over the next 24 hours.
				$occurrences = array();
				$next        = (int) $timestamp;

				if ( $schedule && $interval > 0 ) {
					// Advance past "now" for recurring events already overdue.
					$t = $next;
					if ( $t < $now && $interval > 0 ) {
						$steps = (int) ceil( ( $now - $t ) / $interval );
						$t    += $steps * $interval;
					}
					while ( $t <= $end ) {
						$occurrences[] = $t;
						$t            += $interval;
					}
				} elseif ( $next >= $now && $next <= $end ) {
					$occurrences[] = $next;
				}

				$events[] = array(
					'hook'        => $hook,
					'next_run'    => (int) $timestamp,
					'schedule'    => $schedule ? $schedule : 'one-time',
					'interval'    => $interval,
					'overdue'     => (int) $timestamp < $now,
					'occurrences' => $occurrences,
				);
			}
		}
	}

	// Sort by next_run ascending.
	usort(
		$events,
		function ( $a, $b ) {
			return $a['next_run'] - $b['next_run'];
		}
	);

	// Annotate each event with registered callbacks and origin plugin.
	foreach ( $events as &$ev ) {
		// Resolve registered callbacks for this hook from the global $wp_filter.
		global $wp_filter;
		$callbacks = array();
		if ( isset( $wp_filter[ $ev['hook'] ] ) ) {
			foreach ( $wp_filter[ $ev['hook'] ]->callbacks as $priority => $cbs ) {
				foreach ( $cbs as $cb ) {
					$fn = $cb['function'];
					if ( is_string( $fn ) ) {
						$callbacks[] = $fn . '()';
					} elseif ( is_array( $fn ) && count( $fn ) === 2 ) {
						$class  = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
						$callbacks[] = $class . '::' . $fn[1] . '()';
					} elseif ( $fn instanceof Closure ) {
						$ref  = new ReflectionFunction( $fn );
						$file = basename( $ref->getFileName() );
						$callbacks[] = 'Closure in ' . $file . ':' . $ref->getStartLine();
					} else {
						$callbacks[] = '{callable}';
					}
				}
			}
		}
		$ev['callbacks'] = $callbacks;

		$plugin = cscc_resolve_cron_hook_plugin( $ev['hook'] );
		if ( $plugin ) {
			$ev['plugin_name'] = $plugin['n'];

			if ( $plugin['c'] ) {
				// WordPress Core hooks are always "active".
				$ev['plugin_status'] = 'core';
			} elseif ( ! empty( $callbacks ) ) {
				// Callbacks are registered in $wp_filter, the plugin is loaded and active.
				$ev['plugin_status'] = 'active';
			} elseif ( ! empty( $plugin['s'] ) && true === cscc_plugin_slug_installed( $plugin['s'] ) ) {
				// Directory exists but no callbacks, installed but inactive or deactivated.
				$ev['plugin_status'] = 'inactive';
			} else {
				// No callbacks, no matching directory, orphaned cron entry (plugin removed).
				$ev['plugin_status'] = 'not_installed';
			}
		} else {
			$ev['plugin_name']   = null;
			// Unknown plugin: use callbacks as the signal.
			$ev['plugin_status'] = ! empty( $callbacks ) ? 'active' : 'not_installed';
		}
	}
	unset( $ev );

	// Congestion: group all occurrences into 5-minute buckets; flag buckets with 3+ jobs.
	$buckets = array();
	foreach ( $events as $ev ) {
		foreach ( $ev['occurrences'] as $t ) {
			$bucket = (int) floor( ( $t - $now ) / 300 );
			if ( ! isset( $buckets[ $bucket ] ) ) {
				$buckets[ $bucket ] = array();
			}
			$buckets[ $bucket ][] = $ev['hook'];
		}
	}

	$congestion = array();
	foreach ( $buckets as $bucket => $bucket_hooks ) {
		if ( count( $bucket_hooks ) >= 3 ) {
			$congestion[] = array(
				'offset_seconds' => $bucket * 300,
				'hooks'          => array_values( array_unique( $bucket_hooks ) ),
				'count'          => count( $bucket_hooks ),
			);
		}
	}

	$overdue_count = 0;
	foreach ( $events as $ev ) {
		if ( $ev['overdue'] ) {
			++$overdue_count;
		}
	}

	wp_send_json_success(
		array(
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_cron'   => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'server_time'      => $now,
			'events'           => $events,
			'overdue_count'    => $overdue_count,
			'congestion'       => $congestion,
			'wp_cron_url'      => site_url( '/wp-cron.php?doing_wp_cron' ),
			'run_log'          => get_option( 'cscc_cron_run_log', array() ),
			'recycle_bin'      => array_values( (array) get_option( 'cscc_cron_recycle_bin', array() ) ),
		)
	);
}

add_action( 'wp_ajax_cscc_cron_run_now', 'cscc_ajax_cron_run_now' );
/**
 * Immediately fires a specific allowed CSC cron hook.
 *
 * @since 2.5.1
 * @return void
 */
function cscc_ajax_cron_run_now() {
	check_ajax_referer( 'cscc_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Insufficient permissions.' );
	}

	$allowed = array( 'cscc_scheduled_db_cleanup', 'cscc_scheduled_img_cleanup' );
	$hook    = isset( $_POST['hook'] ) ? sanitize_key( wp_unslash( $_POST['hook'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( ! in_array( $hook, $allowed, true ) ) {
		wp_send_json_error( 'Invalid hook.' );
	}

	do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	wp_send_json_success( array( 'hook' => $hook ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// SPACE REPORT + STORAGE RECYCLE BIN
// ═════════════════════════════════════════════════════════════════════════════

define( 'CSCC_STORAGE_RECYCLE', '.csc-storage-recycle' );

/** Absolute path to the storage recycle bin directory. */
function cscc_storage_recycle_dir(): string {
	return rtrim( wp_upload_dir()['basedir'], '/\\' ) . '/' . CSCC_STORAGE_RECYCLE;
}

/** Read the recycle manifest (array keyed by item ID). */
function cscc_storage_recycle_manifest(): array {
	$path = cscc_storage_recycle_dir() . '/manifest.json';
	if ( ! file_exists( $path ) ) return [];
	$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$data = $raw ? json_decode( $raw, true ) : null;
	return is_array( $data ) ? $data : [];
}

/** Save the recycle manifest. */
function cscc_storage_recycle_save_manifest( array $manifest ): void {
	$dir = cscc_storage_recycle_dir();
	wp_mkdir_p( $dir );
	$htaccess = $dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}

add_action( 'wp_ajax_cscc_storage_recycle_folder', 'cscc_ajax_storage_recycle_folder' );
/**
 * Move a folder into the storage recycle bin.
 *
 * @since 2.6.0
 */
function cscc_ajax_storage_recycle_folder(): void {
	check_ajax_referer( 'cscc_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

	$base = rtrim( wp_upload_dir()['basedir'], '/\\' );
	$rel  = trim( sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) ), '/\\' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
	if ( ! $rel ) { wp_send_json_error( 'No path specified.' ); }
	if ( str_starts_with( $rel, CSCC_STORAGE_RECYCLE ) ) { wp_send_json_error( 'Cannot recycle the recycle bin itself.' ); }

	$real = realpath( $base . '/' . $rel );
	if ( ! $real || ! is_dir( $real ) ) { wp_send_json_error( 'Folder not found.' ); }
	if ( strncmp( $real . '/', $base . '/', strlen( $base ) + 1 ) !== 0 ) { wp_send_json_error( 'Invalid path.' ); }

	[ $size, $count ] = cscc_dir_size_and_count( $real );
	$id       = uniqid( 'sr_', true );
	$dest_dir = cscc_storage_recycle_dir() . '/' . $id;
	wp_mkdir_p( dirname( $dest_dir ) );

	if ( ! rename( $real, $dest_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		wp_send_json_error( 'Could not move folder, check permissions.' );
	}

	$manifest       = cscc_storage_recycle_manifest();
	$manifest[ $id ] = [
		'id'           => $id,
		'original_rel' => $rel,
		'original_name'=> basename( $rel ),
		'recycled_at'  => time(),
		'size'         => $size,
		'count'        => $count,
	];
	cscc_storage_recycle_save_manifest( $manifest );

	wp_send_json_success( [ 'id' => $id, 'freed' => $size ] );
}

add_action( 'wp_ajax_cscc_storage_recycle_list', 'cscc_ajax_storage_recycle_list' );
/** Return current recycle bin contents. @since 2.6.0 */
function cscc_ajax_storage_recycle_list(): void {
	check_ajax_referer( 'cscc_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
	$items = array_values( cscc_storage_recycle_manifest() );
	usort( $items, static fn( $a, $b ) => $b['recycled_at'] <=> $a['recycled_at'] );
	wp_send_json_success( $items );
}

add_action( 'wp_ajax_cscc_storage_recycle_restore', 'cscc_ajax_storage_recycle_restore' );
/** Restore a folder from the recycle bin to its original location. @since 2.6.0 */
function cscc_ajax_storage_recycle_restore(): void {
	check_ajax_referer( 'cscc_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

	$id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! $id ) { wp_send_json_error( 'Missing id.' ); }

	$manifest = cscc_storage_recycle_manifest();
	if ( ! isset( $manifest[ $id ] ) ) { wp_send_json_error( 'Item not found in recycle bin.' ); }

	$item     = $manifest[ $id ];
	$base     = rtrim( wp_upload_dir()['basedir'], '/\\' );
	$src      = cscc_storage_recycle_dir() . '/' . $id;
	$dest     = $base . '/' . $item['original_rel'];

	if ( ! is_dir( $src ) ) { wp_send_json_error( 'Recycled folder no longer exists on disk.' ); }
	if ( is_dir( $dest ) )  { wp_send_json_error( 'A folder already exists at the original location, rename or remove it first.' ); }

	wp_mkdir_p( dirname( $dest ) );
	if ( ! rename( $src, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		wp_send_json_error( 'Could not restore folder, check permissions.' );
	}

	unset( $manifest[ $id ] );
	cscc_storage_recycle_save_manifest( $manifest );
	wp_send_json_success( [] );
}

add_action( 'wp_ajax_cscc_storage_recycle_purge', 'cscc_ajax_storage_recycle_purge' );
/** Permanently delete one or all recycled folders. @since 2.6.0 */
function cscc_ajax_storage_recycle_purge(): void {
	check_ajax_referer( 'cscc_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

	$id       = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$manifest = cscc_storage_recycle_manifest();
	$ids      = $id ? [ $id ] : array_keys( $manifest );

	foreach ( $ids as $del_id ) {
		$dir = cscc_storage_recycle_dir() . '/' . $del_id;
		if ( is_dir( $dir ) ) {
			$iter = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iter as $f ) {
				$f->isDir() ? @rmdir( $f->getPathname() ) : wp_delete_file( $f->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		unset( $manifest[ $del_id ] );
	}
	cscc_storage_recycle_save_manifest( $manifest );
	wp_send_json_success( [] );
}

add_action( 'wp_ajax_cscc_space_scan', 'cscc_ajax_space_scan' );
/**
 * Return directory listing with recursive sizes for the Space Report tab.
 * Path must stay within wp-content/uploads.
 *
 * @since 2.6.0
 */
function cscc_ajax_space_scan(): void {
	check_ajax_referer( 'cscc_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

	// No request-time extension is asked for here. WordPress.org rejects the PHP call that
	// raises it outright, and this handler only READS (its single write is one transient, at
	// the very end): if it does hit the limit on a large site nothing is left half-done, the
	// request simply fails and can be retried. The write handlers are a different case.

	$upload_info = wp_upload_dir();
	$base        = rtrim( $upload_info['basedir'], '/\\' );

	$rel  = trim( sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) ), '/\\' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
	$path = $rel ? ( $base . DIRECTORY_SEPARATOR . $rel ) : $base;
	$real = realpath( $path );

	if ( ! $real || strncmp( $real . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR, strlen( $base ) + 1 ) !== 0 ) {
		wp_send_json_error( 'Invalid path.' );
	}

	$dirs  = [];
	$files = [];

	try {
		foreach ( new DirectoryIterator( $real ) as $item ) {
			if ( $item->isDot() ) continue;
			$name = $item->getFilename();
			$full = $item->getPathname();
			if ( $name === CSCC_STORAGE_RECYCLE ) continue; // hide recycle bin from listing
			if ( $item->isDir() ) {
				[ $size, $count ] = cscc_dir_size_and_count( $full );
				$dirs[] = [
					'name'  => $name,
					'rel'   => ( $rel ? $rel . '/' : '' ) . $name,
					'size'  => $size,
					'count' => $count,
				];
			} else {
				$files[] = [
					'name' => $name,
					'size' => $item->getSize(),
					'ext'  => strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ),
				];
			}
		}
	} catch ( Exception $e ) {
		wp_send_json_error( $e->getMessage() );
	}

	usort( $dirs,  static fn( $a, $b ) => $b['size'] <=> $a['size'] );
	usort( $files, static fn( $a, $b ) => $b['size'] <=> $a['size'] );

	$dir_total  = array_sum( array_column( $dirs,  'size' ) );
	$file_total = array_sum( array_column( $files, 'size' ) );

	$insights = ! $rel ? cscc_space_insights( $real, $dirs ) : [];

	// File type breakdown for the current level's direct files + recursive files when drilling in.
	$type_breakdown = [];
	$top_files      = [];
	if ( $rel ) {
		$img_exts  = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ];
		$counts    = [ 'scaled' => 0, 'original' => 0, 'other' => 0 ];
		$ext_bytes = [];
		$all_files = [];
		try {
			$all_iter = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $real, RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $all_iter as $f ) {
				if ( ! $f->isFile() ) continue;
				$size = $f->getSize();
				$ext  = strtolower( $f->getExtension() );
				$ext_bytes[ $ext ] = ( $ext_bytes[ $ext ] ?? 0 ) + $size;
				if ( in_array( $ext, $img_exts, true ) ) {
					if ( preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $f->getFilename() ) ) {
						$counts['scaled']++;
					} else {
						$counts['original']++;
					}
				} else {
					$counts['other']++;
				}
				// Collect for top-files list, store relative sub-path + size.
				$sub_rel    = ltrim( substr( (string) $f->getPathname(), strlen( $real ) ), '/\\' );
				$all_files[] = [ 'path' => $sub_rel, 'name' => $f->getFilename(), 'size' => $size, 'ext' => $ext ];
			}
		} catch ( Exception $e ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Squiz.Commenting.EmptyCatchComment.Missing -- intentional: fall through silently if operation fails

		// Sort descending by size, return top 100.
		usort( $all_files, static fn( $a, $b ) => $b['size'] <=> $a['size'] );
		$top_files = array_slice( $all_files, 0, 100 );

		arsort( $ext_bytes );
		$type_breakdown = [
			'by_ext'   => array_slice( $ext_bytes, 0, 10, true ),
			'scaled'   => $counts['scaled'],
			'original' => $counts['original'],
		];
	}

	wp_send_json_success( [
		'rel'            => $real === $base ? '' : ltrim( substr( $real, strlen( $base ) ), '/\\' ),
		'dirs'           => $dirs,
		'files'          => $files,
		'dir_total'      => $dir_total,
		'file_total'     => $file_total,
		'total'          => $dir_total + $file_total,
		'insights'       => $insights,
		'type_breakdown' => $type_breakdown,
		'top_files'      => $top_files,
	] );
}

/**
 * Analyse top-level dirs and return actionable insight objects.
 * Only called at root level, one extra scan pass per category.
 *
 * @since 2.6.0
 * @param  string $base  Absolute path to uploads root.
 * @param  array  $dirs  Already-computed top-level dir entries.
 * @return array<int,array<string,mixed>>
 */
function cscc_space_insights( string $base, array $dirs ): array {
	$insights = [];
	$by_name  = [];
	foreach ( $dirs as $d ) {
		$by_name[ $d['name'] ] = $d;
	}

	// ── 1. Duplicate geo databases ────────────────────────────────────────────
	$geo = array_values( array_filter( $dirs, static fn( $d ) =>
		preg_match( '/(^|-|_)geo$/i', $d['name'] ) && $d['count'] > 0
	) );
	if ( count( $geo ) >= 2 ) {
		$geo_total   = array_sum( array_column( $geo, 'size' ) );
		$geo_min     = min( array_column( $geo, 'size' ) );
		$geo_savings = $geo_total - $geo_min;
		$geo_names   = implode( ', ', array_column( $geo, 'name' ) );
		$insights[]  = [
			'level'   => 'error',
			'title'   => 'Duplicate Geo Databases',
			'summary' => count( $geo ) . ' folders contain the same IP geolocation database, ' . cscc_fmt_bytes( $geo_total ) . ' total, ' . cscc_fmt_bytes( $geo_savings ) . ' is redundant.',
			'detail'  => $geo_names . ' each store a copy of the same MMDB file. Only one copy is needed, the others can be deleted after confirming which plugin uses which folder.',
			'savings' => $geo_savings,
			'drills'  => array_map( static fn( $d ) => [ 'label' => $d['name'] . ' (' . cscc_fmt_bytes( $d['size'] ) . ')', 'rel' => $d['rel'] ], $geo ),
		];
	}

	// ── 2. Social formats .jpg + .jpeg duplication ────────────────────────────
	if ( isset( $by_name['social-formats'] ) ) {
		$sf_dir = $base . '/social-formats';
		$sample = null;
		try {
			foreach ( new DirectoryIterator( $sf_dir ) as $item ) {
				if ( ! $item->isDot() && $item->isDir() ) { $sample = $item->getPathname(); break; }
			}
		} catch ( Exception $e ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Squiz.Commenting.EmptyCatchComment.Missing -- intentional: fall through silently if operation fails

		if ( $sample ) {
			$jpg_bases = $jpeg_bases = [];
			try {
				foreach ( new DirectoryIterator( $sample ) as $f ) {
					if ( ! $f->isFile() ) continue;
					$ext  = strtolower( $f->getExtension() );
					$base_name = pathinfo( $f->getFilename(), PATHINFO_FILENAME );
					if ( $ext === 'jpg' )  { $jpg_bases[]  = $base_name; }
					if ( $ext === 'jpeg' ) { $jpeg_bases[] = $base_name; }
				}
			} catch ( Exception $e ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Squiz.Commenting.EmptyCatchComment.Missing -- intentional: fall through silently if operation fails

			if ( $jpg_bases && $jpeg_bases ) {
				$sf      = $by_name['social-formats'];
				$savings = intval( $sf['size'] / 2 );
				$insights[] = [
					'level'   => 'warning',
					'title'   => 'Social Formats Duplication',
					'summary' => 'social-formats/ stores both .jpg and .jpeg for every network image, roughly ' . cscc_fmt_bytes( $savings ) . ' of duplicates.',
					'detail'  => 'Each post folder contains ' . count( $jpg_bases ) . ' .jpg files AND ' . count( $jpeg_bases ) . ' .jpeg files for the same images (Facebook, Instagram, LinkedIn, Twitter, WhatsApp). One extension is entirely redundant. The social sharing plugin should be configured to generate only one format.',
					'savings' => $savings,
					'drills'  => [ [ 'label' => 'social-formats/ (' . cscc_fmt_bytes( $sf['size'] ) . ')', 'rel' => $sf['rel'] ] ],
				];
			}
		}
	}

	// ── 3. WordPress scaled image copies ─────────────────────────────────────
	$year_dirs = array_values( array_filter( $dirs, static fn( $d ) => preg_match( '/^20\d\d$/', $d['name'] ) ) );
	if ( $year_dirs ) {
		usort( $year_dirs, static fn( $a, $b ) => $b['size'] <=> $a['size'] );
		$biggest_year = $year_dirs[0];
		$img_exts     = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ];
		$total = $scaled = 0;
		try {
			$iter = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base . '/' . $biggest_year['name'], RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iter as $f ) {
				if ( ! $f->isFile() || ! in_array( strtolower( $f->getExtension() ), $img_exts, true ) ) continue;
				$total++;
				if ( preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $f->getFilename() ) ) {
					$scaled++;
				}
			}
		} catch ( Exception $e ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Squiz.Commenting.EmptyCatchComment.Missing -- intentional: fall through silently if operation fails

		if ( $total > 10 && $scaled > 0 ) {
			$pct = (int) round( $scaled / $total * 100 );
			$all_years_total = array_sum( array_column( $year_dirs, 'size' ) );
			$insights[] = [
				'level'   => 'info',
				'title'   => 'WordPress Scaled Copies',
				'summary' => $pct . '% of images in ' . $biggest_year['name'] . '/ are auto-generated size variants (' . number_format( $scaled ) . ' of ' . number_format( $total ) . ' files, ' . cscc_fmt_bytes( $all_years_total ) . ' across all years).',
				'detail'  => 'WordPress creates multiple resized versions of every upload (thumbnail, medium, large, etc). If your theme registers sizes that are never rendered in templates, those copies are wasted. Use "Regenerate Thumbnails" after removing unused size registrations, or an image cleanup tool that targets specific sizes.',
				'savings' => 0,
				'drills'  => array_map( static fn( $d ) => [ 'label' => $d['name'] . ' (' . cscc_fmt_bytes( $d['size'] ) . ')', 'rel' => $d['rel'] ], $year_dirs ),
			];
		}
	}

	return $insights;
}

/**
 * Human-readable byte size (GB / MB / KB / B).
 *
 * @since 2.6.0
 */
function cscc_fmt_bytes( int $bytes ): string {
	if ( $bytes >= 1073741824 ) return round( $bytes / 1073741824, 2 ) . ' GB';
	if ( $bytes >= 1048576 )    return round( $bytes / 1048576,    1 ) . ' MB';
	if ( $bytes >= 1024 )       return round( $bytes / 1024 )          . ' KB';
	return $bytes . ' B';
}

/**
 * Return [total_bytes, file_count] for a directory tree.
 *
 * @since 2.6.0
 * @return array{0:int,1:int}
 */
function cscc_dir_size_and_count( string $path ): array {
	$size  = 0;
	$count = 0;
	try {
		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iter as $item ) {
			if ( $item->isFile() ) {
				$size  += $item->getSize();
				++$count;
			}
		}
	} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Squiz.Commenting.EmptyCatchComment.Missing -- intentional: fall through silently if operation fails
		// skip unreadable directories
	}
	return [ $size, $count ];
}

// ═════════════════════════════════════════════════════════════════════════════
// ADMIN PAGE
// ═════════════════════════════════════════════════════════════════════════════


// ─── Explain modal helper ────────────────────────────────────────────────────

/**
 * Render an "Explain..." button and its associated modal dialog.
 *
 * The modal is rendered inline with display:none and opened via onclick. JS functions
 * (cscToggle, cscOrphanToggle) are defined globally in admin.js.
 *
 * @since 2.3.0
 * @param string $id    Unique identifier for this button/modal pair (used in element IDs).
 * @param string $title Modal dialog title.
 * @param array  $items Array of explanation items, each with 'name', 'desc', and 'rec' keys.
 * @param string $color Background colour for the button (CSS colour string).
 * @return void
 */
function cscc_explain_btn( string $id, string $title, array $items, string $color = 'rgba(2.5.3955,2.5.1.2)' ): void {
    $btn_id   = 'csc-explain-btn-' . $id;
    $modal_id = 'csc-explain-modal-' . $id;
    ?>
    <button type="button" id="<?php echo esc_attr( $btn_id ); ?>"
        data-color="<?php echo esc_attr( $color ); ?>"
        onclick="document.getElementById('<?php echo esc_attr( $modal_id ); ?>').style.display='flex'"
        style="background:rgba(0,0,0,0.28)!important;border:1px solid rgba(2.5.395,255,0.55)!important;border-radius:5px!important;color:#fff!important;font-size:12px!important;font-weight:700!important;padding:5px 14px!important;cursor:pointer!important;margin-left:auto!important;flex-shrink:0!important;display:block!important;box-shadow:none!important;text-shadow:0 1px 2px rgba(0,0,0,0.4)!important;text-transform:none!important;letter-spacing:normal!important;line-height:1.4!important">
        Explain&hellip;
    </button>
    <div id="<?php echo esc_attr( $modal_id ); ?>" style="display:none;position:fixed;inset:0;z-index:100002;background:rgba(0,0,0,0.55);align-items:center;justify-content:center;padding:16px;text-transform:none;letter-spacing:normal;font-weight:normal">
        <div class="csc-modal" style="max-width:640px;max-height:88vh;overflow-y:auto">
            <div class="csc-modal-title"><?php echo esc_html( $title ); ?></div>
            <div class="csc-modal-body">
                <?php foreach ( $items as $item ) :
                    $rec    = $item['rec'];
                    $is_on  = strpos( $rec, 'Recommended' ) !== false;
                    $is_opt = strpos( $rec, 'Optional' ) !== false;
                    $bg     = $is_on ? '#edfaef' : ( $is_opt ? '#f6f7f7' : '#f0f6fc' );
                    $col    = $is_on ? '#1a7a34' : ( $is_opt ? '#50575e' : '#1a4a7a' );
                    $bdr    = $is_on ? '#1a7a34' : ( $is_opt ? '#c3c4c7' : '#2271b1' );
                ?>
                <div style="border:1px solid #e0e0e0;border-radius:6px;padding:12px 14px;margin-bottom:10px">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:5px;flex-wrap:wrap">
                        <strong style="font-size:13px"><?php echo esc_html( $item['name'] ); ?></strong>
                        <span style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $col ); ?>;border:1px solid <?php echo esc_attr( $bdr ); ?>;border-radius:4px;font-size:11px;font-weight:600;padding:1px 8px;white-space:nowrap"><?php echo esc_html( $rec ); ?></span>
                    </div>
                    <p style="margin:0;color:#50575e;font-size:12px;line-height:1.5;white-space:pre-line"><?php echo esc_html( $item['desc'] ); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="csc-modal-footer">
                <button type="button" onclick="document.getElementById('<?php echo esc_attr( $modal_id ); ?>').style.display='none'"
                    class="csc-btn csc-btn-cancel">Got it</button>
            </div>
        </div>
    </div>
    <?php
}

function cscc_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'cloudscale-cleanup' ) );
    }
    $dow            = array( 'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun' );
    $db_sched_days  = (array) get_option( 'cscc_schedule_db_days',  array( 'mon', 'wed', 'fri' ) );
    $img_sched_days = (array) get_option( 'cscc_schedule_img_days', array( 'mon', 'wed', 'fri' ) );
    ?>
    <div class="csc-wrap">

        <div class="csc-header">
            <div class="csc-header-inner">
                <div class="csc-header-title">
                    <span class="csc-logo">⚡</span>
                    <div>
                        <h1>CloudScale Cleanup</h1>
                        <p>Database and Media Library Cleanup &middot; Free and Open Source &middot; <a href="https://cloudscale.consulting" target="_blank">cloudscale.consulting</a></p>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;min-width:0;flex-shrink:1">
                    <div class="csc-header-version" style="flex-shrink:0">v<?php echo esc_html( CLOUDSCALE_CLEANUP_VERSION ); ?></div>
                    <a href="https://help.cloudscale.consulting/plugin-help/database-cleanup/" target="_blank" rel="noopener" class="csc-help-btn" style="display:inline-flex;align-items:center;gap:6px;background:#0073ff;color:#fff;font-size:13px;font-weight:600;padding:7px 14px;border-radius:20px;text-decoration:none;white-space:nowrap;transition:background 0.15s;box-shadow:0 0 12px rgba(0,115,255,0.5);flex-shrink:0" onmouseover="this.style.background='#005ce6'" onmouseout="this.style.background='#0073ff'">&#128218; <span>Help &amp; Documentation</span></a>
                </div>
            </div>
        </div>

        <div class="csc-tabs">
            <button class="csc-tab active" data-tab="site-health">Site Health</button>
            <button class="csc-tab" data-tab="db-cleanup">Database Cleanup</button>
            <button class="csc-tab" data-tab="img-cleanup">Media Cleanup</button>
            <button class="csc-tab" data-tab="img-optimise">Image Optimisation</button>
            <button class="csc-tab" data-tab="png-to-jpeg">PNG to JPEG</button>
            <button class="csc-tab" data-tab="space-report">Storage</button>
            <button class="csc-tab" data-tab="cron">Cron</button>
        </div>

        <!-- ═══ Database Cleanup ═══ -->
        <div class="csc-tab-content" id="tab-db-cleanup">
            <div class="csc-cards-row">
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-blue"><span>Database Cleanup</span> <?php cscc_explain_btn(
            'db-cleanup',
            'Database Cleanup, What it does',
            [
            [ 'rec' => '✅ Recommended', 'name' => 'Post Revisions', 'desc' => 'Every time you save or update a post, WordPress stores a complete copy. On an active blog this can mean hundreds of revision rows per post. They consume significant database space over time.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Draft Posts', 'desc' => 'Posts saved as drafts but never published. The threshold controls how old a draft must be before deletion, fresh drafts are never touched.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Trashed Posts', 'desc' => 'Posts moved to the trash. WordPress keeps them indefinitely by default. This removes them permanently after the configured number of days.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Auto-Drafts', 'desc' => 'WordPress creates an auto-draft record when you open Add New Post. If you navigate away without saving, the empty record remains. These accumulate silently.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Expired Transients', 'desc' => 'Temporary cached values stored in your options table by plugins and themes. After expiry WordPress should delete them, but many accumulate. Completely safe to delete.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Orphaned Post Meta', 'desc' => 'Post meta rows referencing a post ID that no longer exists. Left behind when posts are deleted without their metadata being cleaned up.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Orphaned User Meta', 'desc' => 'Metadata rows referencing deleted user accounts. Accumulates when users are removed from the system.' ],
            [ 'rec' => '💡 Tip', 'name' => 'Always dry run first', 'desc' => 'Press Dry Run to preview what will be removed, then review the output log carefully before running cleanup. No changes are made until you press the cleanup button.' ],
            ],
            '#00e676'
        ); ?></div>
                    <div class="csc-card-body">
                        <p class="csc-options-intro">Select which items to include in every cleanup run. Toggle off anything you want to preserve.</p>
                        <div class="csc-options-grid" id="csc-db-toggles">
                            <?php
                            $db_toggles = array(
                                'cscc_clean_revisions'      => array( 'Post Revisions',    'Old revision copies saved every time a post is edited. On active blogs these accumulate into thousands of rows.' ),
                                'cscc_clean_drafts'         => array( 'Draft Posts',        'Unpublished drafts older than the configured threshold. Fresh drafts are never touched.' ),
                                'cscc_clean_trashed'        => array( 'Trashed Posts',      'Posts in the WordPress trash older than the threshold. WordPress keeps them indefinitely by default.' ),
                                'cscc_clean_autodrafts'     => array( 'Auto-Drafts',        'Empty placeholder records left when the editor is abandoned without saving.' ),
                                'cscc_clean_transients'     => array( 'Expired Transients', 'Stale cached values stored in wp_options past their expiry date. Completely safe to delete.' ),
                                'cscc_clean_orphan_post'    => array( 'Orphaned Post Meta', 'Post meta rows whose parent post has been deleted. Left behind when posts are removed without proper cleanup.' ),
                                'cscc_clean_orphan_user'    => array( 'Orphaned User Meta', 'Meta rows referencing deleted user accounts. Accumulates when users are removed from the system.' ),
                                'cscc_clean_spam_comments'  => array( 'Spam Comments',      'Comments flagged as spam older than the configured threshold. Safe to remove after the review window.' ),
                                'cscc_clean_trash_comments' => array( 'Trashed Comments',   'Comments moved to the WordPress comment trash older than the threshold.' ),
                            );
                            foreach ( $db_toggles as $opt => $info ) :
                                $is_on = get_option( $opt, '1' ) === '1';
                            ?>
                            <div class="csc-option-row" style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 14px;border-radius:6px;background:#fff;border:1px solid transparent;transition:background 0.12s;"
                                 onmouseover="this.style.background='#f0f6fc';this.style.borderColor='#c5d9f0'"
                                 onmouseout="this.style.background='#fff';this.style.borderColor='transparent'">
                                <div style="display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;">
                                    <span style="font-size:13px;font-weight:700;color:#1d2327;"><?php echo esc_html( $info[0] ); ?></span>
                                    <span style="font-size:12px;color:#787c82;line-height:1.5;"><?php echo esc_html( $info[1] ); ?></span>
                                </div>
                                <!-- Hidden input holds the real value for form submission -->
                                <input type="hidden" name="<?php echo esc_attr( $opt ); ?>" value="<?php echo esc_attr( $is_on ? '1' : '0' ); ?>" data-csc-toggle="1">
                                <!-- Pure-div toggle, zero CSS class dependencies, all inline -->
                                <div data-csc-toggle-track="1"
                                     data-on="<?php echo esc_attr( $is_on ? '1' : '0' ); ?>"
                                     onclick="cscToggle(this)"
                                     style="position:relative;display:inline-block;width:44px;height:24px;min-width:44px;border-radius:24px;background:<?php echo esc_attr( $is_on ? '#00a32a' : '#c3c4c7' ); ?>;cursor:pointer;transition:background 0.2s;flex-shrink:0;">
                                    <span style="position:absolute;top:3px;left:<?php echo esc_attr( $is_on ? '23px' : '3px' ); ?>;width:18px;height:18px;background:#fff;border-radius:50%;box-shadow:0 1px 4px rgba(0,0,0,0.3);transition:left 0.2s;"></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php /* cscToggle() defined in admin.js, no inline script needed */ ?>
                        <div class="csc-button-row" style="margin-top:18px">
                            <button class="csc-btn csc-btn-primary csc-save-btn" data-group="db-types">Save Selection</button>
                            <button class="csc-btn csc-btn-secondary" id="btn-scan-db">🔍 Dry Run, Preview</button>
                            <button class="csc-btn csc-btn-danger"    id="btn-run-db">🗑 Run Cleanup Now</button>
                        </div>
                        <div class="csc-progress-outer" id="db-progress-outer" style="display:none">
                            <div class="csc-progress-bar"><div class="csc-progress-fill" id="db-progress-fill"></div></div>
                            <div class="csc-progress-label" id="db-progress-label">Preparing…</div>
                        </div>
                    </div>
                </div>
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-teal"><span>Cleanup Thresholds</span> <?php cscc_explain_btn(
            'thresholds',
            'Cleanup Thresholds, What each setting means',
            [
                [ 'rec' => 'ℹ️ Info', 'name' => 'Why thresholds exist', 'desc' => 'Every threshold prevents the cleanup from touching items that are too recent to be considered safe. This protects you from accidentally deleting a draft you were actively working on or a comment that arrived in spam yesterday.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Post revisions older than N days', 'desc' => 'Only revisions created more than N days ago are deleted. Very recent revisions are kept so you can still roll back recent edits. Default: 30 days.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Draft posts older than N days', 'desc' => 'Only posts in Draft status whose last-modified date is older than N days are deleted. A draft you edited yesterday will never be touched. Default: 90 days.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Trashed posts older than N days', 'desc' => 'Posts moved to the WordPress trash older than N days are permanently deleted. Default: 30 days.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Auto-drafts older than N days', 'desc' => 'The empty placeholder records WordPress creates when you open the editor. These are nearly always safe to delete immediately, a threshold of 7 days gives you a buffer. Default: 7 days.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Spam comments older than N days', 'desc' => 'Comments flagged as spam by Akismet or manually. Keeping them for 30 days lets you review false positives before permanent deletion. Default: 30 days.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Trashed comments older than N days', 'desc' => 'Comments you have manually moved to the WordPress comment trash. The threshold gives you a safety window to change your mind. Default: 30 days.' ],
            ],
            '#ff6d00'
        ); ?></div>
                    <div class="csc-card-body csc-settings-inline">
                        <label>Post revisions older than   <input type="number" class="csc-setting" name="cscc_post_revisions_age" value="<?php echo esc_attr( get_option( 'cscc_post_revisions_age', 30 ) ); ?>" min="1"> days</label>
                        <label>Draft posts older than       <input type="number" class="csc-setting" name="cscc_drafts_age"         value="<?php echo esc_attr( get_option( 'cscc_drafts_age',         90 ) ); ?>" min="1"> days</label>
                        <label>Trashed posts older than     <input type="number" class="csc-setting" name="cscc_trash_age"          value="<?php echo esc_attr( get_option( 'cscc_trash_age',          30 ) ); ?>" min="1"> days</label>
                        <label>Auto-drafts older than       <input type="number" class="csc-setting" name="cscc_autodraft_age"      value="<?php echo esc_attr( get_option( 'cscc_autodraft_age',       7 ) ); ?>" min="1"> days</label>
                        <label>Spam comments older than     <input type="number" class="csc-setting" name="cscc_spam_comments_age"  value="<?php echo esc_attr( get_option( 'cscc_spam_comments_age',  30 ) ); ?>" min="1"> days</label>
                        <label>Trashed comments older than  <input type="number" class="csc-setting" name="cscc_trash_comments_age" value="<?php echo esc_attr( get_option( 'cscc_trash_comments_age', 30 ) ); ?>" min="1"> days</label>
                        <div style="margin-top:8px"><button class="csc-btn csc-btn-primary csc-save-btn" data-group="db">Save Thresholds</button></div>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-slate-db"><span>Scheduled Database Cleanup</span> <?php cscc_explain_btn(
            'db-schedule',
            'Scheduled Database Cleanup, How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What scheduling does', 'desc' => 'When enabled, the plugin registers a WordPress Cron job that automatically runs the full database cleanup on the selected days at the configured hour, without you having to log in manually.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'Day and hour selection', 'desc' => 'You can select multiple days per week. The hour is in your server local time, not your browser timezone. Most VPS hosts default to UTC.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'How WordPress Cron works', 'desc' => 'WordPress Cron is triggered by page visits, not a real system clock. On low-traffic sites a job scheduled for 3AM may not run until the first visitor arrives. For precise scheduling, disable WP-Cron in wp-config.php and add a real server cron.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'After each scheduled run', 'desc' => 'The plugin automatically schedules the next run after each execution, so the schedule remains active indefinitely without any manual intervention.' ],
            ],
            '#f48fb1'
        ); ?></div>
                <div class="csc-card-body">
                    <label class="csc-toggle-label">
                        <input type="checkbox" name="cscc_schedule_db_enabled" value="1" <?php checked( get_option( 'cscc_schedule_db_enabled', '0' ), '1' ); ?>>
                        Enable automatic scheduled cleanup
                    </label>
                    <div class="csc-schedule-row">
                        <?php foreach ( $dow as $val => $label ) : ?>
                        <label class="csc-day-label">
                            <input type="checkbox" name="cscc_schedule_db_days[]" value="<?php echo esc_attr( $val ); ?>" <?php checked( in_array( $val, $db_sched_days, true ), true ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                        <?php endforeach; ?>
                        <label class="csc-hour-label">at hour <input type="number" name="cscc_schedule_db_hour" class="csc-small-num" value="<?php echo esc_attr( get_option( 'cscc_schedule_db_hour', 3 ) ); ?>" min="0" max="23"> (server time)</label>
                    </div>
                    <button class="csc-btn csc-btn-primary csc-save-btn" data-group="db-schedule">Save Schedule</button>
                    <?php
                    $next_db_sched = wp_next_scheduled( 'cscc_scheduled_db_cleanup' );
                    $last_db_sched = get_option( 'cscc_last_scheduled_db_cleanup', '' );
                    ?>
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:10px">
                        <span style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#00c853 0%,#69f0ae 100%);color:#003d00;font-size:11.5px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.3px;box-shadow:0 2px 8px rgba(0,200,83,0.3)">✅ Last Run: <?php echo $last_db_sched ? esc_html( date_i18n( 'D j M Y H:i', strtotime( $last_db_sched ) ) ) : 'Never'; ?></span>
                        <?php if ( $next_db_sched ) : ?>
                        <span style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#2979ff 0%,#82b1ff 100%);color:#fff;font-size:11.5px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.3px;box-shadow:0 2px 8px rgba(41,121,2.5.1.3)">⏰ Next Run: <?php echo esc_html( date_i18n( 'D j M Y H:i', $next_db_sched ) ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <?php
                $al_bytes2 = cscc_get_autoload_size();
                $al_rag2   = cscc_autoload_rag( $al_bytes2 );
                $al_hdr_bg = $al_rag2 === 'red' ? 'linear-gradient(135deg,#b71c1c 0%,#c62828 100%)' : ( $al_rag2 === 'amber' ? 'linear-gradient(135deg,#bf360c 0%,#e64a19 100%)' : 'linear-gradient(135deg,#1b5e20 0%,#2e7d32 100%)' );
                ?>
                <div class="csc-card-header" style="background:<?php echo esc_attr( $al_hdr_bg ); ?>;color:#fff;font-weight:700"><span>⚡ Autoloaded Options</span>
                    <span style="font-size:11px;font-weight:400;opacity:0.85;margin-left:8px"><?php echo esc_html( size_format( $al_bytes2, 1 ) ); ?> loaded on every request</span>
                    <?php cscc_explain_btn(
                        'autoload',
                        'Autoloaded Options, What it does',
                        [
                            [ 'rec' => 'ℹ️ Info',         'name' => 'What are autoloaded options?', 'desc' => 'WordPress loads rows marked autoload=yes from wp_options on every single page request, regardless of whether they are needed. Plugins and themes add rows here for caching and configuration, but many forget to clean up expired or redundant entries.' ],
                            [ 'rec' => '✅ Recommended',  'name' => 'Delete expired transients',   'desc' => 'Transients are temporary cache values with an expiry time. WordPress should delete them automatically on expiry, but many accumulate when cron is unreliable or plugins exit early. Deleting them is completely safe.' ],
                            [ 'rec' => '✅ Recommended',  'name' => 'Disable transient autoload',  'desc' => 'Any remaining transient rows (not yet expired) do not need to be autoloaded, WordPress fetches them on demand when a plugin requests them. Disabling autoload reduces the amount of data loaded on every request without removing any data.' ],
                            [ 'rec' => 'ℹ️ Info',         'name' => 'Non-destructive',             'desc' => 'This cleanup never deletes valid plugin data. Only expired transients are deleted. All other options are left in place, only their autoload flag is changed.' ],
                            [ 'rec' => '💡 Tip',          'name' => 'Always dry run first',        'desc' => 'Press Dry Run to preview current autoload size, the top rows consuming the most space, and what the cleanup will do. No changes are made until you press Clean Autoload Now.' ],
                        ]
                    ); ?>
                </div>
                <div class="csc-card-body">
                    <?php
                    $al_rag_labels = array( 'green' => '✅ Healthy', 'amber' => '⚠️ Warning', 'red' => '🔴 Critical' );
                    $al_rag_bg     = array( 'green' => '#2e7d32',    'amber' => '#e65100',    'red' => '#c62828' );
                    ?>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                        <span id="autoload-rag-badge" style="display:inline-flex;align-items:center;gap:6px;background:<?php echo esc_attr( $al_rag_bg[ $al_rag2 ] ); ?>;color:#fff;font-size:12px;font-weight:700;padding:4px 14px;border-radius:20px;letter-spacing:0.3px;box-shadow:0 2px 6px rgba(0,0,0,0.2)"><?php echo esc_html( $al_rag_labels[ $al_rag2 ] ); ?>, <?php echo esc_html( size_format( $al_bytes2, 1 ) ); ?></span>
                        <span style="font-size:11px;color:#78909c">autoloaded on every request</span>
                    </div>
                    <p style="margin:0 0 10px;font-size:13px;color:#3c434a;line-height:1.6">WordPress loads autoloaded options on <strong>every page request</strong>. Expired transients and plugin caches often accumulate here, bloating memory usage. This cleanup deletes expired transients and disables autoloading for any remaining transient rows, no plugin data is removed.</p>
                    <div class="csc-button-row">
                        <button class="csc-btn csc-btn-secondary" id="btn-scan-autoload">🔍 Dry Run, Preview</button>
                        <button class="csc-btn csc-btn-danger"    id="btn-run-autoload">⚡ Clean Autoload Now</button>
                    </div>
                    <div class="csc-progress-outer" id="autoload-progress-outer" style="display:none">
                        <div class="csc-progress-bar"><div class="csc-progress-fill" id="autoload-progress-fill"></div></div>
                        <div class="csc-progress-label" id="autoload-progress-label">Preparing…</div>
                    </div>
                    <pre class="csc-terminal" id="autoload-terminal" style="margin-top:10px;min-height:60px">Ready. Press Dry Run to preview autoloaded options, then Clean Autoload Now to optimise.</pre>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-slate-db"><span>Orphaned Plugin Options</span> <?php cscc_explain_btn(
                    'orphan-options',
                    'Orphaned Plugin Options, How detection works',
                    array(
                        array( 'rec' => 'ℹ️ Info',        'name' => 'What are orphaned options?',    'desc' => 'When a plugin is deleted without a proper uninstall routine, its configuration rows remain in the wp_options table forever, including autoloaded rows that bloat every page request. WordPress never removes these automatically.' ),
                        array( 'rec' => 'ℹ️ Info',        'name' => 'How detection works',           'desc' => 'The scan compares all wp_options rows against the list of currently installed plugins. Rows whose name prefix does not match any installed plugin are flagged as likely orphaned.' ),
                        array( 'rec' => '⚠️ Review first', 'name' => 'Always review before deleting', 'desc' => 'Detection is heuristic. Some plugins use option names that do not obviously match their slug. Uncheck anything you are unsure about, deleting a live option can break a plugin.' ),
                        array( 'rec' => '💡 Tip',         'name' => 'When in doubt, keep it',        'desc' => 'The scan is conservative, it skips all WordPress core options and any option whose name starts with an installed plugin slug. Anything remaining is a strong candidate for deletion, but use your judgement.' ),
                    )
                ); ?></div>
                <div class="csc-card-body">
                    <p style="margin:0 0 12px;font-size:13px;color:#3c434a;line-height:1.6">Scans wp_options for rows left behind by deleted plugins. Results are shown as a checklist, nothing is pre-selected. Use <strong>Select Known Plugins</strong> or the search box to find rows by plugin name (wildcards like <code>yoast*</code> supported), then move selected items to the recycle bin.</p>
                    <div class="csc-button-row">
                        <button class="csc-btn csc-btn-secondary" id="btn-scan-orphans">🔍 Scan for Orphans</button>
                        <button class="csc-btn csc-btn-danger" id="btn-run-orphans" style="display:none">♻️ Move to Recycle Bin</button>
                    </div>
                    <?php
                    $orphan_bin       = cscc_orphan_bin_get();
                    $orphan_bin_count = count( $orphan_bin );
                    ?>
                    <?php $bin_has = $orphan_bin_count > 0; ?>
                    <div id="orphan-bin-bar" style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 12px;background:<?php echo esc_attr( $bin_has ? '#fff3e0' : '#f5f5f5' ); ?>;border:1px solid <?php echo esc_attr( $bin_has ? '#ffb74d' : '#ddd' ); ?>;border-radius:6px;font-size:12px">
                        <span id="orphan-bin-label">♻️ Recycle Bin: <strong><?php echo esc_html( $orphan_bin_count ); ?></strong> item<?php echo esc_html( $orphan_bin_count === 1 ? '' : 's' ); ?></span>
                        <button class="csc-btn csc-btn-secondary" id="btn-orphan-view-bin" style="font-size:11px;padding:3px 10px"<?php echo esc_attr( ! $bin_has ? ' disabled' : '' ); ?>>View</button>
                        <button class="csc-btn csc-btn-secondary" id="btn-orphan-undo"     style="font-size:11px;padding:3px 10px"<?php echo esc_attr( ! $bin_has ? ' disabled' : '' ); ?>>↩ Restore All</button>
                        <button class="csc-btn csc-btn-danger"    id="btn-orphan-empty"    style="font-size:11px;padding:3px 10px"<?php echo esc_attr( ! $bin_has ? ' disabled' : '' ); ?>>🗑 Empty Bin</button>
                    </div>
                    <div id="orphan-bin-list" style="margin-top:8px;display:none"></div>
                    <div id="orphan-results" style="margin-top:12px"></div>
                </div>
            </div>

            <div class="csc-card">
                <?php
                $tbl_overhead = cscc_table_overhead_total();
                $tbl_rag      = cscc_table_overhead_rag( $tbl_overhead );
                $tbl_hdr_bg   = $tbl_rag === 'red' ? 'linear-gradient(135deg,#b71c1c 0%,#c62828 100%)' : ( $tbl_rag === 'amber' ? 'linear-gradient(135deg,#bf360c 0%,#e64a19 100%)' : 'linear-gradient(135deg,#1b5e20 0%,#2e7d32 100%)' );
                $tbl_rag_bgs  = array( 'green' => '#2e7d32', 'amber' => '#e65100', 'red' => '#c62828' );
                $tbl_rag_lbls = array( 'green' => '✅ Healthy', 'amber' => '⚠️ Warning', 'red' => '🔴 Critical' );
                ?>
                <div class="csc-card-header" style="background:<?php echo esc_attr( $tbl_hdr_bg ); ?>;color:#fff;font-weight:700"><span>🔧 Table Overhead Repair</span><?php cscc_explain_btn(
                    'table-overhead',
                    'Table Overhead Repair, How it works',
                    array(
                        array( 'rec' => 'ℹ️ Info',        'name' => 'What is table overhead?',        'desc' => 'Every time WordPress deletes rows, revisions, transients, spam, trashed posts, MySQL/InnoDB marks that space as free but does not physically return it. Over time tables accumulate "gaps" that waste disk space and slow down full-table scans.' ),
                        array( 'rec' => 'ℹ️ Info',        'name' => 'What OPTIMIZE TABLE does',       'desc' => 'Rewrites the table file compactly, eliminating the gaps. The result is a smaller table file that fits better in the buffer pool and is faster to scan.' ),
                        array( 'rec' => '✅ Safe',         'name' => 'InnoDB, online DDL, no lock',   'desc' => 'On MySQL 5.6+ with InnoDB (the WordPress default), OPTIMIZE TABLE uses online DDL. The table remains fully readable and writable during optimisation. No downtime required.' ),
                        array( 'rec' => '⚠️ Note',        'name' => 'MyISAM, brief table lock',      'desc' => 'MyISAM tables (rare on modern WordPress installs) are locked briefly during optimisation. If any of your tables show engine: MyISAM in the dry run, run during low-traffic hours.' ),
                        array( 'rec' => 'ℹ️ Info',        'name' => 'Data_free is an estimate',       'desc' => 'InnoDB reports Data_free as an approximation. Actual space reclaimed may differ slightly from the dry run estimate, this is normal.' ),
                        array( 'rec' => '💡 Tip',         'name' => 'When to run',                    'desc' => 'Run after any large cleanup operation, deleting post revisions, expired transients, or orphaned options. On a typical WordPress site with regular content churn, running monthly keeps overhead low.' ),
                    )
                ); ?></div>
                <div class="csc-card-body">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                        <span id="table-rag-badge" style="display:inline-flex;align-items:center;gap:6px;background:<?php echo esc_attr( $tbl_rag_bgs[ $tbl_rag ] ); ?>;color:#fff;font-size:12px;font-weight:700;padding:4px 14px;border-radius:20px;letter-spacing:0.3px;box-shadow:0 2px 6px rgba(0,0,0,0.2)"><?php echo esc_html( $tbl_rag_lbls[ $tbl_rag ] ); ?>, <?php echo esc_html( size_format( $tbl_overhead, 1 ) ); ?> overhead</span>
                    </div>
                    <p style="margin:0 0 10px;font-size:13px;color:#3c434a;line-height:1.6">After bulk deletes, MySQL tables accumulate fragmentation gaps. OPTIMIZE TABLE rewrites them compactly and reclaims that space. InnoDB runs online, no table locks on MySQL 5.6+.</p>
                    <div class="csc-button-row">
                        <button class="csc-btn csc-btn-secondary" id="btn-scan-tables">🔍 Dry Run, Preview</button>
                        <button class="csc-btn csc-btn-danger"    id="btn-run-tables">🔧 Repair Tables</button>
                    </div>
                    <div class="csc-progress-outer" id="table-progress-outer" style="display:none">
                        <div class="csc-progress-bar"><div class="csc-progress-fill" id="table-progress-fill"></div></div>
                        <div class="csc-progress-label" id="table-progress-label">Preparing…</div>
                    </div>
                    <pre class="csc-terminal" id="table-terminal" style="margin-top:10px;min-height:60px">Ready. Press Dry Run to preview table overhead, then Repair Tables to optimise.</pre>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-dark" style="display:flex;align-items:center;justify-content:space-between">
                    <span>Output Log</span>
                    <button class="btn-copy-log" style="background:rgba(2.5.395,255,0.15);border:none;color:#1a1a1a;font-size:12px;font-weight:600;padding:4px 10px;border-radius:4px;cursor:pointer;transition:background 0.15s" onmouseover="this.style.background='rgba(2.5.395,255,0.28)'" onmouseout="this.style.background='rgba(2.5.395,255,0.15)'">&#128203; Copy</button>
                </div>
                <div class="csc-card-body csc-terminal-wrap">
                    <div style="display:flex;align-items:center;gap:6px;padding:4px 12px;background:#0d1b2a;border-bottom:2px solid #00e5ff;border-radius:6px 6px 0 0"><span style="width:7px;height:7px;border-radius:50%;background:#00e5ff;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#00e5ff;opacity:.5;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#00e5ff;opacity:.25;display:inline-block;flex-shrink:0"></span><span style="margin-left:8px;background:#00e5ff;color:#0d1b2a;font-family:monospace;font-size:10px;font-weight:800;letter-spacing:.1em;padding:2px 10px;border-radius:20px;text-transform:uppercase">⚙ Database Console</span></div>
                    <pre class="csc-terminal" id="db-terminal">Ready. Press Dry Run to preview what will be removed, then review the output log before running cleanup.</pre>
                </div>
            </div>
        </div>

        <!-- ═══ Image Cleanup ═══ -->
        <div class="csc-tab-content" id="tab-img-cleanup">
            <div class="csc-cards-row">
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-purple"><span>Unused Media</span> <?php cscc_explain_btn(
            'unused-images',
            'Unused Media, How detection works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What unused means', 'desc' => 'An attachment is considered unused if it cannot be found in post content, featured images, widget settings, theme mods, the site logo, or the site icon. These are media files registered in WordPress but not referenced anywhere on your site.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'What is always protected', 'desc' => 'The site logo and site icon set in Appearance Customize are never flagged as unused, regardless of whether they appear in post content.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'The recycle workflow', 'desc' => 'Unused media is moved to a recycle bin rather than deleted immediately. The recycle bin stores the full attachment record, metadata, and all physical files so they can be completely restored if needed. Only Permanently Delete removes files for good.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Chunked processing', 'desc' => 'Moves are processed in batches of 25 per request so the operation never risks hitting PHP timeout limits, even on shared hosting with libraries of thousands of images.' ],
            [ 'rec' => '💡 Tip', 'name' => 'Always dry run first', 'desc' => 'Press Dry Run to preview which media will be flagged as unused, then review the output log carefully before moving to recycle. No files are touched until you press the recycle button.' ],
            ],
            '#00e5ff'
        ); ?></div>
                    <div class="csc-card-body">
                        <p>Finds media library attachments not referenced in any post, page, featured image, widget, or theme setting. These are registered in WordPress but nothing links to them. Moves are chunked at <?php echo (int) CSCC_CHUNK_IMAGES; ?> per request with a live progress bar.</p>
                        <div class="csc-button-row">
                            <button class="csc-btn csc-btn-secondary" id="btn-scan-img">🔍 Dry Run, Preview</button>
                            <button class="csc-btn csc-btn-danger"    id="btn-run-img">♻️ Move to Recycle</button>
                        </div>
                        <div class="csc-progress-outer" id="img-progress-outer" style="display:none">
                            <div class="csc-progress-bar"><div class="csc-progress-fill" id="img-progress-fill"></div></div>
                            <div class="csc-progress-label" id="img-progress-label">Preparing…</div>
                        </div>
                        <div id="img-dry-run-summary" style="display:none;margin-top:12px;padding:10px 14px;background:#fff8e1;border-left:4px solid #f9a825;border-radius:0 6px 6px 0;font-size:13px">
                            <strong id="img-dry-run-msg" style="color:#e65100"></strong>
                            <span style="color:#795548">, review the output log below, then click <strong>♻️ Move to Recycle</strong> when ready.</span>
                        </div>
                        <div id="media-recycle-actions" style="margin-top:12px;padding:12px 14px;background:#f3e5f5;border:1px solid #ce93d8;border-radius:6px">
                            <strong style="font-size:12px;color:#4a148c">♻️ Media Recycle Bin</strong>
                            <span id="media-recycle-count" style="font-size:12px;color:#4a148c;margin-left:6px">,  checking…</span>
                            <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
                                <button class="csc-btn" id="btn-restore-media"
                                    style="background:#43a047;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    ↩️ Restore All
                                </button>
                                <button class="csc-btn" id="btn-purge-media"
                                    style="background:#b71c1c;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    🗑 Permanently Delete
                                </button>
                                <button class="csc-btn" id="btn-browse-media-recycle"
                                    style="background:#5c6bc0;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    📂 View Recycle Bin
                                </button>
                            </div>
                        </div>

                        <!-- Media Recycle Bin Browser Modal -->
                        <div id="csc-media-recycle-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:100001;background:rgba(0,0,0,0.55);padding:20px;overflow-y:auto">
                            <div class="csc-modal csc-modal-lg" style="margin:40px auto">
                                <div class="csc-modal-header">
                                    <div>
                                        <div class="csc-modal-header-title">♻️ Media Recycle Bin</div>
                                        <div id="media-recycle-modal-summary" class="csc-modal-header-sub">Loading…</div>
                                    </div>
                                    <button id="btn-media-recycle-modal-close" class="csc-modal-close">✕</button>
                                </div>
                                <div id="media-recycle-modal-list" class="csc-modal-list"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-amber"><span>Unregistered Files</span> <?php cscc_explain_btn(
            'orphan-files',
            'Unregistered Files, What they are',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What an unregistered file is', 'desc' => 'A file that exists physically on disk inside wp-content/uploads but has no corresponding WordPress media library record in the database. Unlike unused media (which has a database record but no references), these files have no database record at all.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'How they accumulate', 'desc' => 'Files uploaded via FTP without being registered in WordPress, partially completed uploads, images imported without the media importer, or files left behind by deleted plugins.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'The recycle workflow', 'desc' => 'Scan finds unregistered files. Move to Recycle places them in wp-content/uploads/.csc-recycle/ with a manifest recording their original paths. Restore moves them back exactly. Permanently Delete wipes the recycle bin.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'What the scan checks', 'desc' => 'Every file found recursively under the uploads directory is checked against files registered in _wp_attached_file and _wp_attachment_metadata, plus any file URLs referenced in published post content. Files not in any of these sets are reported as unregistered.' ],
            ],
            '#ff4081'
        ); ?></div>
                    <div class="csc-card-body">
                        <p>Finds files sitting in your uploads folder that have no matching record in the WordPress media library. Typically from FTP uploads, failed imports, or plugins that wrote files directly. Use the recycle workflow to safely remove them.</p>
                        <?php /* cscPillOff/cscPillOn/cscOrphanToggle/cscOrphanTypes defined in admin.js, no inline script needed */ ?>
                        <div style="display:flex;gap:0;flex-wrap:wrap;margin-bottom:14px">
                            <span class="csc-orphan-pill" onclick="cscOrphanToggle(this,'images')"    style="display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #00a32a;font-size:12px;font-weight:700;cursor:pointer;background:#00a32a;color:#fff;margin:0 4px 4px 0">🖼 Images</span>
                            <span class="csc-orphan-pill" onclick="cscOrphanToggle(this,'documents')" style="display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #00a32a;font-size:12px;font-weight:700;cursor:pointer;background:#00a32a;color:#fff;margin:0 4px 4px 0">📄 Documents</span>
                            <span class="csc-orphan-pill" onclick="cscOrphanToggle(this,'video')"     style="display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #00a32a;font-size:12px;font-weight:700;cursor:pointer;background:#00a32a;color:#fff;margin:0 4px 4px 0">🎬 Video</span>
                            <span class="csc-orphan-pill" onclick="cscOrphanToggle(this,'audio')"     style="display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #00a32a;font-size:12px;font-weight:700;cursor:pointer;background:#00a32a;color:#fff;margin:0 4px 4px 0">🎵 Audio</span>
                        </div>
                        <div class="csc-button-row" style="flex-wrap:wrap;gap:10px">
                            <button class="csc-btn csc-btn-secondary" id="btn-scan-orphan">🔍 Scan Unregistered Files</button>
                            <button class="csc-btn csc-btn-danger"    id="btn-recycle-orphan">♻️ Move to Recycle</button>
                        </div>
                        <div id="orphan-recycle-actions" style="margin-top:12px;padding:12px 14px;background:#fff8e1;border:1px solid #ffe082;border-radius:6px">
                            <strong style="font-size:12px;color:#5d4037">♻️ Recycle Bin</strong>
                            <span id="orphan-recycle-count" style="font-size:12px;color:#5d4037;margin-left:6px">,  checking…</span>
                            <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
                                <button class="csc-btn" id="btn-restore-orphan"
                                    style="background:#43a047;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    ↩️ Restore All
                                </button>
                                <button class="csc-btn" id="btn-purge-orphan"
                                    style="background:#b71c1c;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    🗑 Permanently Delete
                                </button>
                                <button class="csc-btn" id="btn-browse-recycle"
                                    style="background:#5c6bc0;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    📂 View Recycle Bin
                                </button>
                            </div>
                        </div>

                        <!-- Recycle Bin Browser Modal -->
                        <div id="csc-recycle-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:100001;background:rgba(0,0,0,0.55);padding:20px;overflow-y:auto">
                            <div class="csc-modal csc-modal-lg" style="margin:40px auto">
                                <div class="csc-modal-header">
                                    <div>
                                        <div class="csc-modal-header-title">♻️ Recycle Bin Browser</div>
                                        <div id="recycle-modal-summary" class="csc-modal-header-sub">Loading…</div>
                                    </div>
                                    <div style="display:flex;gap:8px;align-items:center">
                                        <button id="btn-recycle-copy-all" class="csc-btn csc-btn-secondary csc-btn-sm">📋 Copy All to Clipboard</button>
                                        <button id="btn-recycle-modal-close" class="csc-modal-close">✕</button>
                                    </div>
                                </div>
                                <div class="csc-modal-search">
                                    <input type="text" id="recycle-search" placeholder="Search files…">
                                </div>
                                <div id="recycle-modal-list" class="csc-modal-list"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-dark" style="display:flex;align-items:center;justify-content:space-between">
                    <span>Output Log</span>
                    <button class="btn-copy-log" style="background:rgba(2.5.395,255,0.15);border:none;color:#1a1a1a;font-size:12px;font-weight:600;padding:4px 10px;border-radius:4px;cursor:pointer;transition:background 0.15s" onmouseover="this.style.background='rgba(2.5.395,255,0.28)'" onmouseout="this.style.background='rgba(2.5.395,255,0.15)'">&#128203; Copy</button>
                </div>
                <div class="csc-card-body csc-terminal-wrap">
                    <div style="display:flex;align-items:center;gap:6px;padding:4px 12px;background:#1a0533;border-bottom:2px solid #e040fb;border-radius:6px 6px 0 0"><span style="width:7px;height:7px;border-radius:50%;background:#e040fb;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#e040fb;opacity:.5;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#e040fb;opacity:.25;display:inline-block;flex-shrink:0"></span><span style="margin-left:8px;background:#e040fb;color:#fff;font-family:monospace;font-size:10px;font-weight:800;letter-spacing:.1em;padding:2px 10px;border-radius:20px;text-transform:uppercase">🖼 Image Console</span></div>
                    <pre class="csc-terminal" id="img-terminal">Ready. Press Dry Run to preview which media will be flagged as unused, then review the output log before moving to recycle.</pre>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header" style="background:linear-gradient(135deg,#d84315 0%,#ff5722 100%)"><span>🔗 Broken Image Links</span> <?php cscc_explain_btn(
            'broken-images',
            'Broken Image Links, How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What it does', 'desc' => 'Scans all published posts and pages for image URLs (img src attributes) that reference files in your uploads directory. For each URL found it checks whether the file actually exists on disk.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'What it finds', 'desc' => 'Detects broken images caused by deleted files, renamed files (e.g. dimensions appended like photo-1024x768.jpg), moved files, or failed optimisations.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Safe and read only', 'desc' => 'This scan does not modify anything. It only reports what it finds so you can fix the issues manually or with a search and replace plugin.' ],
            ],
            '#ff8a65'
        ); ?></div>
                <div class="csc-card-body">
                    <p style="margin:0 0 12px;font-size:13px;color:#50575e;line-height:1.5">Scan all published content for image URLs pointing to files that no longer exist on disk.</p>
                    <div class="csc-button-row" style="flex-wrap:wrap;gap:10px">
                        <button class="csc-btn csc-btn-secondary" id="btn-scan-broken-images">🔍 Scan for Broken Images</button>
                        <button class="csc-btn" id="btn-copy-broken-images" style="display:none;background:#5c6bc0;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">📋 Copy Results</button>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header" style="background:linear-gradient(135deg,#2e7d32 0%,#1b5e20 100%)"><span>🔧 Generate Missing Thumbnails</span> <?php cscc_explain_btn(
            'regen-thumbnails',
            'Generate Missing Thumbnails, How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'Why thumbnails go missing', 'desc' => 'Image cleanup tools (including this plugin\'s Unregistered Files scan) can delete WordPress-generated intermediate sizes, thumbnail, medium, medium_large, large, if the attachment metadata is out of date or the scan treats them as orphans. Once gone, your theme falls back to the full-size original and CSS-crops it, causing edges to be cut off or the wrong area to be shown.' ],
            [ 'rec' => '🔍 Info', 'name' => 'Scan', 'desc' => 'Checks every image attachment against all registered WordPress image sizes. Reports how many are missing one or more sizes, and highlights images currently set as featured images on posts, these affect how your articles look right now.' ],
            [ 'rec' => '✅ Safe', 'name' => 'Regenerate', 'desc' => 'Runs wp_generate_attachment_metadata() for each image that has missing sizes. Processes in batches of 5 to avoid server timeouts. Only generates what is missing, images that are already complete are skipped. Original full-size files are never modified.' ],
            [ 'rec' => '🖼 Info', 'name' => 'Featured image badge', 'desc' => 'Images marked [Featured] in the scan results are set as the featured image on one or more posts. These are the highest priority to regenerate as they directly control the article header and og:image for social sharing.' ],
            ],
            '#69f0ae'
        ); ?></div>
                <div class="csc-card-body">
                    <p style="margin:0 0 12px;font-size:13px;color:#50575e;line-height:1.5">If a cleanup plugin deleted your image thumbnails, article headers will show the wrong crop or be truncated. Scan to see how many sizes are missing, then regenerate them all in one click.</p>
                    <div class="csc-button-row" style="flex-wrap:wrap;gap:10px">
                        <button class="csc-btn csc-btn-secondary" id="btn-scan-regen-thumb">🔍 Scan for Missing Sizes</button>
                        <button class="csc-btn" id="btn-run-regen-thumb" style="display:none;background:#2e7d32;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">⚙️ Regenerate All Missing</button>
                    </div>
                    <div class="csc-progress-outer" id="regen-thumb-progress-outer" style="display:none;margin-top:10px">
                        <div class="csc-progress-bar"><div class="csc-progress-fill" id="regen-thumb-progress-fill"></div></div>
                        <div class="csc-progress-label" id="regen-thumb-progress-label">Working…</div>
                    </div>
                    <div id="regen-thumb-summary" style="display:none;margin-top:12px;padding:10px 14px;background:#e8f5e9;border-left:4px solid #2e7d32;border-radius:0 6px 6px 0;font-size:13px">
                        <strong id="regen-thumb-msg" style="color:#1b5e20"></strong>
                    </div>
                    <div id="regen-thumb-results" style="display:none;margin-top:12px;font-size:12px;line-height:1.7;color:#50575e"></div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-slate-img"><span>Scheduled Media Cleanup</span> <?php cscc_explain_btn(
            'img-schedule',
            'Scheduled Media Cleanup, How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What it does', 'desc' => 'Runs the unused media cleanup automatically on the selected days and hour. Unused attachments are moved to the media recycle bin (not deleted). The same detection logic as the manual cleanup is used, the site logo and site icon are always protected.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'Use with caution on active sites', 'desc' => 'Automated cleanup is most appropriate for sites where images are always attached to posts via the standard WordPress editor. Review a manual dry run before enabling the schedule.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'After each run', 'desc' => 'The next scheduled run is automatically registered, so the schedule stays active indefinitely. You can review and restore recycled media at any time from the Media Cleanup tab.' ],
            ],
            '#69f0ae'
        ); ?></div>
                <div class="csc-card-body">
                    <label class="csc-toggle-label">
                        <input type="checkbox" name="cscc_schedule_img_enabled" value="1" <?php checked( get_option( 'cscc_schedule_img_enabled', '0' ), '1' ); ?>>
                        Enable automatic scheduled cleanup
                    </label>
                    <div class="csc-schedule-row">
                        <?php foreach ( $dow as $val => $label ) : ?>
                        <label class="csc-day-label">
                            <input type="checkbox" name="cscc_schedule_img_days[]" value="<?php echo esc_attr( $val ); ?>" <?php checked( in_array( $val, $img_sched_days, true ), true ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                        <?php endforeach; ?>
                        <label class="csc-hour-label">at hour <input type="number" name="cscc_schedule_img_hour" class="csc-small-num" value="<?php echo esc_attr( get_option( 'cscc_schedule_img_hour', 4 ) ); ?>" min="0" max="23"> (server time)</label>
                    </div>
                    <button class="csc-btn csc-btn-primary csc-save-btn" data-group="img-schedule">Save Schedule</button>
                    <?php
                    $next_img_sched = wp_next_scheduled( 'cscc_scheduled_img_cleanup' );
                    $last_img_sched = get_option( 'cscc_last_scheduled_img_cleanup', '' );
                    ?>
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:10px">
                        <span style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#ff6d00 0%,#ffab40 100%);color:#3e1c00;font-size:11.5px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.3px;box-shadow:0 2px 8px rgba(2.5.399,0,0.3)">✅ Last Run: <?php echo $last_img_sched ? esc_html( date_i18n( 'D j M Y H:i', strtotime( $last_img_sched ) ) ) : 'Never'; ?></span>
                        <?php if ( $next_img_sched ) : ?>
                        <span style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#aa00ff 0%,#d500f9 100%);color:#fff;font-size:11.5px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.3px;box-shadow:0 2px 8px rgba(170,0,2.5.1.3)">⏰ Next Run: <?php echo esc_html( date_i18n( 'D j M Y H:i', $next_img_sched ) ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Image Optimisation ═══ -->
        <div class="csc-tab-content" id="tab-img-optimise">
            <div class="csc-cards-row">
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-red"><span>Image Optimisation</span> <?php cscc_explain_btn(
            'img-optimise',
            'Image Optimisation, How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What it does', 'desc' => 'Processes your original uploaded images in two ways: Resize (scales down images exceeding your configured maximum, preserving aspect ratio) and Recompress (re-saves JPEG files at the configured quality level).' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Thumbnail regeneration', 'desc' => 'Thumbnails are only regenerated when an image is resized or converted from PNG to JPEG. For quality-only recompression the existing thumbnails stay valid and are left untouched, making those runs much faster.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'PNG to JPEG conversion', 'desc' => 'When enabled, PNG files without transparency are converted to JPEG. Photographic PNGs typically shrink by 40-70% when converted. PNG files with transparency are never converted.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Chunked processing', 'desc' => 'One image is processed per request to stay well within server and gateway timeout limits. Each request completes in seconds regardless of image size.' ],
            [ 'rec' => '💡 Tip', 'name' => 'Always dry run first', 'desc' => 'Press Dry Run to preview potential savings, then review the output log carefully before optimising. No files are modified until you press the optimise button.' ],
            ],
            '#ff1744'
        ); ?></div>
                    <div class="csc-card-body">
                        <p>Resizes oversized originals and recompresses JPEGs to the configured quality target. Processes one image per request, each request completes in seconds. Files under the minimum size or below the gain threshold are skipped automatically.</p>
                        <div class="csc-button-row">
                            <button class="csc-btn csc-btn-secondary" id="btn-scan-optimise">🔍 Dry Run, Preview Savings</button>
                            <button class="csc-btn csc-btn-danger"    id="btn-run-optimise">⚡ Optimise Images Now</button>
                        </div>
                        <div class="csc-progress-outer" id="opt-progress-outer" style="display:none">
                            <div class="csc-progress-bar"><div class="csc-progress-fill" id="opt-progress-fill"></div></div>
                            <div class="csc-progress-label" id="opt-progress-label">Preparing…</div>
                        </div>
                        <p class="csc-note">This modifies original image files on disk. Take a full backup first. WordPress 5.3+ preserves a scaled backup original automatically.</p>
                    </div>
                </div>
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-green"><span>Optimisation Settings</span> <?php cscc_explain_btn(
            'opt-settings',
            'Optimisation Settings, What each option does',
            [
            [ 'rec' => '✅ Recommended', 'name' => 'Maximum width and height (px)', 'desc' => 'Any image whose width or height exceeds the maximum will be scaled down proportionally. Default: 1920x1080. If your theme never displays images wider than 1200px, setting the max to 1200 will produce better storage savings.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'JPEG quality (1-100)', 'desc' => 'Controls compression when saving JPEG files. 80-85 is the sweet spot, excellent quality with significant size reduction. Default: 82.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Minimum file size (KB)', 'desc' => 'Images smaller than this threshold are skipped entirely. Already-small files yield tiny absolute savings and re-compressing them at a lower quality setting can actually increase their size. Default: 100 KB.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Minimum gain threshold (%)', 'desc' => 'After compressing to a temp file, if the size saving is below this percentage the original is kept and the result discarded. Protects already-optimised images from being overwritten with a negligibly smaller (or larger) version. Default: 10%.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'Convert non-transparent PNGs to JPEG', 'desc' => 'Converts eligible PNGs (those with no transparency) to JPEG. A PNG screenshot at 200KB will typically become a 40-80KB JPEG with no visible difference at screen resolution.' ],
            ],
            '#ffd600'
        ); ?></div>
                    <div class="csc-card-body csc-settings-inline">
                        <label>Maximum width (px)      <input type="number" class="csc-setting" name="cscc_img_max_width"     value="<?php echo esc_attr( get_option( 'cscc_img_max_width',     1920 ) ); ?>" min="200"></label>
                        <label>Maximum height (px)     <input type="number" class="csc-setting" name="cscc_img_max_height"    value="<?php echo esc_attr( get_option( 'cscc_img_max_height',    1080 ) ); ?>" min="200"></label>
                        <label>JPEG quality (1 to 100)    <input type="number" class="csc-setting" name="cscc_img_quality"       value="<?php echo esc_attr( get_option( 'cscc_img_quality',       82   ) ); ?>" min="1" max="100"></label>
                        <label>Min. file size (KB)     <input type="number" class="csc-setting" name="cscc_img_min_size_kb"   value="<?php echo esc_attr( get_option( 'cscc_img_min_size_kb',  100  ) ); ?>" min="0"></label>
                        <label>Min. gain threshold (%) <input type="number" class="csc-setting" name="cscc_img_min_gain_pct"  value="<?php echo esc_attr( get_option( 'cscc_img_min_gain_pct',  10  ) ); ?>" min="0" max="99"></label>
                        <label class="csc-toggle-label">
                            <input type="checkbox" name="cscc_convert_png_to_jpg" value="1" <?php checked( get_option( 'cscc_convert_png_to_jpg', '1' ), '1' ); ?>>
                            Convert non-transparent PNGs to JPEG
                        </label>
                        <p class="csc-note">Recommended defaults: 1920&times;1080 · quality 82 · skip under 100 KB · skip if gain &lt; 10%. PNG conversion yields 40 to 70% size reduction on photographic images.</p>
                        <button class="csc-btn csc-btn-primary csc-save-btn" data-group="optimise">Save Settings</button>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-dark" style="display:flex;align-items:center;justify-content:space-between">
                    <span>Output Log</span>
                    <button class="btn-copy-log" style="background:rgba(2.5.395,255,0.15);border:none;color:#1a1a1a;font-size:12px;font-weight:600;padding:4px 10px;border-radius:4px;cursor:pointer;transition:background 0.15s" onmouseover="this.style.background='rgba(2.5.395,255,0.28)'" onmouseout="this.style.background='rgba(2.5.395,255,0.15)'">&#128203; Copy</button>
                </div>
                <div class="csc-card-body csc-terminal-wrap">
                    <div style="display:flex;align-items:center;gap:6px;padding:4px 12px;background:#0a1f00;border-bottom:2px solid #76ff03;border-radius:6px 6px 0 0"><span style="width:7px;height:7px;border-radius:50%;background:#76ff03;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#76ff03;opacity:.5;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#76ff03;opacity:.25;display:inline-block;flex-shrink:0"></span><span style="margin-left:8px;background:#76ff03;color:#0a1f00;font-family:monospace;font-size:10px;font-weight:800;letter-spacing:.1em;padding:2px 10px;border-radius:20px;text-transform:uppercase">⚡ Optimisation Console</span></div>
                    <pre class="csc-terminal" id="optimise-terminal">Ready. Press Dry Run to preview savings, then review the output log before optimising.</pre>
                </div>
            </div>
        </div>

        <!-- ═══ PNG to JPEG Converter ═══ -->
        <div class="csc-tab-content" id="tab-png-to-jpeg">
            <?php
            $cspj_chunk_mb     = cscc_get_cspj_chunk_mb();
            $cspj_server_max   = cscc_get_cspj_server_max_mb();
            ?>
            <div class="csc-cards-row">
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-purple"><span>Upload PNG Files</span> <?php cscc_explain_btn(
            'png-upload',
            'PNG to JPEG Upload, How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'Drag and drop', 'desc' => 'Drag PNG files onto the upload area, or click Browse to select files from your computer. Multiple files can be queued at once.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Transparency handling', 'desc' => 'PNG transparency is composited onto a white background before conversion to JPEG.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Chunked uploads', 'desc' => 'Large files are automatically split into chunks to stay within your server upload limits. Each chunk is reassembled server side before conversion.' ],
            ],
            '#00e5ff'
        ); ?></div>
                    <div class="csc-card-body">
                        <div class="cspj-upload-area" id="cspj-drop-zone">
                            <div class="cspj-upload-icon">📷</div>
                            <p>Drag & drop PNG files here</p>
                            <label for="cspj-file-input" id="cspj-browse-btn">📂 Browse to Select</label>
                            <p class="csc-note" id="cspj-limit-hint" style="margin-top:14px;text-align:center">Server request limit: <strong><?php echo esc_html( $cspj_server_max ); ?> MB</strong> · Chunk size: <strong><?php echo esc_html( $cspj_chunk_mb ); ?> MB</strong></p>
                            <input type="file" id="cspj-file-input" accept="image/png" multiple style="position:absolute; left:-9999px;">
                        </div>
                    </div>
                </div>

                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-green"><span>Conversion Settings</span> <?php cscc_explain_btn(
            'png-settings',
            'PNG to JPEG Settings, What each option does',
            [
            [ 'rec' => '✅ Recommended', 'name' => 'JPEG Quality (1 to 100)', 'desc' => 'Controls compression when saving JPEG files. 80 to 92 is the sweet spot, excellent visual quality with significant size reduction. Use 95+ for print, 60 to 75 for web thumbnails.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'Output Size', 'desc' => 'Choose a preset resolution or Custom dimensions. Enable Constrain proportions to auto calculate the missing dimension.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'Chunk Size', 'desc' => 'Controls how large each upload chunk is. Keep below your server request limit. Default 1.5 MB works on most hosts.' ],
            ],
            '#ffd600'
        ); ?></div>
                    <div class="csc-card-body csc-settings-inline">
                        <label>JPEG Quality
                            <div style="display:flex;align-items:center;gap:12px;width:100%">
                                <input type="range" id="cspj-quality" min="1" max="100" value="90" style="flex:1;accent-color:var(--csc-accent2)">
                                <span id="cspj-quality-val" style="font-size:20px;font-weight:800;color:var(--csc-accent);min-width:36px;text-align:center">90</span>
                            </div>
                        </label>
                        <label>Output Size
                            <select id="cspj-size" style="width:100%;padding:8px 10px;border:1px solid var(--csc-border);border-radius:5px;font-size:13px;background:#f8f9fc">
                                <option value="original">Original size (unchanged)</option>
                                <option value="3840x2160">4K, 3840 × 2160</option>
                                <option value="2560x1440">2K / QHD, 2560 × 1440</option>
                                <option value="1920x1080">Full HD, 1920 × 1080</option>
                                <option value="1280x720">HD, 1280 × 720</option>
                                <option value="1024x768">XGA, 1024 × 768</option>
                                <option value="800x600">SVGA, 800 × 600</option>
                                <option value="640x480">VGA, 640 × 480</option>
                                <option value="512.5.39">Square, 512 × 512</option>
                                <option value="256x256">Square, 256 × 256</option>
                                <option value="custom">Custom…</option>
                            </select>
                        </label>
                        <div id="cspj-custom-size" style="display:none;margin-top:4px">
                            <label style="gap:4px">
                                <input type="number" id="cspj-custom-w" placeholder="Width px" class="csc-setting" style="width:90px">
                                ×
                                <input type="number" id="cspj-custom-h" placeholder="Height px" class="csc-setting" style="width:90px">
                            </label>
                            <label class="csc-toggle-label" style="margin-top:6px">
                                <input type="checkbox" id="cspj-constrain" checked> Constrain proportions
                            </label>
                        </div>
                        <label>Chunk Size
                            <div style="display:flex;align-items:center;gap:8px">
                                <input type="number" id="cspj-chunk-mb" min="0.25" max="1.95" step="0.05" value="<?php echo esc_attr( $cspj_chunk_mb ); ?>" class="csc-setting" style="width:80px">
                                <span style="font-size:12px;color:var(--csc-muted)">MB</span>
                                <button class="csc-btn csc-btn-primary" id="cspj-save-chunkmb" style="padding:6px 14px;font-size:12px">Save</button>
                                <span id="cspj-save-status" style="font-size:12px"></span>
                            </div>
                        </label>
                        <p class="csc-note">Server limit: <?php echo esc_html( $cspj_server_max ); ?> MB. Keep chunk size below the server limit. Default is 1.5 MB.</p>
                    </div>
                </div>
            </div>

            <div class="csc-card" id="cspj-queue-card" style="display:none">
                <div class="csc-card-header csc-card-header-amber"><span>File Queue</span></div>
                <div class="csc-card-body">
                    <div id="cspj-file-list"></div>
                    <div class="csc-button-row">
                        <button class="csc-btn csc-btn-danger" id="cspj-convert-all">⚡ Convert All</button>
                        <button class="csc-btn csc-btn-secondary" id="cspj-clear-all">Clear Queue</button>
                    </div>
                </div>
            </div>

            <div class="csc-card" id="cspj-results" style="display:none">
                <div class="csc-card-header csc-card-header-green"><span>Converted Files</span></div>
                <div class="csc-card-body">
                    <div id="cspj-results-list"></div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-dark">Debug Console</div>
                <div class="csc-card-body csc-terminal-wrap">
                    <div style="display:flex;align-items:center;gap:6px;padding:4px 12px;background:#1a0533;border-bottom:2px solid #ce93d8;border-radius:6px 6px 0 0"><span style="width:7px;height:7px;border-radius:50%;background:#ce93d8;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#ce93d8;opacity:.5;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#ce93d8;opacity:.25;display:inline-block;flex-shrink:0"></span><span style="margin-left:8px;background:#ce93d8;color:#1a0533;font-family:monospace;font-size:10px;font-weight:800;letter-spacing:.1em;padding:2px 10px;border-radius:20px;text-transform:uppercase">📷 Converter Console</span>
                        <span style="margin-left:auto;display:flex;gap:6px">
                            <button id="cspj-debug-copy" style="background:rgba(2.5.3955,2.5.1.15);border:1px solid rgba(2.5.3955,2.5.1.3);border-radius:6px;color:#fff;font-size:11px;font-weight:600;padding:3px 10px;cursor:pointer">📋 Copy</button>
                            <button id="cspj-debug-clear" style="background:rgba(2.5.3955,2.5.1.15);border:1px solid rgba(2.5.3955,2.5.1.3);border-radius:6px;color:#fff;font-size:11px;font-weight:600;padding:3px 10px;cursor:pointer">🗑 Clear</button>
                            <button id="cspj-debug-toggle" style="background:rgba(2.5.3955,2.5.1.15);border:1px solid rgba(2.5.3955,2.5.1.3);border-radius:6px;color:#fff;font-size:11px;font-weight:600;padding:3px 10px;cursor:pointer">▼</button>
                        </span>
                    </div>
                    <div id="cspj-debug-env" style="padding:10px 14px;background:#f8f9fc;border-bottom:1px solid var(--csc-border);font-size:11px;color:var(--csc-muted);font-family:monospace;line-height:1.7"></div>
                    <pre class="csc-terminal" id="cspj-debug-log" style="min-height:120px;max-height:320px">Ready. Drop PNG files above to get started.</pre>
                </div>
            </div>

            <!-- Rename popup -->
            <div id="cspj-popup-rename" style="display:none;position:fixed;inset:0;z-index:100002;background:rgba(0,0,0,0.55);align-items:center;justify-content:center;padding:16px">
                <div class="csc-modal">
                    <div class="csc-modal-title">💾 Save to Media Library</div>
                    <div class="csc-modal-body">
                        <p>Enter a filename for this image in the Media Library. The <code>.jpg</code> extension will be added automatically.</p>
                        <input type="text" id="cspj-rename-input" style="width:100%;padding:9px 12px;border:2px solid var(--csc-border);border-radius:6px;font-size:14px;box-sizing:border-box" placeholder="e.g. my-product-hero">
                        <div id="cspj-rename-error" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:8px 12px;font-size:13px;color:#991b1b;margin-top:8px"></div>
                    </div>
                    <div class="csc-modal-footer">
                        <button id="cspj-rename-cancel" class="csc-btn csc-btn-cancel">Cancel</button>
                        <button id="cspj-rename-confirm" class="csc-btn csc-btn-primary">💾 Add to Library</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Site Health ═══ -->
        <div class="csc-tab-content active" id="tab-site-health">
            <div class="csc-card">
                <div class="csc-card-header" style="background:linear-gradient(135deg,#1b5e20 0%,#43a047 100%)">
                    <span>📊 Site Health Overview</span>
                    <?php cscc_explain_btn( 'site-health', 'Site Health Metrics', array(
                        array(
                            'name' => 'Disk Storage Tracking',
                            'rec'  => 'Recommended',
                            'desc' => 'Measures wp-content disk usage weekly. Calculates growth rate over 3 months and estimates weeks until disk full. Green = 6+ months remaining, Amber = 3 to 6 months, Red = under 3 months.',
                        ),
                        array(
                            'name' => 'CPU Peak Monitoring',
                            'rec'  => 'Recommended',
                            'desc' => 'Records the maximum CPU usage percentage each hour. With sysstat installed, captures per minute peaks from sar (so brief spikes are caught). Without sysstat, falls back to an instantaneous load average snapshot.',
                        ),
                        array(
                            'name' => 'Memory Peak Monitoring',
                            'rec'  => 'Recommended',
                            'desc' => 'Records the maximum memory usage percentage each hour. With sysstat installed, captures per minute peaks from sar. Without sysstat, falls back to an instantaneous /proc/meminfo reading.',
                        ),
                        array(
                            'name' => 'sysstat (sar)',
                            'rec'  => 'Recommended',
                            'desc' => 'sysstat continuously samples CPU and memory every 10 minutes in the background, giving accurate peak values rather than single point snapshots. Without sysstat, the plugin falls back to instantaneous readings which may show 0% on lightly loaded servers.\n\nStep 1: Install sysstat.\nAmazon Linux / RHEL: sudo yum install sysstat -y\nUbuntu / Debian: sudo apt install sysstat -y\n\nStep 2: Enable the service and the collection timer.\nsudo systemctl enable sysstat && sudo systemctl start sysstat\nsudo systemctl enable sysstat-collect.timer && sudo systemctl start sysstat-collect.timer\n\nStep 3: Force an immediate first collection (optional, otherwise wait 10 minutes).\nsudo /usr/lib64/sa/sa1 1 1\n\nStep 4: Verify data is being collected.\nLC_ALL=C /usr/bin/sar -u 2>&1 | tail -5\n\nYou should see CPU usage lines with timestamps. If you only see a header with no data, the collection timer is not running. The sysstat-collect.timer is the part that actually triggers data collection every 10 minutes. On Amazon Linux 2023, enabling sysstat alone is not enough; you must also enable sysstat-collect.timer separately.\n\nUse the Test Sysstat button below to verify the plugin can read sar data.',
                        ),
                        array(
                            'name' => 'Data Retention',
                            'rec'  => 'Info',
                            'desc' => 'Hourly metrics and weekly snapshots are automatically expired after 6 months (180 days). Stored in wp_options with autoload disabled to avoid memory overhead.',
                        ),
                        array(
                            'name' => 'Max Resource Percentage',
                            'rec'  => 'Info',
                            'desc' => 'Each hourly sample stores max(cpu%, mem%) as the single worst case resource metric. This helps identify whether the server is becoming constrained by CPU, memory, or both.',
                        ),
                    ) ); ?>
                </div>
                <div class="csc-card-body" id="csc-health-overview">
                    <div id="csc-health-loading" style="text-align:center;padding:30px;color:#888">Loading health metrics…</div>
                    <div id="csc-health-content" style="display:none">
                        <!-- RAG indicator -->
                        <div id="csc-health-rag-bar" style="display:flex;align-items:center;gap:16px;padding:16px 20px;border-radius:8px;margin-bottom:20px">
                            <div id="csc-health-rag-dot" style="width:24px;height:24px;border-radius:50%;flex-shrink:0"></div>
                            <div>
                                <div id="csc-health-rag-label" style="font-size:16px;font-weight:800"></div>
                                <div id="csc-health-rag-detail" style="font-size:13px;margin-top:2px;opacity:0.8"></div>
                            </div>
                        </div>

                        <!-- Row 1: Disk Storage (brown theme) -->
                        <div style="background:linear-gradient(135deg,#efebe9 0%,#faf7f5 100%);border:1px solid #bcaaa4;border-radius:10px;padding:16px 20px;margin-bottom:14px">
                            <div style="font-size:13px;font-weight:800;color:#4e342e;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:12px">💾 Disk Storage</div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px">
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">wp-content</div>
                                    <div class="csc-health-metric-value" id="hm-disk-used" style="color:#4e342e">&middot;</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Disk Free</div>
                                    <div class="csc-health-metric-value" id="hm-disk-free" style="color:#4e342e">&middot;</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Disk Total</div>
                                    <div class="csc-health-metric-value" id="hm-disk-total" style="color:#4e342e">&middot;</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Database</div>
                                    <div class="csc-health-metric-value" id="hm-db-size" style="color:#4e342e">&middot;</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Growth / Week</div>
                                    <div class="csc-health-metric-value" id="hm-growth" style="color:#4e342e">&middot;</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Est. Time to Storage Full</div>
                                    <div class="csc-health-metric-value" id="hm-weeks-left" style="color:#4e342e">&middot;</div>
                                </div>
                            </div>
                        </div>

                        <!-- Row 2: CPU (orange theme) -->
                        <div style="background:linear-gradient(135deg,#fff3e0 0%,#fffaf4 100%);border:1px solid #ffcc80;border-radius:10px;padding:16px 20px;margin-bottom:14px">
                            <div style="font-size:13px;font-weight:800;color:#e65100;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:12px">⚡ CPU</div>
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Current</div>
                                    <div class="csc-health-metric-value" id="hm-cpu-now" style="color:#bf360c">&middot;</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Peak (24h)</div>
                                    <div class="csc-health-metric-value" id="hm-cpu-24h" style="color:#bf360c">&middot;</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Peak (7d)</div>
                                    <div class="csc-health-metric-value" id="hm-cpu-7d" style="color:#bf360c">&middot;</div>
                                </div>
                            </div>
                        </div>

                        <!-- Row 3: Memory (purple theme) -->
                        <div style="background:linear-gradient(135deg,#f3e5f5 0%,#faf5fc 100%);border:1px solid #ce93d8;border-radius:10px;padding:16px 20px;margin-bottom:20px">
                            <div style="font-size:13px;font-weight:800;color:#7b1fa2;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:12px">🧠 Memory</div>
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Current</div>
                                    <div class="csc-health-metric-value" id="hm-mem-now" style="color:#4a148c">&middot;</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Peak (24h)</div>
                                    <div class="csc-health-metric-value" id="hm-mem-24h" style="color:#4a148c">&middot;</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Peak (7d)</div>
                                    <div class="csc-health-metric-value" id="hm-mem-7d" style="color:#4a148c">&middot;</div>
                                </div>
                            </div>
                        </div>

                        <!-- Data collection status -->
                        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px 16px;background:#f8f9fc;border-radius:6px;border:1px solid #e0e0e0;margin-bottom:10px">
                            <span style="font-size:12px;color:#50575e">📈 Hourly samples: <strong id="hm-hourly-count">0</strong></span>
                            <span style="font-size:12px;color:#50575e">📅 Weekly snapshots: <strong id="hm-weekly-count">0</strong></span>
                            <span style="font-size:12px;color:#50575e">🕐 Last hourly: <strong id="hm-last-hourly">&middot;</strong></span>
                            <span style="font-size:12px;color:#50575e">Last Collected: <strong id="hm-last-weekly">&middot;</strong></span>
                            <span style="font-size:12px;color:#50575e">📊 Data span: <strong id="hm-data-span">&middot;</strong> weeks</span>
                        </div>

                        <!-- sysstat status -->
                        <div id="csc-sysstat-status" style="padding:12px 16px;border-radius:6px;border:1px solid #e0e0e0;margin-bottom:16px;font-size:12px;display:none">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <span id="csc-sysstat-icon" style="font-size:14px">&middot;</span>
                                <strong id="csc-sysstat-label">Checking sysstat...</strong>
                                <span id="csc-sysstat-detail" style="color:#50575e"></span>
                            </div>
                            <div id="csc-sysstat-instructions" style="display:none;margin-top:8px;padding:8px 12px;background:#fff3e0;border:1px solid #ffe0b2;border-radius:4px;font-family:monospace;font-size:11px;word-break:break-all"></div>
                        </div>

                        <div class="csc-button-row" style="gap:10px">
                            <button class="csc-btn csc-btn-secondary" id="btn-health-refresh">🔄 Refresh</button>
                            <button class="csc-btn csc-btn-primary" id="btn-health-collect">📊 Collect Metrics Now</button>
                            <button class="csc-btn csc-btn-secondary" id="btn-sysstat-test">🔧 Test Sysstat</button>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Space Report ═══ -->
        <div class="csc-tab-content" id="tab-space-report">
            <div class="csc-card">
                <div class="csc-card-header" style="background:linear-gradient(135deg,#0d47a1 0%,#1565c0 100%)">
                    <span>Uploads Space Report</span>
                    <button id="btn-space-scan" class="csc-btn csc-btn-secondary" style="margin-left:auto">Scan</button>
                </div>
                <div class="csc-card-body" style="padding:0">
                    <div id="space-report-loading" style="display:none;padding:24px;text-align:center;color:#666">Scanning&hellip;</div>
                    <div id="space-report-error"   style="display:none;padding:16px;color:#c62828"></div>

                    <div id="space-report-content" style="display:none">
                        <!-- Insight cards, shown only at root level -->
                        <div id="space-insights" style="display:none;padding:16px;display:flex;flex-direction:column;gap:10px;border-bottom:1px solid #e0e0e0"></div>

                        <!-- Breadcrumb -->
                        <div id="space-breadcrumb" style="padding:10px 16px;background:#f5f7fa;border-bottom:1px solid #e0e0e0;font-size:13px;display:flex;align-items:center;gap:6px;flex-wrap:wrap"></div>

                        <!-- Summary bar -->
                        <div id="space-summary" style="padding:12px 16px;background:#e8f0fe;font-size:13px;color:#1a237e;border-bottom:1px solid #c5cae9"></div>

                        <!-- Dirs table -->
                        <div style="overflow-x:auto">
                        <table id="space-dirs-table" style="width:100%;border-collapse:collapse;font-size:13px">
                            <thead>
                                <tr style="background:#f0f4ff">
                                    <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #c5cae9">Folder</th>
                                    <th style="padding:8px 12px;text-align:right;border-bottom:2px solid #c5cae9;white-space:nowrap">Size</th>
                                    <th style="padding:8px 12px;text-align:right;border-bottom:2px solid #c5cae9;white-space:nowrap">Files</th>
                                    <th style="padding:8px 12px;text-align:right;border-bottom:2px solid #c5cae9;white-space:nowrap">% of Total</th>
                                    <th style="padding:8px 12px;text-align:center;border-bottom:2px solid #c5cae9;white-space:nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="space-dirs-body"></tbody>
                        </table>
                        </div>

                        <!-- Largest files (recursive, drill-down only) -->
                        <div id="space-top-files-wrap" style="display:none">
                            <div style="padding:8px 12px;background:#f5f7fa;border-top:2px solid #c5cae9;font-size:12px;font-weight:700;color:#1a237e;letter-spacing:.05em;text-transform:uppercase">Largest Files (all subfolders)</div>
                            <div style="overflow-x:auto">
                            <table style="width:100%;border-collapse:collapse;font-size:13px">
                                <thead>
                                    <tr style="background:#f0f4ff">
                                        <th style="padding:7px 12px;text-align:left;border-bottom:2px solid #c5cae9">Path</th>
                                        <th style="padding:7px 12px;text-align:right;border-bottom:2px solid #c5cae9;white-space:nowrap">Size</th>
                                        <th style="padding:7px 12px;text-align:left;border-bottom:2px solid #c5cae9">Type</th>
                                    </tr>
                                </thead>
                                <tbody id="space-top-files-body"></tbody>
                            </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Storage Recycle Bin -->
            <div class="csc-card" id="space-recycle-bin-card" style="margin-top:16px">
                <div class="csc-card-header" style="background:linear-gradient(135deg,#4a148c 0%,#7b1fa2 100%);display:flex;align-items:center">
                    <span>&#128465;&#65039; Storage Recycle Bin</span>
                    <button id="btn-storage-bin-empty" class="csc-btn csc-btn-sm" style="margin-left:auto;background:#c62828;color:#fff;border-color:#c62828">Empty Bin</button>
                </div>
                <div class="csc-card-body" style="padding:0">
                    <div id="storage-bin-empty-msg" style="padding:16px;color:#888;text-align:center;font-size:13px">Recycle bin is empty.</div>
                    <div id="storage-bin-list-wrap" style="display:none;overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:13px">
                            <thead>
                                <tr style="background:#f9f0ff">
                                    <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ce93d8">Folder</th>
                                    <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ce93d8">Original Path</th>
                                    <th style="padding:8px 12px;text-align:right;border-bottom:2px solid #ce93d8;white-space:nowrap">Size</th>
                                    <th style="padding:8px 12px;text-align:center;border-bottom:2px solid #ce93d8;white-space:nowrap">Trashed</th>
                                    <th style="padding:8px 12px;text-align:center;border-bottom:2px solid #ce93d8;white-space:nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="storage-bin-body"></tbody>
                        </table>
                    </div>
                    <div id="storage-bin-result" style="display:none;padding:10px 16px;font-size:13px"></div>
                </div>
            </div>
        </div>

        <!-- ═══ Cron ═══ -->
        <div class="csc-tab-content" id="tab-cron">
            <div class="csc-card">
                <div class="csc-card-header csc-card-header-blue">About CloudScale Cleanup</div>
                <div class="csc-card-body csc-about">
                    <div class="csc-stats-grid" style="grid-template-columns:repeat(5,1fr)">
                        <div class="csc-stat-box">
                            <div class="csc-stat-label">Last DB Cleanup</div>
                            <div class="csc-stat-value"><?php echo esc_html( get_option( 'cscc_last_db_cleanup', 'Never' ) ); ?></div>
                        </div>
                        <div class="csc-stat-box">
                            <div class="csc-stat-label">Last Media Cleanup</div>
                            <div class="csc-stat-value"><?php echo esc_html( get_option( 'cscc_last_img_cleanup', 'Never' ) ); ?></div>
                        </div>
                        <div class="csc-stat-box">
                            <div class="csc-stat-label">Last Optimisation</div>
                            <div class="csc-stat-value"><?php echo esc_html( get_option( 'cscc_last_img_optimise', 'Never' ) ); ?></div>
                        </div>
                        <div class="csc-stat-box">
                            <div class="csc-stat-label">PNG Conversions</div>
                            <div class="csc-stat-value"><?php echo esc_html( get_option( 'cscc_total_png_conversions', '0' ) ); ?></div>
                        </div>
                        <div class="csc-stat-box">
                            <div class="csc-stat-label">Version</div>
                            <div class="csc-stat-value"><?php echo esc_html( CLOUDSCALE_CLEANUP_VERSION ); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="csc-card" id="csc-cron-management">
                <div class="csc-card-header csc-card-header-teal">
                    <span>Cron Management</span>
                    <?php cscc_explain_btn(
                        'cron-management',
                        'Cron Management, What is this?',
                        array(
                            array(
                                'name' => 'WordPress Pseudo-Cron (WP-Cron)',
                                'rec'  => 'Info',
                                'desc' => 'WordPress has no real scheduler, it simulates cron by piggy-backing on page visits. When a visitor loads a page, WordPress checks if any scheduled jobs are due and fires them inline. On low-traffic sites this means jobs can run late or not at all.',
                            ),
                            array(
                                'name' => 'Real Server Cron',
                                'rec'  => 'Recommended',
                                'desc' => 'A real server cron (crontab entry) calls wp-cron.php on a fixed schedule regardless of traffic. Set DISABLE_WP_CRON=true in wp-config.php once a server cron is in place to stop the pseudo-cron fallback.',
                            ),
                            array(
                                'name' => 'Cron Congestion',
                                'rec'  => 'Watch out',
                                'desc' => 'When 3 or more jobs fire within the same 5-minute window they compete for CPU, memory and database connections. This can slow page loads or trigger PHP timeouts. Stagger recurring jobs or move heavy tasks to off-peak hours.',
                            ),
                            array(
                                'name' => 'Orphaned Cron Jobs',
                                'rec'  => 'Clean up',
                                'desc' => 'When a plugin is deactivated or deleted its cron entries are often left behind in the database. These fire on schedule, find no callback, and waste resources. Use the recycle bin to remove them safely.',
                            ),
                        )
                    ); ?>
                </div>
                <div class="csc-card-body">

                    <!-- Health banner, rendered by JS after AJAX -->
                    <div id="csc-cron-health-banner" class="csc-cron-health-loading">
                        <span class="csc-cron-spinner"></span> Loading cron status&hellip;
                    </div>

                    <!-- Server cron setup -->
                    <div class="csc-cron-setup-box">
                        <strong>Real Server Cron Setup</strong>
                        <p class="csc-note" style="margin:6px 0 8px">WordPress pseudo-cron fires only on page visits, jobs may run late on low-traffic sites. Add a real server cron for reliable, clock-accurate scheduling:</p>
                        <div class="csc-cron-cmd-row">
                            <code id="csc-cron-cmd">*/5 * * * * curl -s "<?php echo esc_url( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?>" &gt; /dev/null 2&gt;&amp;1</code>
                            <button type="button" id="btn-copy-cron-cmd" class="csc-btn csc-btn-secondary csc-btn-sm">Copy</button>
                        </div>
                        <p class="csc-note" style="margin-top:6px">Add to your server's crontab (<code>crontab -e</code>), then add <code>define('DISABLE_WP_CRON', true);</code> to <code>wp-config.php</code>.</p>
                    </div>

                    <!-- 24-hour timeline -->
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                        <div class="csc-cron-section-title" style="margin-bottom:0">24-Hour Job Timeline</div>
                        <?php cscc_explain_btn(
                            'cron-timeline',
                            '24-Hour Job Timeline, How to read this',
                            array(
                                array(
                                    'name' => 'What the bars show',
                                    'rec'  => 'Info',
                                    'desc' => "Each bar represents one scheduled firing of a cron job. The bar's horizontal position is when it runs; its width is proportional to the estimated execution time (half the interval, capped at 5 minutes). Bars that visually overlap mean the jobs run at the same time.",
                                ),
                                array(
                                    'name' => 'X-axis (time)',
                                    'rec'  => 'Info',
                                    'desc' => 'The timeline spans 24 hours from Now (left edge). Gridlines are snapped to clock hours in your local time zone. The "Now" marker shows the current moment.',
                                ),
                                array(
                                    'name' => 'Red congestion bands',
                                    'rec'  => 'Watch out',
                                    'desc' => 'A red band highlights any 5-minute window where 3 or more jobs are scheduled to fire. Concurrent jobs compete for CPU and database connections and can slow the site or cause timeouts.',
                                ),
                                array(
                                    'name' => 'Plugin column & status dot',
                                    'rec'  => 'Info',
                                    'desc' => "The coloured dot next to each job name indicates its plugin's status:\n● Green, plugin active\n● Orange, installed but inactive\n● Red, not installed (orphaned hook)\n● Blue, WordPress Core",
                                ),
                                array(
                                    'name' => 'Trash icon',
                                    'rec'  => 'Optional',
                                    'desc' => 'Click the bin icon next to any job to move it to the Cron Recycle Bin. It is not permanently deleted, you can restore it from the bin section below.',
                                ),
                            )
                        ); ?>
                    </div>
                    <p class="csc-note" style="margin:0 0 8px">Each bar marks a scheduled execution (width = estimated run time). Red bands = Cron Congestion, 3 or more jobs firing within the same 5-minute window.</p>
                    <div id="csc-cron-timeline-wrap" class="csc-cron-timeline-wrap">
                        <div id="csc-cron-timeline-labels" class="csc-cron-timeline-labels"></div>
                        <canvas id="csc-cron-timeline"></canvas>
                    </div>
                    <div id="csc-cron-congestion-warn" class="csc-cron-congestion-alert" style="display:none"></div>

                    <!-- Cron job queue table -->
                    <div style="display:flex;align-items:center;gap:10px;margin-top:20px;margin-bottom:6px">
                        <div class="csc-cron-section-title" style="margin-bottom:0">Cron Job Queue</div>
                        <?php cscc_explain_btn(
                            'cron-events',
                            'Cron Job Queue, What is this?',
                            array(
                                array(
                                    'name' => 'What is the Cron Job Queue?',
                                    'rec'  => 'Info',
                                    'desc' => 'This is the complete list of background tasks that WordPress and your plugins have scheduled to run automatically. Every plugin that needs to do something on a timer, send emails, clean up files, check for updates, sync data, registers a job here. WordPress fires them in the background when a visitor loads a page (pseudo-cron) or when a real server cron calls wp-cron.php.',
                                ),
                                array(
                                    'name' => 'Hook',
                                    'rec'  => 'Info',
                                    'desc' => 'The internal action name WordPress calls when the job fires. This is what you would search for in plugin code to find where the job is defined.',
                                ),
                                array(
                                    'name' => 'Plugin',
                                    'rec'  => 'Info',
                                    'desc' => 'The plugin that registered this hook, resolved by matching the hook name prefix against a list of 200+ known plugins. The status badge shows whether the plugin is Active, Installed (inactive), Not installed (orphaned), or WordPress Core.',
                                ),
                                array(
                                    'name' => 'Schedule',
                                    'rec'  => 'Info',
                                    'desc' => 'How often the job repeats, e.g. hourly, twicedaily, daily. "one-time" means it fires once and does not recur.',
                                ),
                                array(
                                    'name' => 'Next Run',
                                    'rec'  => 'Info',
                                    'desc' => 'How long until the job fires next. "Overdue" means it was due in the past and has not fired yet, usually because there was no page visit to trigger pseudo-cron.',
                                ),
                                array(
                                    'name' => 'Last Run',
                                    'rec'  => 'Info',
                                    'desc' => "How long the job took the last time it ran, and how long ago that was. This data is collected by CloudScale Cleanup's lightweight timing hooks and only appears after the job has run at least once since activation.",
                                ),
                                array(
                                    'name' => 'Orphaned jobs (Not installed)',
                                    'rec'  => 'Clean up',
                                    'desc' => 'If a plugin was deleted without being deactivated first, its cron entries remain in the database. They fire on schedule, find no callback, and waste resources. Use the bin icon to move them to the recycle bin.',
                                ),
                            )
                        ); ?>
                    </div>
                    <div id="csc-cron-events-wrap" style="overflow-x:auto">
                        <table class="csc-cron-events-table">
                            <thead>
                                <tr>
                                    <th>Hook</th>
                                    <th>Plugin</th>
                                    <th>Schedule</th>
                                    <th>Next Run</th>
                                    <th>Last Run</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="csc-cron-events-body">
                                <tr><td colspan="7" style="text-align:center;padding:12px;color:#666">Loading&hellip;</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" id="btn-cron-refresh" class="csc-btn csc-btn-secondary" style="margin-top:10px">Refresh</button>

                    <!-- Manual triggers -->
                    <div class="csc-cron-section-title" style="margin-top:20px">Manual Triggers</div>
                    <p class="csc-note" style="margin:0 0 10px">Fire a CSC scheduled job immediately, without waiting for the next scheduled time.</p>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="button" id="btn-cron-run-db"  class="csc-btn csc-btn-secondary" data-hook="cscc_scheduled_db_cleanup">&#9654; Run DB Cleanup Now</button>
                        <button type="button" id="btn-cron-run-img" class="csc-btn csc-btn-secondary" data-hook="cscc_scheduled_img_cleanup">&#9654; Run Media Cleanup Now</button>
                    </div>
                    <div id="csc-cron-run-result" style="display:none;margin-top:10px;font-size:13px;padding:8px 12px;border-radius:5px"></div>

                    <!-- Cron Recycle Bin -->
                    <div class="csc-cron-section-title" style="margin-top:24px">&#128465; Cron Recycle Bin</div>
                    <p class="csc-note" style="margin:0 0 8px">Deleted cron jobs. Restore to re-schedule, or permanently delete.</p>
                    <div id="csc-cron-recycle-wrap" style="overflow-x:auto">
                        <table class="csc-cron-events-table">
                            <thead>
                                <tr>
                                    <th>Hook</th>
                                    <th>Schedule</th>
                                    <th>Was Due</th>
                                    <th>Deleted</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="csc-cron-recycle-body">
                                <tr><td colspan="5" style="text-align:center;padding:12px;color:#aaa;font-style:italic">Recycle bin is empty.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="csc-cron-recycle-result" style="display:none;margin-top:8px;font-size:13px;padding:8px 12px;border-radius:5px"></div>

                </div>
            </div>
        </div>

        <div id="csc-save-notice" class="csc-save-notice" style="display:none">Settings saved.</div>

        <!-- Collect Now confirmation modal, outside all cards to avoid overflow:hidden clipping -->
        <div id="csc-collect-modal" style="display:none;position:fixed;inset:0;z-index:100001;background:rgba(0,0,0,0.55);align-items:center;justify-content:center;padding:16px">
            <div class="csc-modal">
                <div class="csc-modal-title">📊 Collect Metrics Now</div>
                <div class="csc-modal-warning">Both collection jobs will run immediately on the server.</div>
                <div class="csc-modal-body">
                    <p>This will immediately run both scheduled collection jobs:</p>
                    <ul>
                        <li><strong>Hourly sample</strong>, records current CPU % and memory % into the rolling store</li>
                        <li><strong>Weekly disk snapshot</strong>, records current disk used/free/total (needed to calculate storage growth rate and Est. Wks to Full)</li>
                    </ul>
                    <p>Normally these run automatically on cron. Use this to seed data on a fresh install or force an immediate reading.</p>
                </div>
                <div class="csc-modal-footer">
                    <button id="btn-collect-cancel" class="csc-btn csc-btn-cancel">Cancel</button>
                    <button id="btn-collect-confirm" class="csc-btn csc-btn-primary">Yes, Collect Now</button>
                </div>
            </div>
        </div>
        <!-- Move to Recycle confirmation modal -->
        <div id="csc-img-move-modal" style="display:none;position:fixed;inset:0;z-index:100001;background:rgba(0,0,0,0.55);align-items:center;justify-content:center;padding:16px">
            <div class="csc-modal">
                <div class="csc-modal-title">♻️ Move to Recycle</div>
                <p id="csc-img-move-msg" class="csc-modal-warning"></p>
                <div class="csc-modal-body">
                    <p>Files are <strong>moved, not deleted</strong>, originals and all thumbnails are copied to a protected recycle folder on disk.</p>
                    <p>WordPress database records are removed so the items no longer appear in the Media Library.</p>
                    <p>This is <strong>fully reversible</strong>, use <em>Restore All</em> or the Recycle Bin browser to bring any item back. Nothing is permanently deleted unless you choose <em>Permanently Delete</em>.</p>
                </div>
                <div class="csc-modal-footer">
                    <button id="btn-recycle-cancel" class="csc-btn csc-btn-cancel">Cancel</button>
                    <button id="btn-recycle-confirm" class="csc-btn csc-btn-primary">Move to Recycle</button>
                </div>
            </div>
        </div>

    </div>

    <?php
}

