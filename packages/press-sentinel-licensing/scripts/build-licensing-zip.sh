#!/usr/bin/env bash
#
# Package the optional PressSentinel licensing module for archival or reuse.
#
# Usage (from repo root):
#   bash packages/press-sentinel-licensing/scripts/build-licensing-zip.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="press-sentinel-licensing"
VERSION="1.0.0"
OUT_DIR="$ROOT/build"

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/press-sentinel-licensing.XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT

DEST="$STAGE/$SLUG"
mkdir -p "$DEST"

cp -a "$PKG_ROOT/src" "$DEST/"
cp -a "$PKG_ROOT/config" "$DEST/"
cp -a "$PKG_ROOT/tests" "$DEST/"
cp "$PKG_ROOT/INTEGRATION.md" "$DEST/"
cp "$PKG_ROOT/README.md" "$DEST/" 2>/dev/null || true

mkdir -p "$OUT_DIR"
ZIP_PATH="$OUT_DIR/${SLUG}-${VERSION}.zip"
rm -f "$ZIP_PATH"
(
  cd "$STAGE"
  zip -r -q "$ZIP_PATH" "$SLUG"
)

echo "Built $ZIP_PATH ($(du -h "$ZIP_PATH" | cut -f1))"
