#!/usr/bin/env bash
#
# Cut a release for either the plugin or the theme.
#
# Bumps the version in the component's header file(s), commits the bump, and
# creates the matching git tag — the tag prefix that the
# build-release-zips.yml workflow listens for. It stops short of pushing so
# you get a chance to review; it prints the exact push command to run next.
#
# Usage:
#   npm run release:plugin -- 1.1.0
#   npm run release:theme  -- 2.0.1
#
#   ./bin/release.sh plugin 1.1.0
#   ./bin/release.sh theme  2.0.1

set -euo pipefail

COMPONENT="${1:-}"
VERSION="${2:-}"

usage() {
    echo "Usage: $0 <plugin|theme> <version>" >&2
    echo "  e.g. $0 plugin 1.1.0" >&2
    exit 1
}

[ -n "$COMPONENT" ] && [ -n "$VERSION" ] || usage

# Accept a leading 'v' for convenience, but store the bare version.
VERSION="${VERSION#v}"

# Versions are plain semver-ish (digits and dots, optional pre-release).
# Keep it loose but reject obvious mistakes like a stray tag prefix.
if ! [[ "$VERSION" =~ ^[0-9]+(\.[0-9]+)*([.-][A-Za-z0-9.]+)?$ ]]; then
    echo "✘ '$VERSION' doesn't look like a version number." >&2
    exit 1
fi

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$ROOT"

case "$COMPONENT" in
	plugin)
		TAG="plugin-v$VERSION"
		PLUGIN_FILE="plugins/heckl-tools/heckl-tools.php"
		README_FILE="plugins/heckl-tools/readme.txt"
		FILES=("$PLUGIN_FILE" "$README_FILE")
		;;
	theme)
		TAG="theme-v$VERSION"
		STYLE_FILE="themes/heckl/style.css"
		README_FILE="themes/heckl/readme.txt"
		FILES=("$STYLE_FILE" "$README_FILE")
		;;
    *)
        usage
        ;;
esac

# Guard rails: a release should come off a clean tree so the tag points at a
# reviewed commit, and the tag must not already exist.
if [ -n "$(git status --porcelain)" ]; then
    echo "✘ Working tree is not clean. Commit or stash changes before releasing." >&2
    exit 1
fi
if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "✘ Tag $TAG already exists." >&2
    exit 1
fi

echo "→ Bumping $COMPONENT version to $VERSION …"

# perl -i is portable across macOS (BSD) and Linux (GNU), unlike sed -i.
# Each pattern matches the existing header line and rewrites just the value,
# preserving the surrounding whitespace/formatting.
if [ "$COMPONENT" = "plugin" ]; then
	perl -i -pe "s/^(\s*\*\s*Version:\s*).*/\${1}$VERSION/" "$PLUGIN_FILE"
	perl -i -pe "s/^(Stable tag:\s*).*/\${1}$VERSION/"      "$README_FILE"
else
	perl -i -pe "s/^(Version:\s*).*/\${1}$VERSION/" "$STYLE_FILE"
	perl -i -pe "s/^(Stable tag:\s*).*/\${1}$VERSION/" "$README_FILE"
fi

# Confirm the bump actually changed something — guards against a header whose
# format drifted out from under the regex.
if [ -z "$(git status --porcelain)" ]; then
    echo "✘ No files changed. Is the version already $VERSION, or did the header format change?" >&2
    exit 1
fi

git add "${FILES[@]}"
git commit -m "Bump $COMPONENT version to $VERSION"
git tag "$TAG"

echo
echo "✓ Committed version bump and created tag $TAG"
echo
echo "Review the commit, then push to trigger the release workflow:"
echo
echo "    git push && git push origin $TAG"
echo
echo "To undo before pushing:"
echo "    git tag -d $TAG && git reset --hard HEAD~1"
