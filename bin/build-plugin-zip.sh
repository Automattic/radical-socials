#!/usr/bin/env bash
#
# Build a release-ready zip of the Heckl plugin, formatted for
# submission to the WordPress.org plugin directory.
#
# - Runs a fresh production build (wp-scripts build).
# - Stages the plugin under dist/staging/heckl-tools/ and excludes:
#     * source maps (*.map) — dev artifact, doubles the zip size
#     * modules/dev/        — WP-CLI test harness, not for end users
#     * OS / IDE droppings (.DS_Store, Thumbs.db)
#     * editor backups / temp files (*~, *.bak, *.orig, *.swp)
# - Keeps src/ alongside build/ so we satisfy wp.org guideline #4
#   ("include the source code for any minified/bundled assets").
# - Outputs dist/heckl-tools-<version>.zip with the version pulled
#   straight from the plugin header so the filename never drifts from
#   the Stable tag in readme.txt.
#
# Usage:  npm run plugin-zip
#         ./bin/build-plugin-zip.sh

set -euo pipefail

# Resolve repo root regardless of where the script is called from.
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
PLUGIN_DIR="$ROOT/plugins/heckl-tools"
DIST_DIR="$ROOT/dist"
STAGE_DIR="$DIST_DIR/staging"

if [ ! -f "$PLUGIN_DIR/heckl-tools.php" ]; then
    echo "✘ Plugin file not found at $PLUGIN_DIR/heckl-tools.php" >&2
    exit 1
fi

# Pull the version from the plugin header — single source of truth so the
# zip filename always matches the Stable tag in readme.txt.
VERSION="$(
    grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$PLUGIN_DIR/heckl-tools.php" \
        | head -n1 \
        | sed -E 's/.*Version:[[:space:]]*([^[:space:]]+).*/\1/'
)"
if [ -z "$VERSION" ]; then
    echo "✘ Could not parse Version from heckl-tools.php header" >&2
    exit 1
fi

# Plugin header Version: must match readme.txt's Stable tag: — wp.org
# auto-rejects mismatches. Catching it here avoids a silent broken
# release.
STABLE_TAG="$(
    grep -E '^[[:space:]]*Stable tag:' "$PLUGIN_DIR/readme.txt" \
        | head -n1 \
        | sed -E 's/.*Stable tag:[[:space:]]*([^[:space:]]+).*/\1/'
)"
if [ "$STABLE_TAG" != "$VERSION" ]; then
    echo "✘ Stable tag (\"$STABLE_TAG\") in readme.txt does not match plugin Version (\"$VERSION\")" >&2
    exit 1
fi

ZIP_PATH="$DIST_DIR/heckl-tools-$VERSION.zip"

echo "→ Building production assets…"
rm -rf "$PLUGIN_DIR/build"
( cd "$ROOT" && npm run build --silent )

# Flatten theme templates into plugin-default block templates. The output
# lives under plugins/heckl-tools/templates/ and is gitignored — it
# must be regenerated for every zip so the shipped fallback templates
# match the current state of heckl.
echo "→ Flattening theme templates into plugin defaults…"
( cd "$ROOT" && npm run build:templates --silent )

echo "→ Staging plugin files at $STAGE_DIR/heckl-tools …"
rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR/heckl-tools"

# rsync gives us a single tool for copy + exclude. Trailing slash on source
# means "copy contents", target has no slash so dirs are created under it.
rsync -a \
    --exclude='*.map' \
    --exclude='/node_modules/' \
    --exclude='/package-lock.json' \
    --exclude='/package.json' \
    --exclude='/webpack.config.js' \
    --exclude='/modules/dev/' \
    --exclude='/.wordpress-org/' \
    --exclude='*.test.js' \
    --exclude='*.test.jsx' \
    --exclude='__tests__' \
    --exclude='.DS_Store' \
    --exclude='Thumbs.db' \
    --exclude='*~' \
    --exclude='*.bak' \
    --exclude='*.orig' \
    --exclude='*.swp' \
    --exclude='*.rej' \
    --exclude='.gitkeep' \
    "$PLUGIN_DIR/" "$STAGE_DIR/heckl-tools/"

# Sanity: refuse to ship if any of the things we don't want made it through.
LEAKED="$(
    find "$STAGE_DIR/heckl-tools" \
        \( -name '*.map' -o -name '.DS_Store' -o -name '*.test.js' -o -name '*.test.jsx' -o -name 'package-lock.json' -o -path '*/node_modules/*' -o -path '*/__tests__/*' -o -path '*/modules/dev/*' \) -print
)"
if [ -n "$LEAKED" ]; then
    echo "✘ Unexpected files in staging:" >&2
    echo "$LEAKED" >&2
    exit 1
fi

# Build the zip from the parent of the staged directory so the top-level
# folder inside the archive is heckl-tools/ (what wp.org expects).
rm -f "$ZIP_PATH"
echo "→ Zipping to $ZIP_PATH …"
( cd "$STAGE_DIR" && zip -rq "$ZIP_PATH" heckl-tools )

# Cleanup staging — leave only the zip under dist/.
rm -rf "$STAGE_DIR"

SIZE="$( du -h "$ZIP_PATH" | cut -f1 )"
COUNT="$( unzip -Z1 "$ZIP_PATH" | wc -l | tr -d ' ' )"
echo
echo "✓ Built $ZIP_PATH ($SIZE, $COUNT entries)"
echo "  Plugin version: $VERSION"
