#!/usr/bin/env bash
#
# Build the deployment archive for the Asterisk host.
#
# The archive is assembled from an explicit ALLOW-LIST: a file is only added
# when it is named below. That is deliberately the opposite of "tar the folder
# and exclude a couple of names" — with an allow-list, a config.php, a .env, a
# TLS key, an SSH key or any secret file added later can never be swept into the
# archive by accident.
#
# The contents are listed, and then actually checked, before success is
# reported: the script refuses to hand over an archive that contains anything
# secret-looking.
#
# Usage:  deploy/telephony/bin/package.sh [output-directory]

set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="${1:-${SRC}/dist}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
ARCHIVE="${OUT_DIR}/vodnox-telephony-${STAMP}.tar.gz"

# ---------------------------------------------------------------------------
# The allow-list. Every entry must exist.
# ---------------------------------------------------------------------------

FILES=(
    README.md
    .env.example

    bin/backup.sh
    bin/ice-candidates.sh
    bin/install.sh
    bin/rollback.sh

    asterisk/extensions.conf.snippet
    asterisk/http.conf.snippet
    asterisk/manager.conf.snippet
    asterisk/pjsip.conf
    asterisk/rtp.conf.snippet

    certbot/vodnox-telephony-deploy-hook.sh
    tests/certbot-deploy-hook.test.sh

    nginx/vodnox-allow.conf.example
    nginx/vodnox-provisioning.conf
    php-fpm/vodnox-provisioning.conf
    systemd/vodnox-provisioning.service

    events/bootstrap.php
    events/bin/run.php
    events/config.example.php
    events/systemd/vodnox-telephony-events.service

    provisioning/config.example.php
)

DIRS=(
    events/src
    provisioning/public
    provisioning/src
)

for entry in "${FILES[@]}" "${DIRS[@]}"; do
    if [ ! -e "${SRC}/${entry}" ]; then
        echo "the allow-list names a missing path: ${entry}" >&2
        exit 1
    fi
done

install -d -m 0750 "${OUT_DIR}"

tar -czf "${ARCHIVE}" -C "${SRC}" "${FILES[@]}" "${DIRS[@]}"
chmod 0600 "${ARCHIVE}"

gzip -t "${ARCHIVE}"

# ---------------------------------------------------------------------------
# Inspect before handing over.
# ---------------------------------------------------------------------------

LIST="$(tar -tzf "${ARCHIVE}")"

echo "==> contents of $(basename "${ARCHIVE}")"
echo "${LIST}" | sort

# Exact path components only: config.example.php and .env.example must stay
# allowed, while config.php and .env must never appear.
FORBIDDEN='(^|/)(config\.php|\.env(\.[a-z]+)?|id_rsa|id_ed25519|[^/]*\.(key|pem|p12|pfx|jks)|secrets?|state|rejected)(/|$)'
ALLOWED_EXAMPLES='(^|/)(\.env\.example|config\.example\.php)$'

VIOLATIONS="$(echo "${LIST}" | grep -E "${FORBIDDEN}" | grep -Ev "${ALLOWED_EXAMPLES}" || true)"

if [ -n "${VIOLATIONS}" ]; then
    echo >&2
    echo "refusing: the archive contains something that may carry a secret:" >&2
    echo "${VIOLATIONS}" >&2
    exit 1
fi

echo
echo "==> no secret-looking file is present; allowed examples :"
echo "${LIST}" | grep -E '(^|/)(config\.example\.php|\.env\.example)$'

echo
echo "archive: ${ARCHIVE}"
echo "verify on the target before extracting:  tar -tzf $(basename "${ARCHIVE}")"
