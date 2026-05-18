#!/usr/bin/env bash
#
# Build a WordPress-ready distribution zip containing only runtime files.
# Excludes tests, dev tooling, git metadata, and Composer dev dependencies.
#
# Usage (from repo root):
#   bash scripts/build-release-zip.sh
#   bash scripts/build-release-zip.sh /path/to/output-dir
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$ROOT/build}"
PLUGIN_SLUG="presssentinel"
MAIN_FILE="$ROOT/press-sentinel.php"

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

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/presssentinel-release.XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT

DEST="$STAGE/$PLUGIN_SLUG"
mkdir -p "$DEST"

# Whitelist: only paths required at runtime (plus operator docs).
copy_if_exists() {
  local rel="$1"
  if [[ -e "$ROOT/$rel" ]]; then
    cp -a "$ROOT/$rel" "$DEST/"
  else
    echo "warning: missing optional path $rel" >&2
  fi
}

copy_if_exists "press-sentinel.php"
copy_if_exists "bootstrap"
copy_if_exists "config"
copy_if_exists "src"
copy_if_exists "resources"
copy_if_exists "mu-loader"
copy_if_exists ".env.example"
copy_if_exists "README.md"

# Storage: ship directory skeleton + lockdown files only (never local *.log).
mkdir -p "$DEST/storage/logs"
if [[ -f "$ROOT/storage/logs/.htaccess" ]]; then
  cp -a "$ROOT/storage/logs/.htaccess" "$DEST/storage/logs/"
fi
if [[ -f "$ROOT/storage/logs/index.html" ]]; then
  cp -a "$ROOT/storage/logs/index.html" "$DEST/storage/logs/"
fi
for subdir in cache tmp; do
  if [[ -d "$ROOT/storage/$subdir" ]]; then
    mkdir -p "$DEST/storage/$subdir"
    # Preserve .gitkeep or similar placeholders if present.
    shopt -s nullglob
    for f in "$ROOT/storage/$subdir"/.* "$ROOT/storage/$subdir"/*; do
      [[ -e "$f" ]] || continue
      base="$(basename "$f")"
      [[ "$base" == "." || "$base" == ".." ]] && continue
      cp -a "$f" "$DEST/storage/$subdir/"
    done
    shopt -u nullglob
  fi
done

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
