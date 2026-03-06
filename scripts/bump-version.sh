#!/bin/bash
set -euo pipefail

# bump-version.sh — Update the version in package/INFO
#
# Usage:
#   ./scripts/bump-version.sh <new-version>
#
# Example:
#   ./scripts/bump-version.sh 0.2.0
#   ./scripts/bump-version.sh 1.0.0-beta1

if [ $# -ne 1 ]; then
    echo "Usage: $0 <new-version>" >&2
    echo "  Example: $0 0.2.0" >&2
    exit 1
fi

NEW_VERSION="$1"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
INFO_FILE="$PROJECT_DIR/package/INFO"

if [ ! -f "$INFO_FILE" ]; then
    echo "ERROR: $INFO_FILE not found" >&2
    exit 1
fi

OLD_VERSION=$(grep '^version=' "$INFO_FILE" | cut -d'"' -f2)

# Update version in INFO
sed -i.bak "s/^version=\".*\"/version=\"${NEW_VERSION}\"/" "$INFO_FILE"
rm -f "${INFO_FILE}.bak"

echo "Version bumped: ${OLD_VERSION} -> ${NEW_VERSION}"
echo "Updated: $INFO_FILE"
