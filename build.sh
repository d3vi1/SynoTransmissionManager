#!/bin/bash
set -euo pipefail

# Build TransmissionManager .spk package
#
# Usage: ./build.sh
#
# Produces build/TransmissionManager-<version>.spk

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# Read version from package/INFO
VERSION=$(grep '^version=' package/INFO | cut -d'"' -f2)
PACKAGE_NAME="TransmissionManager"
BUILD_DIR="build"

echo "Building ${PACKAGE_NAME} v${VERSION}..."

# ---------------------------------------------------------------
# Validate required files
# ---------------------------------------------------------------
REQUIRED_FILES=(
    "package/INFO"
    "package/scripts/preinst"
    "package/scripts/postinst"
    "package/scripts/preuninst"
    "package/scripts/postuninst"
    "package/scripts/start-stop-status"
    "package/conf/privilege"
    "schema.sql"
    "ui/modules/MainWindow.js"
    "webapi/src/entry.php"
)

MISSING=0
for f in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$f" ]; then
        echo "ERROR: Missing required file: $f" >&2
        MISSING=1
    fi
done
if [ "$MISSING" -eq 1 ]; then
    echo "Build aborted due to missing files." >&2
    exit 1
fi

# ---------------------------------------------------------------
# Clean previous build
# ---------------------------------------------------------------
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/staging"

# ---------------------------------------------------------------
# Copy package files (excluding dev-only files)
# ---------------------------------------------------------------
cp -r package "$BUILD_DIR/staging/"
cp -r ui "$BUILD_DIR/staging/"
cp -r etc "$BUILD_DIR/staging/"
cp schema.sql "$BUILD_DIR/staging/"

# Copy webapi, excluding tests and dev config
mkdir -p "$BUILD_DIR/staging/webapi"
cp -r webapi/src "$BUILD_DIR/staging/webapi/"
cp -r webapi/*.lib "$BUILD_DIR/staging/webapi/" 2>/dev/null || true

# Copy icons if they exist
for icon in PACKAGE_ICON.PNG PACKAGE_ICON_256.PNG; do
    if [ -f "$icon" ]; then
        cp "$icon" "$BUILD_DIR/staging/package/"
    fi
done

# ---------------------------------------------------------------
# Ensure scripts are executable
# ---------------------------------------------------------------
chmod +x "$BUILD_DIR/staging/package/scripts/"*

# ---------------------------------------------------------------
# Remove dev-only files from staging
# ---------------------------------------------------------------
find "$BUILD_DIR/staging" -name ".gitkeep" -delete 2>/dev/null || true
find "$BUILD_DIR/staging" -name "*.md" -delete 2>/dev/null || true
find "$BUILD_DIR/staging" -name ".DS_Store" -delete 2>/dev/null || true

# ---------------------------------------------------------------
# Create inner tarball (package.tgz)
# ---------------------------------------------------------------
(cd "$BUILD_DIR/staging" && tar czf ../package.tgz .)

# ---------------------------------------------------------------
# Create SPK (outer tarball with INFO + package.tgz)
# ---------------------------------------------------------------
cp "$BUILD_DIR/staging/package/INFO" "$BUILD_DIR/INFO"
# Include icons at SPK root level if present
for icon in PACKAGE_ICON.PNG PACKAGE_ICON_256.PNG; do
    if [ -f "$BUILD_DIR/staging/package/$icon" ]; then
        cp "$BUILD_DIR/staging/package/$icon" "$BUILD_DIR/"
    fi
done

(cd "$BUILD_DIR" && tar czf "${PACKAGE_NAME}-${VERSION}.spk" INFO package.tgz $(ls PACKAGE_ICON*.PNG 2>/dev/null || true))

# ---------------------------------------------------------------
# Clean up
# ---------------------------------------------------------------
rm -rf "$BUILD_DIR/staging" "$BUILD_DIR/package.tgz" "$BUILD_DIR/INFO"
rm -f "$BUILD_DIR"/PACKAGE_ICON*.PNG

SPK_PATH="${BUILD_DIR}/${PACKAGE_NAME}-${VERSION}.spk"
SPK_SIZE=$(du -h "$SPK_PATH" | cut -f1)

echo ""
echo "Package built successfully:"
echo "  File: $SPK_PATH"
echo "  Size: $SPK_SIZE"
