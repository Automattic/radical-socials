#!/usr/bin/env bash
#
# Build a release-ready zip of the Radical Socials plugin, formatted for
# submission to the WordPress.org plugin directory.
#
# - Runs a fresh production build (wp-scripts build).
# - Stages the plugin under dist/staging/radical-socials/ and excludes:
#     * source maps (*.map) — dev artifact, doubles the zip size
#     * modules/dev/        — WP-CLI test harness, not for end users
#     * OS / IDE droppings (.DS_Store, Thumbs.db)
#     * editor backups / temp files (*~, *.bak, *.orig, *.swp)
# - Keeps src/ alongside build/ so we satisfy wp.org guideline #4
#   ("include the source code for any minified/bundled assets").
# - Outputs dist/radical-socials-<version>.zip with the version pulled
#   straight from the plugin header so the filename never drifts from
#   the Stable tag in readme.txt.
#
# Usage:  npm run plugin-zip
#         ./bin/build-plugin-zip.sh

set -euo pipefail

# Resolve repo root regardless of where the script is called from.
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
PLUGIN_DIR="$ROOT/plugins/radical-socials"
DIST_DIR="$ROOT/dist"
STAGE_DIR="$DIST_DIR/staging"

if [ ! -f "$PLUGIN_DIR/radical-socials.php" ]; then
    echo "✘ Plugin file not found at $PLUGIN_DIR/radical-socials.php" >&2
    exit 1
fi

# Pull the version from the plugin header — single source of truth so the
# zip filename always matches the Stable tag in readme.txt.
VERSION="$(
    grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$PLUGIN_DIR/radical-socials.php" \
        | head -n1 \
        | sed -E 's/.*Version:[[:space:]]*([^[:space:]]+).*/\1/'
)"
if [ -z "$VERSION" ]; then
    echo "✘ Could not parse Version from radical-socials.php header" >&2
    exit 1
fi

ZIP_PATH="$DIST_DIR/radical-socials-$VERSION.zip"

echo "→ Building production assets…"
( cd "$ROOT" && npm run build --silent )

echo "→ Staging plugin files at $STAGE_DIR/radical-socials …"
rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR/radical-socials"

# rsync gives us a single tool for copy + exclude. Trailing slash on source
# means "copy contents", target has no slash so dirs are created under it.
rsync -a \
    --exclude='*.map' \
    --exclude='/modules/dev/' \
    --exclude='.DS_Store' \
    --exclude='Thumbs.db' \
    --exclude='*~' \
    --exclude='*.bak' \
    --exclude='*.orig' \
    --exclude='*.swp' \
    --exclude='*.rej' \
    "$PLUGIN_DIR/" "$STAGE_DIR/radical-socials/"

# Sanity: refuse to ship if any of the things we don't want made it through.
LEAKED="$(
    find "$STAGE_DIR/radical-socials" \
        \( -name '*.map' -o -name '.DS_Store' -o -path '*/modules/dev/*' \) -print
)"
if [ -n "$LEAKED" ]; then
    echo "✘ Unexpected files in staging:" >&2
    echo "$LEAKED" >&2
    exit 1
fi

# Build the zip from the parent of the staged directory so the top-level
# folder inside the archive is radical-socials/ (what wp.org expects).
rm -f "$ZIP_PATH"
echo "→ Zipping to $ZIP_PATH …"
( cd "$STAGE_DIR" && zip -rq "$ZIP_PATH" radical-socials )

# Cleanup staging — leave only the zip under dist/.
rm -rf "$STAGE_DIR"

SIZE="$( du -h "$ZIP_PATH" | cut -f1 )"
COUNT="$( unzip -Z1 "$ZIP_PATH" | wc -l | tr -d ' ' )"
echo
echo "✓ Built $ZIP_PATH ($SIZE, $COUNT entries)"
echo "  Plugin version: $VERSION"
