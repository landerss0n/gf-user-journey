#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

PLUGIN_SLUG="gf-user-journey"
VERSION=$(grep "Version:" "$PLUGIN_SLUG.php" | head -1 | sed 's/.*Version:[[:space:]]*//' | sed 's/[[:space:]]*$//')
DIST_DIR="dist"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo "Building ${ZIP_NAME}..."

rm -f "${PLUGIN_SLUG}"-*.zip
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR/$PLUGIN_SLUG/assets/js"
mkdir -p "$DIST_DIR/$PLUGIN_SLUG/assets/css"

# Copy only production files (no dev configs, no vendor, no node_modules)
cp "$PLUGIN_SLUG.php" "$DIST_DIR/$PLUGIN_SLUG/"
cp README.md "$DIST_DIR/$PLUGIN_SLUG/"
cp assets/js/user-journey.js "$DIST_DIR/$PLUGIN_SLUG/assets/js/"
cp assets/js/user-journey.min.js "$DIST_DIR/$PLUGIN_SLUG/assets/js/"
cp assets/css/entry-detail.css "$DIST_DIR/$PLUGIN_SLUG/assets/css/"
cp -r languages/ "$DIST_DIR/$PLUGIN_SLUG/languages/"
cp -r includes/ "$DIST_DIR/$PLUGIN_SLUG/includes/"

# Create zip
cd "$DIST_DIR"
zip -rq "../$ZIP_NAME" "$PLUGIN_SLUG"
cd ..

# Cleanup
rm -rf "$DIST_DIR"

echo "Done: ${ZIP_NAME} ($(du -h "$ZIP_NAME" | cut -f1))"
