#!/bin/bash
set -euo pipefail

# Read version from package/INFO
VERSION=$(grep '^version=' package/INFO | cut -d'"' -f2)
PACKAGE_NAME="TransmissionManager"
BUILD_DIR="build"

echo "Building ${PACKAGE_NAME} v${VERSION}..."

# Clean previous build
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/staging"

# Copy package files
cp -r package "$BUILD_DIR/staging/"
cp -r ui "$BUILD_DIR/staging/"
cp -r webapi "$BUILD_DIR/staging/"
cp -r etc "$BUILD_DIR/staging/"
cp schema.sql "$BUILD_DIR/staging/"

# Create inner tarball
cd "$BUILD_DIR/staging"
tar czf ../package.tgz .
cd ../..

# Create SPK (outer tarball)
cd "$BUILD_DIR"
tar czf "${PACKAGE_NAME}-${VERSION}.spk" package.tgz
rm -rf staging package.tgz
cd ..

echo "Package built: ${BUILD_DIR}/${PACKAGE_NAME}-${VERSION}.spk"
