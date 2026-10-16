#!/usr/bin/env bash
#
# Snapshot the Asterisk configuration (and the provisioning agent state) before
# any change. Keep the archives outside /etc so a bad edit can always be undone.
#
# Usage:  sudo deploy/telephony/bin/backup.sh [label]

set -euo pipefail

BACKUP_DIR="${VODNOX_BACKUP_DIR:-/var/backups/vodnox-telephony}"
KEEP="${VODNOX_BACKUP_KEEP:-14}"
LABEL="${1:-manual}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
ARCHIVE="${BACKUP_DIR}/asterisk-${STAMP}-${LABEL}.tar.gz"

install -d -m 0750 "${BACKUP_DIR}"

# Configuration, TLS keys and the agent's own state (which holds the SIP
# passwords it generated). Everything stays on the media server.
tar -czf "${ARCHIVE}" \
    -C / \
    etc/asterisk \
    var/lib/vodnox-telephony \
    2>/dev/null || true

chmod 0600 "${ARCHIVE}"

# Retain only the newest archives.
mapfile -t old < <(ls -1t "${BACKUP_DIR}"/asterisk-*.tar.gz 2>/dev/null | tail -n "+$((KEEP + 1))" || true)
if [ "${#old[@]}" -gt 0 ]; then
    rm -f "${old[@]}"
fi

echo "backup written: ${ARCHIVE}"
