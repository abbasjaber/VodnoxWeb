#!/usr/bin/env bash
#
# Install the VODNOX telephony services on the Asterisk host:
#
#   1. vodnox-provisioning      — the signed provisioning agent (php-fpm)
#   2. vodnox-telephony-events  — the call-event forwarder (Asterisk AMI -> Laravel)
#
# Run from the repository root on the media server:
#     sudo deploy/telephony/bin/install.sh
#
# It is idempotent: re-running it re-copies the services and re-applies ownership
# without touching the generated configuration under /etc/asterisk/vodnox nor the
# secrets under /etc/vodnox-telephony-events.
#
# It never modifies Asterisk itself: manager.conf, http.conf and extensions.conf
# are merged by hand from the *.snippet files, once.
#
# The PHP runtimes are DETECTED, never assumed. The agent's php-fpm and the
# forwarder's CLI are independent of each other and of whatever PHP hosts the
# Laravel application. Override with:
#     VODNOX_PHP_FPM_BIN=/usr/sbin/php-fpm8.3
#     VODNOX_PHP_CLI_BIN=/usr/bin/php8.3

set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"

APP_DIR="/opt/vodnox-telephony"
EVENTS_APP_DIR="/opt/vodnox-telephony-events"
STATE_DIR="/var/lib/vodnox-telephony/state"
SPOOL_DIR="/var/lib/vodnox-telephony/events"
AST_DIR="/etc/asterisk/vodnox"
EVENTS_CONF_DIR="/etc/vodnox-telephony-events"

fail() { echo "error: $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# 1. Detect the PHP runtimes that are actually installed.
# ---------------------------------------------------------------------------

echo "==> 1/11 detecting the PHP runtimes"

detect_php_fpm() {
    if [ -n "${VODNOX_PHP_FPM_BIN:-}" ]; then
        echo "${VODNOX_PHP_FPM_BIN}"
        return
    fi

    # A versioned binary whose matching configuration tree exists is what the
    # unit needs; "php-fpm" without a version is only a fallback.
    local candidate version
    for candidate in $(ls -1 /usr/sbin/php-fpm* /usr/local/sbin/php-fpm* 2>/dev/null | sort -Vr || true); do
        version="${candidate##*php-fpm}"
        if [ -n "${version}" ] && [ -d "/etc/php/${version}/fpm" ]; then
            echo "${candidate}"
            return
        fi
    done

    command -v php-fpm || true
}

PHP_FPM_BIN="$(detect_php_fpm)"
[ -n "${PHP_FPM_BIN}" ] && [ -x "${PHP_FPM_BIN}" ] \
    || fail "no php-fpm binary found; install one or set VODNOX_PHP_FPM_BIN"

PHP_FPM_VERSION="$("${PHP_FPM_BIN}" -v 2>/dev/null | head -n1 | grep -oE '[0-9]+\.[0-9]+' | head -n1 || true)"
[ -n "${PHP_FPM_VERSION}" ] || fail "cannot read the php-fpm version from ${PHP_FPM_BIN}"

PHP_FPM_CONF_DIR="/etc/php/${PHP_FPM_VERSION}/fpm"
[ -d "${PHP_FPM_CONF_DIR}" ] || fail "expected the fpm configuration directory at ${PHP_FPM_CONF_DIR}"

# The forwarder needs only the CLI SAPI; ext-curl is deliberately not required
# (it delivers over streams), so the CLI package alone is enough.
PHP_CLI_BIN="${VODNOX_PHP_CLI_BIN:-$(command -v php || true)}"
[ -n "${PHP_CLI_BIN}" ] && [ -x "${PHP_CLI_BIN}" ] \
    || fail "no php CLI binary found; install php-cli or set VODNOX_PHP_CLI_BIN"

echo "    php-fpm : ${PHP_FPM_BIN} (${PHP_FPM_VERSION}), config ${PHP_FPM_CONF_DIR}"
echo "    php cli : ${PHP_CLI_BIN} ($("${PHP_CLI_BIN}" -r 'echo PHP_VERSION;' 2>/dev/null || echo '?'))"

echo "==> 2/11 checking the Asterisk user"
id asterisk >/dev/null 2>&1 || fail "the 'asterisk' user does not exist"

echo "==> 3/11 snapshotting the current configuration"
"${SRC}/bin/backup.sh" pre-install

echo "==> 4/11 installing the provisioning agent"
install -d -m 0750 "${APP_DIR}"
cp -a "${SRC}"/provisioning/public "${APP_DIR}/"
cp -a "${SRC}"/provisioning/src "${APP_DIR}/"
if [ ! -f "${APP_DIR}/config.php" ]; then
    install -m 0640 -o asterisk -g asterisk "${SRC}/provisioning/config.example.php" "${APP_DIR}/config.php"
    echo "    -> edit ${APP_DIR}/config.php and set the token and signing secret"
fi

echo "==> 5/11 installing the call-event forwarder"
install -d -m 0750 "${EVENTS_APP_DIR}"
install -m 0644 "${SRC}/events/bootstrap.php" "${EVENTS_APP_DIR}/bootstrap.php"
install -m 0644 "${SRC}/events/bin/run.php" "${EVENTS_APP_DIR}/run.php"
cp -a "${SRC}/events/src" "${EVENTS_APP_DIR}/"
install -d -m 0750 -o asterisk -g asterisk "${EVENTS_CONF_DIR}"
if [ ! -f "${EVENTS_CONF_DIR}/config.php" ]; then
    install -m 0640 -o root -g asterisk "${SRC}/events/config.example.php" "${EVENTS_CONF_DIR}/config.php"
    echo "    -> edit ${EVENTS_CONF_DIR}/config.php and set the AMI and webhook secrets"
fi

echo "==> 6/11 creating the writable directories"
install -d -m 0750 -o asterisk -g asterisk "${STATE_DIR}"
install -d -m 0750 -o asterisk -g asterisk "${STATE_DIR}/secrets" "${STATE_DIR}/nonces" "${STATE_DIR}/idempotency"
install -d -m 0750 -o asterisk -g asterisk "${SPOOL_DIR}"

echo "==> 7/11 creating the generated configuration directories"
install -d -m 0750 -o asterisk -g asterisk "${AST_DIR}" "${AST_DIR}/generated" "${AST_DIR}/dialplan"
if [ ! -f "${AST_DIR}/pjsip.conf" ]; then
    install -m 0640 -o asterisk -g asterisk "${SRC}/asterisk/pjsip.conf" "${AST_DIR}/pjsip.conf"
fi

echo "==> 8/11 installing nginx, php-fpm and systemd configuration"
install -m 0644 "${SRC}/nginx/vodnox-provisioning.conf" /etc/nginx/sites-available/vodnox-provisioning
ln -sf /etc/nginx/sites-available/vodnox-provisioning /etc/nginx/sites-enabled/vodnox-provisioning

# Fail-closed by default: with no "allow" line in this file, the server block's
# "deny all" closes the provisioning API to everyone.
if [ ! -f /etc/nginx/vodnox-allow.conf ]; then
    install -m 0644 "${SRC}/nginx/vodnox-allow.conf.example" /etc/nginx/vodnox-allow.conf
    echo "    -> set the application host's egress address in /etc/nginx/vodnox-allow.conf"
fi

install -m 0640 "${SRC}/php-fpm/vodnox-provisioning.conf" "${PHP_FPM_CONF_DIR}/vodnox-provisioning.conf"

render_unit() {
    local source="$1" target="$2"
    sed -e "s|@PHP_FPM_BIN@|${PHP_FPM_BIN}|g" \
        -e "s|@PHP_FPM_CONF@|${PHP_FPM_CONF_DIR}/vodnox-provisioning.conf|g" \
        -e "s|@PHP_CLI_BIN@|${PHP_CLI_BIN}|g" \
        "${source}" > "${target}"
    grep -q '@PHP_' "${target}" && fail "a placeholder was left in ${target}"
    return 0
}

render_unit "${SRC}/systemd/vodnox-provisioning.service" /etc/systemd/system/vodnox-provisioning.service
render_unit "${SRC}/events/systemd/vodnox-telephony-events.service" /etc/systemd/system/vodnox-telephony-events.service

echo "==> 9/11 starting the services"
systemctl daemon-reload
systemctl enable --now vodnox-provisioning.service
systemctl enable --now vodnox-telephony-events.service
nginx -t
systemctl reload nginx

echo "==> 10/11 wiring the includes into Asterisk (manual, once)"
cat <<'MANUAL'
    These files are NOT edited automatically — merge them by hand:
      /etc/asterisk/pjsip.conf      -> #include vodnox/pjsip.conf
      /etc/asterisk/extensions.conf -> #include vodnox/dialplan/*.conf
      /etc/asterisk/http.conf       -> merge asterisk/http.conf.snippet
      /etc/asterisk/manager.conf    -> merge asterisk/manager.conf.snippet

    The AMI account needs a secret, and it must equal ami.secret in
    /etc/vodnox-telephony-events/config.php:
        openssl rand -hex 32

    Apply with reloads only:
        asterisk -rx 'manager reload'
        asterisk -rx 'pjsip reload'
        asterisk -rx 'dialplan reload'
        asterisk -rx 'http reload'

    A change of http.conf bindaddr/bindport is bound at module load: if the WSS
    listener does not appear afterwards, ONE initial Asterisk restart is needed.
    That is the only restart, and it never happens per employee.
MANUAL

echo "==> 11/11 done"
echo "    provisioning : $(systemctl is-active vodnox-provisioning.service || true)"
echo "    events       : $(systemctl is-active vodnox-telephony-events.service || true)"
echo "    check the forwarder log with: journalctl -u vodnox-telephony-events -n 50 --no-pager"
