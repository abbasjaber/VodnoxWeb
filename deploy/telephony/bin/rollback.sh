#!/usr/bin/env bash
#
# Restore an Asterisk configuration snapshot taken by backup.sh and reload the
# affected modules. Asterisk itself is never restarted.
#
# Usage:  sudo deploy/telephony/bin/rollback.sh /var/backups/vodnox-telephony/asterisk-....tar.gz

set -euo pipefail

ARCHIVE="${1:-}"

if [ -z "${ARCHIVE}" ] || [ ! -f "${ARCHIVE}" ]; then
    echo "usage: $0 <archive.tar.gz>" >&2
    exit 2
fi

echo "about to restore ${ARCHIVE}"
echo "the current configuration will be replaced; press Ctrl+C to abort"
read -r -p "continue? [y/N] " reply
case "${reply}" in
    y|Y) ;;
    *) echo "aborted"; exit 1 ;;
esac

# Keep the pre-rollback state so the rollback itself can be undone.
"$(dirname "$0")/backup.sh" pre-rollback

tar -xzf "${ARCHIVE}" -C /

chown -R asterisk:asterisk /etc/asterisk/vodnox /var/lib/vodnox-telephony 2>/dev/null || true

asterisk -rx "pjsip reload"
asterisk -rx "dialplan reload"
asterisk -rx "http reload"

echo "rollback complete"
