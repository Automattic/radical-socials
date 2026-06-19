#!/usr/bin/env bash
#
# Build a release-ready zip of the Heckl, formatted for submission
# to the WordPress.org theme directory.
#
# - Stages the theme under dist/staging/heckl/ and excludes:
#     * OS / IDE droppings (.DS_Store, Thumbs.db)
#     * editor backups / temp files (*~, *.bak, *.orig, *.swp, *.rej)
#     * source control (.git, .gitignore)  — defensive; shouldn't be in
#                                            themes/heckl anyway.
#     * dev dependencies (node_modules)    — same.
# - Output: dist/heckl-<version>.zip with the version pulled from
#   the style.css header so the filename always matches what the theme
#   directory will display.
#
# Usage:  npm run theme-zip
#         ./bin/build-theme-zip.sh

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
THEME_DIR="$ROOT/themes/heckl"
DIST_DIR="$ROOT/dist"
STAGE_DIR="$DIST_DIR/staging"

if [ ! -f "$THEME_DIR/style.css" ]; then
    echo "✘ Theme stylesheet not found at $THEME_DIR/style.css" >&2
    exit 1
fi
if [ ! -f "$THEME_DIR/theme.json" ]; then
    echo "✘ theme.json missing — block themes must ship one" >&2
    exit 1
fi

# Pull the version out of the style.css header.
VERSION="$(
    grep -E '^[[:space:]]*Version:' "$THEME_DIR/style.css" \
        | head -n1 \
        | sed -E 's/.*Version:[[:space:]]*([^[:space:]]+).*/\1/'
)"
if [ -z "$VERSION" ]; then
    echo "✘ Could not parse Version from style.css header" >&2
    exit 1
fi

ZIP_PATH="$DIST_DIR/heckl-$VERSION.zip"

echo "→ Staging theme files at $STAGE_DIR/heckl …"
rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR/heckl"

rsync -a \
    --exclude='.DS_Store' \
    --exclude='Thumbs.db' \
    --exclude='*~' \
    --exclude='*.bak' \
    --exclude='*.orig' \
    --exclude='*.swp' \
    --exclude='*.rej' \
    --exclude='/.git' \
    --exclude='/.gitignore' \
    --exclude='/node_modules' \
    "$THEME_DIR/" "$STAGE_DIR/heckl/"

# Sanity check.
LEAKED="$(
    find "$STAGE_DIR/heckl" \
        \( -name '.DS_Store' -o -name 'node_modules' -o -name '.git' \) -print
)"
if [ -n "$LEAKED" ]; then
    echo "✘ Unexpected files in staging:" >&2
    echo "$LEAKED" >&2
    exit 1
fi

rm -f "$ZIP_PATH"
echo "→ Zipping to $ZIP_PATH …"
( cd "$STAGE_DIR" && zip -rq "$ZIP_PATH" heckl )

rm -rf "$STAGE_DIR"

SIZE="$( du -h "$ZIP_PATH" | cut -f1 )"
COUNT="$( unzip -Z1 "$ZIP_PATH" | wc -l | tr -d ' ' )"
echo
echo "✓ Built $ZIP_PATH ($SIZE, $COUNT entries)"
echo "  Theme version: $VERSION"

# Friendly nudge: themes on wp.org need a screenshot.png. Warn rather than
# fail so the script stays useful for local dev distribution.
if [ ! -f "$THEME_DIR/screenshot.png" ] && [ ! -f "$THEME_DIR/screenshot.jpg" ]; then
    echo
    echo "⚠ No screenshot.png found in themes/heckl/."
    echo "  Add one (1200×900 recommended) before submitting to wp.org/themes."
fi
