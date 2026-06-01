#!/usr/bin/env bash
#
# Package NiyiGuard for WordPress.
# Excludes tests, dev tooling, git metadata, and Composer dev dependencies.
# Ships readme.txt and license.txt (required for WordPress.org). Privacy policy: docs/PRIVACY.md.
#
# Usage (from repo root):
#   bash scripts/build-release-zip.sh dev
#     Copy runtime files to ../plugins/niyiguard (local WordPress plugins dir).
#   bash scripts/build-release-zip.sh svn
#     Copy runtime files to ../svn/niyiguard/trunk and ../svn/niyiguard/tags/<Version>
#     (<Version> is read from the Version header in niyiguard.php).
#   bash scripts/build-release-zip.sh svn /path/to/svn-checkout
#     Same as svn, but use a custom SVN working copy root (expects trunk/ and tags/ inside).
#   bash scripts/build-release-zip.sh prod
#     Build a distribution zip in ./build (default).
#   bash scripts/build-release-zip.sh prod /path/to/output-dir
#     Build zip in a custom output directory.
#
# Legacy (prod with custom output dir, mode omitted):
#   bash scripts/build-release-zip.sh /path/to/output-dir
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="niyiguard"
MAIN_FILE="$ROOT/niyiguard.php"

MODE="prod"
OUT_DIR="$ROOT/build"
SVN_ROOT=""

if [[ $# -ge 1 ]]; then
  case "$1" in
    dev|prod|svn)
      MODE="$1"
      if [[ "$MODE" == "prod" && $# -ge 2 ]]; then
        OUT_DIR="$2"
      elif [[ "$MODE" == "svn" && $# -ge 2 ]]; then
        SVN_ROOT="$2"
      fi
      ;;
    *)
      MODE="prod"
      OUT_DIR="$1"
      ;;
  esac
fi

if [[ ! -f "$MAIN_FILE" ]]; then
  echo "error: expected $MAIN_FILE" >&2
  exit 1
fi

# Read "Version: x.y.z" from the plugin file header (first match).
VERSION="$(
  grep -E '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*' "$MAIN_FILE" \
    | head -n1 \
    | sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//;s/[[:space:]]+$//'
)"
if [[ -z "$VERSION" ]]; then
  echo "error: could not parse Version from $MAIN_FILE" >&2
  exit 1
fi

# Whitelist: only paths required at runtime (plus operator docs).
copy_if_exists() {
  local dest="$1"
  local rel="$2"
  if [[ -e "$ROOT/$rel" ]]; then
    cp -a "$ROOT/$rel" "$dest/"
  else
    echo "warning: missing optional path $rel" >&2
  fi
}

# WordPress.org and directory reviewers expect these at the plugin root.
copy_required() {
  local dest="$1"
  local rel="$2"
  if [[ ! -e "$ROOT/$rel" ]]; then
    echo "error: required release file missing: $rel" >&2
    exit 1
  fi
  cp -a "$ROOT/$rel" "$dest/"
}

stage_plugin_to() {
  local dest="$1"
  mkdir -p "$dest"

  copy_if_exists "$dest" "niyiguard.php"
  copy_if_exists "$dest" "bootstrap"
  copy_if_exists "$dest" "config"
  copy_if_exists "$dest" "src"
  copy_if_exists "$dest" "resources"
  copy_if_exists "$dest" "mu-loader"
  for doc in docs/WHY_NIYIGUARD.md docs/USAGE.md docs/MU_LOADER_INSTALL.md docs/PRIVACY.md; do
    if [[ -f "$ROOT/$doc" ]]; then
      mkdir -p "$dest/docs"
      cp -a "$ROOT/$doc" "$dest/docs/"
    fi
  done

  # Required for WordPress.org (readme parser, GPL distribution). Only readme.txt at plugin root (Plugin Check).
  copy_required "$dest" "readme.txt"
  copy_required "$dest" "license.txt"

  # Writable runtime data (logs, cache, temp) lives under wp-content/uploads/niyiguard — not in the plugin package.

  # Drop legacy paths removed from source (cp -a does not delete stale deploy files).
  rm -f "$dest/bootstrap/env.php" "$dest/env.example" "$dest/.env" "$dest/.env.local"
  rm -rf "$dest/storage" 2>/dev/null || true
}

if [[ "$MODE" == "dev" ]]; then
  DEV_DEST="$(cd "$ROOT/../../plugins" && pwd)/$PLUGIN_SLUG"
  mkdir -p "$(dirname "$DEV_DEST")"
  stage_plugin_to "$DEV_DEST"
  echo "Deployed to $DEV_DEST (version $VERSION)"
  exit 0
fi

if [[ "$MODE" == "svn" ]]; then
  if [[ -z "$SVN_ROOT" ]]; then
    SVN_ROOT="$(cd "$ROOT/../svn/$PLUGIN_SLUG" && pwd)"
  else
    SVN_ROOT="$(cd "$SVN_ROOT" && pwd)"
  fi

  for required in trunk tags; do
    if [[ ! -d "$SVN_ROOT/$required" ]]; then
      echo "error: expected SVN directory $SVN_ROOT/$required (checkout https://plugins.svn.wordpress.org/$PLUGIN_SLUG first)" >&2
      exit 1
    fi
  done

  TRUNK_DEST="$SVN_ROOT/trunk"
  TAG_DEST="$SVN_ROOT/tags/$VERSION"

  mkdir -p "$TAG_DEST"
  stage_plugin_to "$TRUNK_DEST"
  stage_plugin_to "$TAG_DEST"

  echo "Deployed to $TRUNK_DEST (version $VERSION)"
  echo "Deployed to $TAG_DEST"
  echo "Next: cd $SVN_ROOT && svn status && svn commit -m \"Release $VERSION\""
  exit 0
fi

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/niyiguard-release.XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT

DEST="$STAGE/$PLUGIN_SLUG"
stage_plugin_to "$DEST"

mkdir -p "$OUT_DIR"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$OUT_DIR/$ZIP_NAME"

rm -f "$ZIP_PATH"
(
  cd "$STAGE"
  zip -r -q "$ZIP_PATH" "$PLUGIN_SLUG"
)

FILES_IN_ZIP="$(unzip -l "$ZIP_PATH" | awk '/ files$/ {print $(NF-1)}')"
echo "Built $ZIP_PATH ($(du -h "$ZIP_PATH" | cut -f1))"
echo "Files in archive: ${FILES_IN_ZIP}"
