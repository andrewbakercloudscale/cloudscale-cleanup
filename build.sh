#!/bin/bash
# Build cloudscale-cleanup.zip from the repo directory
# Creates a zip with cloudscale-cleanup/ as the top level folder
# which is the structure WordPress expects for plugin upload
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# Guard against diverged history caused by parallel sessions on the same machine.
if git -C "$SCRIPT_DIR" fetch origin main --quiet 2>/dev/null; then
    LOCAL=$(git -C "$SCRIPT_DIR" rev-parse HEAD)
    REMOTE=$(git -C "$SCRIPT_DIR" rev-parse origin/main)
    if [ "$LOCAL" != "$REMOTE" ] && git -C "$SCRIPT_DIR" merge-base --is-ancestor "$LOCAL" "$REMOTE" 2>/dev/null; then
        echo "⚠ Remote is ahead of local — pulling before build to avoid drift..."
        git -C "$SCRIPT_DIR" pull --ff-only origin main
        echo "✓ Pulled. Continuing build."
    fi
fi

# Load shared Claude model config
GITHUB_DIR="$(dirname "$SCRIPT_DIR")"
# shellcheck source=../.claude-config.sh
source "$GITHUB_DIR/.claude-config.sh"
REPO_DIR="$SCRIPT_DIR"
ZIP_FILE="$SCRIPT_DIR/cloudscale-cleanup.zip"
PLUGIN_NAME="cloudscale-cleanup"
TEMP_DIR=$(mktemp -d)

echo "Building plugin zip from $REPO_DIR..."
# ── Auto-increment patch version ─────────────────────────────────────────────
MAIN_PHP=$(grep -rl "^ \* Version:" "$REPO_DIR" --include="*.php" 2>/dev/null | grep -v "repo/" | head -1)
if [ -z "$MAIN_PHP" ]; then
  echo "ERROR: Could not find main plugin PHP file with Version header."
  exit 1
fi
CURRENT_VER=$(grep "^ \* Version:" "$MAIN_PHP" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
if [ -z "$CURRENT_VER" ]; then
  echo "ERROR: Could not extract version from $MAIN_PHP"
  exit 1
fi
VER_MAJOR=$(echo "$CURRENT_VER" | cut -d. -f1)
VER_MINOR=$(echo "$CURRENT_VER" | cut -d. -f2)
VER_PATCH=$(echo "$CURRENT_VER" | cut -d. -f3)
NEW_VER="$VER_MAJOR.$VER_MINOR.$((VER_PATCH + 1))"
# Escape dots so the sed pattern is a literal version string, not a regex with
# wildcards. Without this, "s/2.5.65/.../g" matches "255,255,255,0.15" inside
# inline CSS rgba() values and mangles them (see commit 623474a, v2.5.28 and
# v2.5.65 — both fixed the same recurring CSS corruption).
ESC_VER=$(printf '%s\n' "$CURRENT_VER" | sed 's/\./\\./g')
# Word-boundary anchors prevent matching version-like substrings inside
# longer numeric runs (e.g. the "2.5.65" inside a hypothetical "12.5.654").
echo "Version bump: $CURRENT_VER → $NEW_VER"
# Targeted bump ONLY. The old blanket replace-everywhere sed rewrote EVERY
# occurrence of the previous version — historical @since/@deprecated docblock
# tags and past readme.txt changelog headings included — so release history
# was silently rewritten on every build.
sed -i '' "s/^\( \* Version:[[:space:]]*\)${ESC_VER}\$/\1${NEW_VER}/" "$MAIN_PHP"
sed -i '' "s/\(define([[:space:]]*'CLOUDSCALE_CLEANUP_VERSION',[[:space:]]*'\)${ESC_VER}'/\1${NEW_VER}'/" "$REPO_DIR/cloudscale-cleanup.php"
sed -i '' "s/^\(Stable tag:[[:space:]]*\)${ESC_VER}\$/\1${NEW_VER}/" "$REPO_DIR/readme.txt"
# Promote ONLY the topmost changelog heading written for the pre-bump version;
# headings for past releases are never dragged forward.
sed -i '' "1,/^= ${ESC_VER} =\$/ s/^= ${ESC_VER} =\$/= ${NEW_VER} =/" "$REPO_DIR/readme.txt"
# JS @version headers.
while IFS= read -r vfile; do
  sed -i '' "s/\(@version[[:space:]]*\)${ESC_VER}\$/\1${NEW_VER}/" "$vfile"
done < <(grep -rl "@version[[:space:]]*$CURRENT_VER" "$REPO_DIR" --include="*.js" 2>/dev/null | grep -v "\.git" | grep -v "/repo/")
# Sync readme.txt and main PHP into repo/ so SVN trunk always has correct version.
cp "$REPO_DIR/readme.txt" "$REPO_DIR/repo/readme.txt"
sed -i '' "s/^ \* Version:.*/ * Version:     $NEW_VER/" "$REPO_DIR/repo/cloudscale-cleanup.php"
# ─────────────────────────────────────────────────────────────────────────────

# PHP syntax check — abort before packaging if any file has a parse error
echo "Checking PHP syntax..."
LINT_ERRORS=0
while IFS= read -r -d '' phpfile; do
  result=$(php -l "$phpfile" 2>&1)
  if [ $? -ne 0 ]; then
    echo "$result"
    LINT_ERRORS=1
  fi
done < <(find "$REPO_DIR" -name "*.php" -print0)
if [ "$LINT_ERRORS" -ne 0 ]; then
  echo ""
  echo "ERROR: PHP syntax errors found above. Fix before deploying."
  exit 1
fi
echo "PHP syntax: OK"
echo ""
# PHP runtime include test — catches TypeError/fatal that php -l misses.
echo "Checking PHP runtime includes..."
RUNTIME_ERRORS=0
while IFS= read -r -d '' phpfile; do
  basename=$(basename "$phpfile")
  [[ "$basename" == "uninstall.php" ]] && continue
  result=$(php -r "
define('ABSPATH', '/tmp/');
\$code = file_get_contents('$phpfile');
if (strpos(\$code, 'class ') !== false || strpos(\$code, 'function ') !== false) {
    if (strpos(\$code, 'require') === false && strpos(\$code, 'wp_') === false) {
        @include '$phpfile';
    }
}
" 2>&1 | grep -i "TypeError\|ParseError\|Fatal" || true)
  if [ -n "$result" ]; then echo "  RUNTIME ERROR in $phpfile: $result"; RUNTIME_ERRORS=1; fi
done < <(find "$(dirname "$0")/includes" -name "*.php" -print0 2>/dev/null)
find "$(dirname "$0")" -maxdepth 1 -name "*.php" -print0 2>/dev/null | while IFS= read -r -d '' phpfile; do
  basename=$(basename "$phpfile"); [[ "$basename" == "uninstall.php" ]] && continue
  result=$(php -r "@include '$phpfile';" 2>&1 | grep -i "TypeError\|ParseError\|Fatal" || true)
  if [ -n "$result" ]; then echo "  RUNTIME ERROR in $phpfile: $result"; RUNTIME_ERRORS=1; fi
done
if [ "$RUNTIME_ERRORS" -ne 0 ]; then
  echo "ERROR: PHP runtime errors found — crashes on first HTTP request."; exit 1
fi
echo "PHP runtime: OK"
echo ""

# ── Cross-file PHP method existence check ──────────────────────────────────
# Catches ClassName::method() calls where the method is not defined in the
# plugin — passes php -l but causes fatal errors at runtime (e.g. after an
# OPcache serves a stale class that is missing a newly added method).
echo "Checking cross-file method calls..."
XFILE_ERRORS=0
XFILE_PHP=()
while IFS= read -r -d '' f; do
    XFILE_PHP+=("$f")
done < <(find "$REPO_DIR" -name "*.php" \
    ! -path "*/repo/*" ! -path "*/vendor/*" ! -path "*/tests/*" \
    ! -path "*/node_modules/*" -print0 2>/dev/null)
if [[ ${#XFILE_PHP[@]} -gt 0 ]]; then
    PLUGIN_CLASSES=$(grep -hE "^(abstract |final )?class [A-Z_]" \
        "${XFILE_PHP[@]}" 2>/dev/null | \
        sed -E 's/^(abstract |final )?class ([A-Z_][a-zA-Z_0-9]+).*/\2/' | sort -u)
    while IFS= read -r class; do
        [[ -z "$class" ]] && continue
        while IFS= read -r method; do
            [[ -z "$method" ]] && continue
            if ! grep -qh "function ${method}(" "${XFILE_PHP[@]}" 2>/dev/null; then
                echo "  UNDEFINED: ${class}::${method}() — not found in plugin files"
                XFILE_ERRORS=1
            fi
        done < <(grep -h "${class}::" "${XFILE_PHP[@]}" 2>/dev/null \
            | grep -v '^\s*//' | grep -v '^\s*\*' \
            | grep -oh "${class}::[a-zA-Z_][a-zA-Z_0-9]*(" \
            | cut -d: -f3 | tr -d '(' | sort -u)
    done <<< "$PLUGIN_CLASSES"
fi
if [[ "$XFILE_ERRORS" -ne 0 ]]; then
    echo ""
    echo "ERROR: Undefined method calls found — fix before deploying."
    exit 1
fi
echo "Cross-file methods: OK"
echo ""

# ── WP bootstrap safety check ────────────────────────────────────────────────
# Catches calls to functions that require a bootstrapped WordPress environment
# (user auth, DB, etc.) made at global scope in the main plugin PHP file.
# These pass php -l but silently misbehave or fatal-crash on real page loads —
# e.g. current_user_can() called before wp_set_current_user() always returns false,
# and in some WP versions can trigger a PHP fatal that causes a 503.
echo "Checking WP bootstrap safety..."
BOOTSTRAP_ERRORS=0
_BOOTSTRAP_FORBIDDEN='current_user_can,is_user_logged_in,wp_get_current_user,get_current_user_id,check_admin_referer,check_ajax_referer,is_multisite,switch_to_blog,restore_current_blog'
while IFS= read -r -d '' phpfile; do
    _result=$(php -r "
\$file = '$phpfile';
\$tokens = token_get_all(file_get_contents(\$file));
// Scan only the leading global-scope preamble — tokens before the first
// top-level class/function/interface/trait declaration. Everything after that
// is inside a declaration body and is safe to call WP bootstrap functions.
\$forbidden = explode(',', '$_BOOTSTRAP_FORBIDDEN');
\$errors    = [];
foreach (\$tokens as \$tok) {
    if (!is_array(\$tok)) continue;
    \$type = \$tok[0];
    // Stop scanning at the first top-level declaration.
    if (in_array(\$type, [T_CLASS, T_FUNCTION, T_INTERFACE, T_TRAIT])) break;
    if (\$type === T_STRING && in_array(\$tok[1], \$forbidden)) {
        \$errors[] = 'BOOTSTRAP: ' . basename(\$file) . ':' . \$tok[2]
            . ': ' . \$tok[1] . '() in plugin preamble — requires bootstrapped WP, '
            . 'use a hook (add_action) or check WP_ADMIN/REST_REQUEST/\$_COOKIE instead';
    }
}
foreach (\$errors as \$e) echo \$e . PHP_EOL;
exit(empty(\$errors) ? 0 : 1);
" 2>&1 || true)
    if [ -n "$_result" ]; then
        echo "$_result"
        BOOTSTRAP_ERRORS=1
    fi
done < <(find "$REPO_DIR" -maxdepth 1 -name "*.php" -print0 2>/dev/null)
if [ "$BOOTSTRAP_ERRORS" -ne 0 ]; then
    echo ""
    echo "ERROR: WP bootstrap safety violations — fix before building."
    exit 1
fi
echo "WP bootstrap safety: OK"
echo ""

# ── PHPCS WordPress standards check ──────────────────────────────────────────
_PHPCS=""
for _candidate in \
    "$REPO_DIR/vendor/bin/phpcs" \
    "$HOME/.config/composer/vendor/bin/phpcs" \
    "$HOME/.composer/vendor/bin/phpcs" \
    "$(command -v phpcs 2>/dev/null || true)"; do
    [ -x "$_candidate" ] && { _PHPCS="$_candidate"; break; }
done

if [ -z "$_PHPCS" ]; then
    echo "phpcs not found — attempting auto-install..."
    if ! command -v composer &>/dev/null && command -v brew &>/dev/null; then
        brew install --quiet composer && hash -r
    fi
    if command -v composer &>/dev/null; then
        composer global require --quiet \
            squizlabs/php_codesniffer \
            wp-coding-standards/wpcs \
            dealerdirect/phpcodesniffer-composer-installer 2>&1 | tail -3
        _PHPCS="$(composer global config home 2>/dev/null)/vendor/bin/phpcs"
    fi
fi

if [ -z "$_PHPCS" ] || [ ! -x "$_PHPCS" ]; then
    echo "ERROR: phpcs not found and could not be installed automatically."
    echo "  Install: composer global require squizlabs/php_codesniffer wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer"
    exit 1
fi

echo "Running PHPCS (WordPress standard)..."
PHPCS_OUT=$("$_PHPCS" \
    -d memory_limit=512M \
    --standard="$REPO_DIR/phpcs.xml" \
    --severity=5 \
    --extensions=php \
    -s \
    "$REPO_DIR" 2>&1 || true)
echo "$PHPCS_OUT"
echo ""

# Count violations. grep|wc pipeline always exits 0 — safe under set -e.
_PHPCS_ERRS=$(echo "$PHPCS_OUT" | grep -F "| ERROR " | wc -l | tr -d '[:space:]')
_PHPCS_WARNS=$(echo "$PHPCS_OUT" | grep -F "| WARNING " | wc -l | tr -d '[:space:]')

# Block on any ERROR — WordPress.org reviewers reject plugins with any PHPCS error.
# Rules already suppressed in phpcs.xml will not appear here.
if [ "${_PHPCS_ERRS:-0}" -gt 0 ]; then
    echo "ERROR: PHPCS found ${_PHPCS_ERRS} error(s) — fix before building (WP.org rejects all errors)."
    exit 1
fi

# Block on development/discouraged-function warnings — WP.org explicitly rejects
# var_dump(), error_log(), eval(), base64_decode() etc. even when flagged as warnings.
if echo "$PHPCS_OUT" | grep -qE "WordPress\.PHP\.(DevelopmentFunctions|DiscouragedPHPFunctions)"; then
    echo "ERROR: Development or discouraged PHP functions flagged — remove before WordPress.org submission."
    exit 1
fi

# Non-blocking warning summary — must be zero before WordPress.org submission.
if [ "${_PHPCS_WARNS:-0}" -gt 0 ]; then
    echo "PHPCS: OK — 0 errors, ${_PHPCS_WARNS} warning(s) (must be clean before WP.org submission)"
    echo "  Warning breakdown:"
    echo "$PHPCS_OUT" | grep -oE '\([A-Za-z]+\.[A-Za-z.]+\)' | tr -d '()' | sort | uniq -c | sort -rn | head -8 | sed 's/^/    /'
else
    echo "PHPCS: OK — 0 errors, 0 warnings"
fi
echo ""

# Create temp directory with plugin name as wrapper
mkdir -p "$TEMP_DIR/$PLUGIN_NAME"
rsync -a \
  --exclude='.git' --exclude='.gitignore' --exclude='*.zip' \
  --exclude='.DS_Store' --exclude='._*' \
  --exclude='.claude-flow' --exclude='.claude' \
  --exclude='build.sh' --exclude='deploy-wordpress.sh' \
  --exclude='backup-s3.sh' --exclude='purge-cloudflare.sh' \
  --exclude='rollback-wordpress.sh' \
  --exclude='node_modules' --exclude='package.json' --exclude='package-lock.json' \
  --exclude='playwright.config.js' --exclude='tests' --exclude='test-results' --exclude='playwright-report' \
  --exclude='phpcs.xml' \
  --exclude='terraclaim' \
  --exclude='docs' \
  --exclude='generate-help-docs.sh' \
  --exclude='build-review.sh' \
  --exclude='setup-playwright-test-account.sh' \
  --exclude='delete-playwright-test-account.sh' \
  --exclude='archive' \
  --exclude='CloudScaleCleanup.jpg' \
  --exclude='repo' \
  "$REPO_DIR/" "$TEMP_DIR/$PLUGIN_NAME/"

# ── Deterministic WordPress.org file-write standards guard ───────────────────
# Scans the STAGED plugin (exactly what ships) for disallowed file writes:
# executable code (.php/.sh) deployed at runtime, writes to the plugin dir,
# OS/system paths, or the /wp-content root. See standards-grep-guard.sh.
STD_GUARD="$GITHUB_DIR/standards-grep-guard.sh"
[ -f "$STD_GUARD" ] || STD_GUARD="$(dirname "$GITHUB_DIR")/standards-grep-guard.sh"
if [ -f "$STD_GUARD" ]; then
  bash "$STD_GUARD" "$TEMP_DIR/$PLUGIN_NAME" || { rm -rf "$TEMP_DIR"; exit 1; }
else
  echo "WARNING: standards-grep-guard.sh not found — file-write guard skipped."
fi

# Build zip with correct structure
rm -f "$ZIP_FILE"
cd "$TEMP_DIR"
zip -r "$ZIP_FILE" "$PLUGIN_NAME/"

# Cleanup
rm -rf "$TEMP_DIR"

echo ""
echo "Zip built: $ZIP_FILE"
echo ""
echo "Contents:"
unzip -l "$ZIP_FILE" | head -25
echo ""

# Show version
VERSION=$(grep "^ \* Version:" "$REPO_DIR/cloudscale-cleanup.php" | head -1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
echo "Plugin version: $VERSION"
echo ""
echo "To deploy to S3, run:"
  echo "  bash $SCRIPT_DIR/backup-s3.sh"
echo ""
echo "Then on the server:"
echo "  sudo aws s3 cp s3://andrewninjawordpress/cloudscale-cleanup.zip /tmp/plugin.zip && sudo rm -rf /var/www/html/wp-content/plugins/cloudscale-cleanup && sudo unzip -q /tmp/plugin.zip -d /var/www/html/wp-content/plugins/ && sudo chown -R apache:apache /var/www/html/wp-content/plugins/cloudscale-cleanup && php -r \"if(function_exists('opcache_reset'))opcache_reset();\""
