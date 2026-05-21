#!/usr/bin/env bash
#
# Package Press Sentinel for WordPress.
# Excludes tests, dev tooling, git metadata, and Composer dev dependencies.
#
# Usage (from repo root):
#   bash scripts/build-release-zip.sh dev
#     Copy runtime files to ../plugins/presssentinel (local WordPress plugins dir).
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
PLUGIN_SLUG="presssentinel"
MAIN_FILE="$ROOT/press-sentinel.php"

MODE="prod"
OUT_DIR="$ROOT/build"

if [[ $# -ge 1 ]]; then
  case "$1" in
    dev|prod)
      MODE="$1"
      if [[ "$MODE" == "prod" && $# -ge 2 ]]; then
        OUT_DIR="$2"
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

stage_plugin_to() {
  local dest="$1"
  mkdir -p "$dest"

  copy_if_exists "$dest" "press-sentinel.php"
  copy_if_exists "$dest" "bootstrap"
  copy_if_exists "$dest" "config"
  copy_if_exists "$dest" "src"
  copy_if_exists "$dest" "resources"
  copy_if_exists "$dest" "mu-loader"
  copy_if_exists "$dest" "README.md"

  # Storage: ship directory skeleton + lockdown files only (never local *.log).
  mkdir -p "$dest/storage/logs"
  if [[ -f "$ROOT/storage/logs/index.html" ]]; then
    cp -a "$ROOT/storage/logs/index.html" "$dest/storage/logs/"
  fi
  for subdir in cache tmp; do
    if [[ -d "$ROOT/storage/$subdir" ]]; then
      mkdir -p "$dest/storage/$subdir"
      shopt -s nullglob
      for f in "$ROOT/storage/$subdir"/index.html "$ROOT/storage/$subdir"/.gitkeep; do
        [[ -e "$f" ]] || continue
        cp -a "$f" "$dest/storage/$subdir/"
      done
      shopt -u nullglob
    fi
  done

  # Drop legacy paths removed from source (cp -a does not delete stale deploy files).
  rm -f "$dest/bootstrap/env.php" "$dest/env.example" "$dest/.env" "$dest/.env.local"
  rm -f "$dest/storage/tmp/"*.php "$dest/storage/cache/"*.php 2>/dev/null || true
  rm -f "$dest/storage/tmp/.htaccess" "$dest/storage/cache/.htaccess" 2>/dev/null || true
}

if [[ "$MODE" == "dev" ]]; then
  DEV_DEST="$(cd "$ROOT/../../plugins" && pwd)/$PLUGIN_SLUG"
  mkdir -p "$(dirname "$DEV_DEST")"
  stage_plugin_to "$DEV_DEST"
  echo "Deployed to $DEV_DEST (version $VERSION)"
  exit 0
fi

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/presssentinel-release.XXXXXX")"
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
