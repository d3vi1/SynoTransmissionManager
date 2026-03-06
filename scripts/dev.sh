#!/bin/bash
set -euo pipefail

# dev.sh — Rapid development sync to a Synology NAS
#
# Syncs the package files to the NAS for quick iteration without
# building a full .spk.
#
# Usage:
#   ./scripts/dev.sh <nas-host> [nas-user]
#
# Example:
#   ./scripts/dev.sh 192.168.37.21 claude
#
# Prerequisites:
#   - SSH access to the NAS (set up key-based auth for convenience)
#   - rsync installed on both host and NAS
#   - Package must be installed on the NAS first (via .spk)

if [ $# -lt 1 ]; then
    echo "Usage: $0 <nas-host> [nas-user]" >&2
    echo "  nas-host: IP or hostname of the Synology NAS" >&2
    echo "  nas-user: SSH user (default: root)" >&2
    exit 1
fi

NAS_HOST="$1"
NAS_USER="${2:-root}"
PACKAGE="TransmissionManager"
TARGET="/var/packages/$PACKAGE/target"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_DIR"

echo "Syncing to ${NAS_USER}@${NAS_HOST}:${TARGET}..."

# Sync UI files
rsync -avz --delete \
    --exclude '.DS_Store' \
    --exclude '*.md' \
    ui/ \
    "${NAS_USER}@${NAS_HOST}:${TARGET}/ui/"

# Sync PHP backend
rsync -avz --delete \
    --exclude '.DS_Store' \
    --exclude '*.md' \
    webapi/src/ \
    "${NAS_USER}@${NAS_HOST}:${TARGET}/webapi/src/"

# Sync WebAPI lib files
rsync -avz \
    webapi/*.lib \
    "${NAS_USER}@${NAS_HOST}:${TARGET}/webapi/" 2>/dev/null || true

# Sync schema
rsync -avz \
    schema.sql \
    "${NAS_USER}@${NAS_HOST}:${TARGET}/"

# Sync package scripts
rsync -avz --delete \
    --exclude '.DS_Store' \
    package/scripts/ \
    "${NAS_USER}@${NAS_HOST}:/var/packages/$PACKAGE/scripts/"

echo ""
echo "Sync complete. Restarting package..."

# Restart the package
ssh "${NAS_USER}@${NAS_HOST}" "synopkg restart $PACKAGE" 2>/dev/null || \
    ssh "${NAS_USER}@${NAS_HOST}" "/var/packages/$PACKAGE/scripts/start-stop-status stop; /var/packages/$PACKAGE/scripts/start-stop-status start" || true

echo "Done. Open DSM to test changes."
