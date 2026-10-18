#!/usr/bin/env bash
#
# Certbot deploy hook for the VODNOX telephony host.
#
# Certbot renews the Let's Encrypt lineage, but Asterisk and Nginx read *copies*
# of the certificate from /etc/asterisk/keys/. Without a hook those copies go
# stale and WSS starts failing when the renewed-only lineage expires. This hook
# publishes the renewed files.
#
# What it guarantees before anything is replaced:
#   1. it acts only on the intended certificate (the domain must be in
#      RENEWED_DOMAINS, and the certificate must really cover that domain);
#   2. the new certificate is valid and not about to expire;
#   3. the private key matches the certificate;
#   4. the previous copies are backed up.
# Then it copies the files atomically with the documented ownership/mode,
# checks `nginx -t` before reloading Nginx, and re-reads Asterisk with
# `core reload`.
#
# What it deliberately never does:
#   * no `http reload` (that CLI command does not exist on Asterisk 22);
#   * no `module unload` of any telephony module;
#   * no automatic restart — if the WSS listener does not pick the new
#     certificate up, it logs an explicit maintenance-window instruction and
#     exits non-zero so the operator sees it. Established calls are never cut.
#
# Install (once, as root):
#   install -m 0755 certbot/vodnox-telephony-deploy-hook.sh \
#       /etc/letsencrypt/renewal-hooks/deploy/vodnox-telephony
#
# Certbot calls it with RENEWED_LINEAGE and RENEWED_DOMAINS set; it is a no-op
# when those are absent.

set -euo pipefail

# Allow the domain and the paths to be overridden without editing this file.
[ -r /etc/default/vodnox-telephony ] && . /etc/default/vodnox-telephony

DOMAIN="${VODNOX_TLS_DOMAIN:-pbx.vodnox.com}"
KEYS_DIR="${VODNOX_ASTERISK_KEYS_DIR:-/etc/asterisk/keys}"
KEY_OWNER="${VODNOX_TLS_OWNER:-root:asterisk}"
CERT_MODE="${VODNOX_TLS_CERT_MODE:-0640}"
DIR_MODE="${VODNOX_TLS_DIR_MODE:-0750}"
BACKUP_DIR="${VODNOX_BACKUP_DIR:-/var/backups/vodnox-telephony}"
LOG_FILE="${VODNOX_TLS_LOG:-/var/log/vodnox-telephony-certbot.log}"
# Refuse to publish a certificate that expires within this many seconds (7 days).
MIN_DAYS="${VODNOX_TLS_MIN_DAYS:-7}"
WSS_CHECK_HOST="${VODNOX_TLS_WSS_HOST:-127.0.0.1:8089}"

LINEAGE="${RENEWED_LINEAGE:-}"
DOMAINS="${RENEWED_DOMAINS:-}"

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"${LOG_FILE}" 2>/dev/null || true; }
say() { printf '%s\n' "$*"; log "$*"; }
fail() { say "ERROR: $*"; exit 1; }

# ---------------------------------------------------------------------------
# 0. Only run when Certbot actually deployed a lineage for our domain.
# ---------------------------------------------------------------------------

if [ -z "${LINEAGE}" ]; then
    # Manual run with no lineage: nothing was renewed, stay quiet.
    exit 0
fi

if [ -n "${DOMAINS}" ]; then
    match=0
    for d in ${DOMAINS}; do
        [ "${d}" = "${DOMAIN}" ] && match=1
    done
    if [ "${match}" != "1" ]; then
        log "skip: ${DOMAIN} is not in RENEWED_DOMAINS (${DOMAINS}); nothing to publish"
        exit 0
    fi
else
    # Older Certbot: fall back to the lineage directory name.
    if [ "$(basename "${LINEAGE}")" != "${DOMAIN}" ]; then
        log "skip: lineage is $(basename "${LINEAGE}"), not ${DOMAIN}"
        exit 0
    fi
fi

[ "$(id -u)" = "0" ] || fail "must run as root (Certbot deploy hooks run as root)"

SRC_CERT="${LINEAGE}/fullchain.pem"
SRC_KEY="${LINEAGE}/privkey.pem"

DST_CERT="${KEYS_DIR}/${DOMAIN}.fullchain.pem"
DST_KEY="${KEYS_DIR}/${DOMAIN}.key"

[ -r "${SRC_CERT}" ] || fail "renewed certificate is unreadable: ${SRC_CERT}"
[ -r "${SRC_KEY}" ]  || fail "renewed private key is unreadable: ${SRC_KEY}"

command -v openssl >/dev/null 2>&1 || fail "openssl is required"

install -d -m 0700 "$(dirname "${LOG_FILE}")" 2>/dev/null || true
install -d -m "${DIR_MODE}" "${KEYS_DIR}"
install -d -m 0750 "${BACKUP_DIR}"

say "renewal deployed for ${DOMAIN} from ${LINEAGE}"

# ---------------------------------------------------------------------------
# 1. The certificate must cover the domain, be valid, and match the key.
# ---------------------------------------------------------------------------

if openssl x509 -help 2>&1 | grep -q -- '-checkhost'; then
    openssl x509 -in "${SRC_CERT}" -noout -checkhost "${DOMAIN}" >/dev/null 2>&1 \
        || fail "the renewed certificate does not cover ${DOMAIN}"
else
    openssl x509 -in "${SRC_CERT}" -noout -text 2>/dev/null \
        | grep -Eq "(DNS:|CN ?= ?)[^,]*${DOMAIN}" \
        || fail "cannot confirm the renewed certificate covers ${DOMAIN}"
fi

openssl x509 -in "${SRC_CERT}" -noout -checkend "$((MIN_DAYS * 86400))" >/dev/null 2>&1 \
    || fail "the renewed certificate expires within ${MIN_DAYS} days; refusing to publish it"

openssl pkey -in "${SRC_KEY}" -noout -check >/dev/null 2>&1 \
    || fail "the renewed private key is not a valid key"

cert_pub="$(openssl x509 -in "${SRC_CERT}" -noout -pubkey 2>/dev/null | openssl sha256)"
key_pub="$(openssl pkey -in "${SRC_KEY}" -pubout 2>/dev/null | openssl sha256)"
[ -n "${cert_pub}" ] && [ "${cert_pub}" = "${key_pub}" ] \
    || fail "the renewed private key does not match the renewed certificate"

new_fp="$(openssl x509 -in "${SRC_CERT}" -noout -fingerprint -sha256 | sed 's/^.*=//')"
say "validated ${DOMAIN} (sha256 ${new_fp})"

# ---------------------------------------------------------------------------
# 2. Nothing to do when the published copy is already this certificate.
# ---------------------------------------------------------------------------

if [ -r "${DST_CERT}" ]; then
    current_fp="$(openssl x509 -in "${DST_CERT}" -noout -fingerprint -sha256 2>/dev/null | sed 's/^.*=//')"
    if [ -n "${current_fp}" ] && [ "${current_fp}" = "${new_fp}" ]; then
        say "the published certificate is already current; no reload needed"
        exit 0
    fi
fi

# ---------------------------------------------------------------------------
# 3. Back up the copies we are about to replace.
# ---------------------------------------------------------------------------

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
for pair in "${DST_CERT}:fullchain.pem" "${DST_KEY}:key"; do
    src="${pair%%:*}"
    label="${pair##*:}"
    if [ -f "${src}" ]; then
        backup="${BACKUP_DIR}/${DOMAIN}.${label}.${STAMP}.bak"
        cp -p "${src}" "${backup}"
        chmod 0600 "${backup}"
        say "backup: ${backup}"
    fi
done

# ---------------------------------------------------------------------------
# 4. Publish atomically, preserving the documented ownership and mode.
# ---------------------------------------------------------------------------

publish() {
    local src="$1" dst="$2" tmp
    tmp="$(mktemp "${KEYS_DIR}/.$(basename "${dst}").XXXXXX")"
    cp -p "${src}" "${tmp}"
    chown "${KEY_OWNER}" "${tmp}"
    chmod "${CERT_MODE}" "${tmp}"
    mv -f "${tmp}" "${dst}"
}

publish "${SRC_CERT}" "${DST_CERT}"
publish "${SRC_KEY}"  "${DST_KEY}"

chown "${KEY_OWNER}" "${KEYS_DIR}"
chmod "${DIR_MODE}" "${KEYS_DIR}"

say "published ${DST_CERT} and ${DST_KEY} (${KEY_OWNER} ${CERT_MODE})"

# ---------------------------------------------------------------------------
# 5. Nginx: validate the configuration before reloading it.
# ---------------------------------------------------------------------------

if command -v nginx >/dev/null 2>&1 && [ -d /etc/nginx ]; then
    if nginx -t >/dev/null 2>&1; then
        if command -v systemctl >/dev/null 2>&1; then
            systemctl reload nginx && say "reloaded: nginx"
        else
            nginx -s reload && say "reloaded: nginx"
        fi
    else
        say "ERROR: nginx -t failed; nginx was NOT reloaded (fix the configuration and reload manually)"
    fi
fi

# ---------------------------------------------------------------------------
# 6. Asterisk: re-read http.conf with 'core reload' (never a restart), then
#    confirm the WSS listener is really serving the new certificate.
# ---------------------------------------------------------------------------

if command -v asterisk >/dev/null 2>&1; then
    asterisk -rx 'core reload' >/dev/null 2>&1 || say "warning: 'core reload' returned non-zero"
    say "reloaded: asterisk 'core reload' (no restart; active calls untouched)"

    if command -v openssl >/dev/null 2>&1; then
        served_fp="$(echo | openssl s_client -connect "${WSS_CHECK_HOST}" -servername "${DOMAIN}" 2>/dev/null \
            | openssl x509 -noout -fingerprint -sha256 2>/dev/null | sed 's/^.*=//')"
        if [ -n "${served_fp}" ] && [ "${served_fp}" = "${new_fp}" ]; then
            say "verified: WSS on ${WSS_CHECK_HOST} now serves the new certificate"
        else
            say "NOTICE: the WSS listener on ${WSS_CHECK_HOST} does not yet serve the new certificate."
            say "        This does not affect active calls. Apply it in a maintenance window with:"
            say "            asterisk -rx 'core restart when convenient'"
            exit 1
        fi
    fi
fi

log "done"
exit 0
