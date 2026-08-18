<?php
/**
 * CloudScale Cleanup, Uninstall
 *
 * Removes all plugin options and clears scheduled cron events when the plugin
 * is deleted via Plugins > Delete.
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Settings.
$options = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- file-scope variables in uninstall.php only; no namespace conflict risk
    'cscc_loaded_version',
    'cscc_clean_revisions',
    'cscc_clean_drafts',
    'cscc_clean_trashed',
    'cscc_clean_autodrafts',
    'cscc_clean_transients',
    'cscc_clean_orphan_post',
    'cscc_clean_orphan_user',
    'cscc_clean_spam_comments',
    'cscc_clean_trash_comments',
    'cscc_post_revisions_age',
    'cscc_trash_age',
    'cscc_drafts_age',
    'cscc_autodraft_age',
    'cscc_spam_comments_age',
    'cscc_trash_comments_age',
    'cscc_schedule_db_enabled',
    'cscc_schedule_db_days',
    'cscc_schedule_db_hour',
    'cscc_schedule_img_enabled',
    'cscc_schedule_img_days',
    'cscc_schedule_img_hour',
    'cscc_img_max_width',
    'cscc_img_max_height',
    'cscc_img_quality',
    'cscc_convert_png_to_jpg',
    // Both names: the key was renamed for WordPress.org's one-prefix-per-plugin rule and the
    // old row is deliberately left in place on upgrade, so uninstall is what finally removes it.
    'cscc_chunk_mb',
    'cspj_chunk_mb',
    // Runtime data.
    'cscc_last_db_cleanup',
    'cscc_last_img_cleanup',
    'cscc_last_img_optimise',
    'cscc_last_scheduled_db_cleanup',
    'cscc_last_scheduled_img_cleanup',
    'cscc_total_png_conversions',
    'cscc_health_hourly_metrics',
    'cscc_health_weekly_snapshots',
    // Cron management data.
    'cscc_cron_run_log',
    'cscc_cron_recycle_bin',
];

foreach ( $options as $option ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- file-scope variables in uninstall.php only; no namespace conflict risk
    delete_option( $option );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'cscc_scheduled_db_cleanup' );
wp_clear_scheduled_hook( 'cscc_scheduled_img_cleanup' );
wp_clear_scheduled_hook( 'cscc_cleanup_chunks' );
wp_clear_scheduled_hook( 'cspj_cleanup_chunks' ); // pre-rename name; harmless if never scheduled
wp_clear_scheduled_hook( 'cscc_health_hourly_collect' );
wp_clear_scheduled_hook( 'cscc_health_weekly_snapshot' );
