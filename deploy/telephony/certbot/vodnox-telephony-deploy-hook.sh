#!/usr/bin/env bash
#
# Certbot deploy hook for the VODNOX telephony host.
#
# Certbot renews the Let's Encrypt lineage, but Asterisk and Nginx read *copies*
# of the certificate from /etc/asterisk/keys/. Without a hook those copies go
# stale and WSS starts failing once the renewed lineage expires. This hook
# publishes the renewed files, and does nothing else.
#
# Before anything is replaced it guarantees that:
#   1. it acts only on the intended certificate (the domain must be named in
#      RENEWED_DOMAINS, and the certificate must really cover that domain);
#   2. the renewed certificate is valid and not about to expire;
#   3. the private key matches the certificate;
#   4. the copies it is about to replace are backed up first.
#
# Directory and permission safety
# -------------------------------
# The hook writes only inside directories dedicated to it or to the telephony
# stack, and it never re-modes or re-owns a directory that already exists:
# `install -d -m MODE <existing directory>` silently chmods that directory, and
# a log directory derived from /var/log is exactly how a certificate hook ends
# up turning /var/log into 0700 and breaking unrelated services. Directories are
# therefore created only when missing (with the documented mode), and a drifted
# directory is *reported*, never "fixed" behind the operator's back. The shared
# system paths (/var/log, /etc, /var/backups, /etc/asterisk, ...) are refused
# outright. The log file is created 0600 inside /var/log/vodnox-telephony and it
# carries fingerprints and decisions only — never key material.
#
# Failure behaviour
# -----------------
#   * `nginx -t` failing, or the nginx reload failing, is a real failure: the
#     hook exits non-zero and restores the certificate copies it had replaced.
#   * the WSS check is bounded by a timeout so a stalled socket cannot hang the
#     renewal run, and the fingerprint it compares is the one the listener
#     *presents* in the TLS handshake — a matching file on disk is never taken
#     as proof that WSS is serving it.
#   * no automatic restart and no `module unload`. If the listener does not pick
#     the renewed certificate up, the hook prints an explicit maintenance-window
#     instruction and exits non-zero. Established calls are never cut.
#
# What it deliberately never does:
#   * no `http reload` (that CLI command does not exist on Asterisk 22);
#   * no `module unload` of any telephony module;
#   * no automatic restart.
#
# Install (once, as root):
#   install -m 0755 certbot/vodnox-telephony-deploy-hook.sh \
#       /etc/letsencrypt/renewal-hooks/deploy/vodnox-telephony
#
# Certbot calls it with RENEWED_LINEAGE and RENEWED_DOMAINS set; it is a no-op
# when those are absent. See README section 7.1, and
# tests/certbot-deploy-hook.test.sh for the behavioural guarantees.

set -euo pipefail

# Optional overrides live in one file; environment variables always win.
DEFAULTS_FILE="${VODNOX_TLS_DEFAULTS:-/etc/default/vodnox-telephony}"
if [ -r "${DEFAULTS_FILE}" ]; then
    # shellcheck disable=SC1090
    . "${DEFAULTS_FILE}"
fi

DOMAIN="${VODNOX_TLS_DOMAIN:-pbx.vodnox.com}"
KEYS_DIR="${VODNOX_ASTERISK_KEYS_DIR:-/etc/asterisk/keys}"
KEY_OWNER="${VODNOX_TLS_OWNER:-root:asterisk}"
CERT_MODE="${VODNOX_TLS_CERT_MODE:-0640}"
DIR_MODE="${VODNOX_TLS_DIR_MODE:-0750}"
BACKUP_DIR="${VODNOX_BACKUP_DIR:-/var/backups/vodnox-telephony}"
BACKUP_OWNER="${VODNOX_TLS_BACKUP_OWNER:-root:root}"
BACKUP_DIR_MODE="${VODNOX_TLS_BACKUP_DIR_MODE:-0700}"
# A dedicated directory on purpose: the hook must never widen, or narrow, a
# shared directory such as /var/log just to have somewhere to write its log.
LOG_FILE="${VODNOX_TLS_LOG:-/var/log/vodnox-telephony/certbot-deploy.log}"
NGINX_CONF_DIR="${VODNOX_TLS_NGINX_CONF_DIR:-/etc/nginx}"
# Refuse to publish a certificate that expires within this many days.
MIN_DAYS="${VODNOX_TLS_MIN_DAYS:-7}"
WSS_CHECK_HOST="${VODNOX_TLS_WSS_HOST:-127.0.0.1:8089}"
WSS_CHECK_TIMEOUT="${VODNOX_TLS_WSS_TIMEOUT:-10}"

LINEAGE="${RENEWED_LINEAGE:-}"
DOMAINS="${RENEWED_DOMAINS:-}"

# Best-effort logging. The whole statement is wrapped so that an unwritable log
# path (an error the hook is about to report anyway) cannot itself print a
# shell redirection error and disturb the caller.
log() { { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"${LOG_FILE}"; } 2>/dev/null || true; }
say() { printf '%s\n' "$*"; log "$*"; }
fail() { say "ERROR: $*"; exit 1; }

# ---------------------------------------------------------------------------
# Helpers: stat, directory hygiene, bounded execution, WSS probing.
# ---------------------------------------------------------------------------

mode_of() {
    local m
    m="$(stat -c '%a' "$1" 2>/dev/null)" || m=""
    [ -n "${m}" ] || m="$(stat -f '%Lp' "$1" 2>/dev/null)" || m=""
    printf '%s' "${m}"
}

owner_of() {
    local o
    o="$(stat -c '%U:%G' "$1" 2>/dev/null)" || o=""
    [ -n "${o}" ] || o="$(stat -f '%Su:%Sg' "$1" 2>/dev/null)" || o=""
    printf '%s' "${o}"
}

# "750" and "0750" are the same mode.
same_mode() {
    case "${1:-}" in '' | *[!0-9]*) return 1 ;; esac
    case "${2:-}" in '' | *[!0-9]*) return 1 ;; esac
    [ "$((10#${1}))" = "$((10#${2}))" ]
}

# Directories that belong to the system or to another component. This hook has
# no business creating, chmod-ing, chown-ing or writing inside them.
SHARED_DIRS='/ /etc /var /var/log /var/backups /etc/asterisk /etc/letsencrypt /etc/nginx /usr /home'

resolve_dir() {
    # readlink -f is GNU / macOS 12+; fall back to the literal path.
    readlink -f "$1" 2>/dev/null || printf '%s' "$1"
}

refuse_shared_dir() {
    local dir="$1" what="$2" shared resolved
    # Compare both the literal path and its fully resolved form, and resolve the
    # guard list the same way: on macOS /var/log is a symlink to
    # /private/var/log, and a one-sided comparison would let the shared
    # directory through under its other name.
    resolved="$(resolve_dir "${dir}")"
    for shared in ${SHARED_DIRS}; do
        if [ "${dir}" = "${shared}" ] || [ "${resolved}" = "$(resolve_dir "${shared}")" ]; then
            fail "refusing to use ${dir} as the ${what}: it is a shared system directory. Point the matching VODNOX_* variable at a dedicated directory."
        fi
    done
}

# Create a directory only when it is missing. An existing directory keeps the
# mode and owner it has; mutating it would be an unannounced production change.
ensure_owned_dir() {
    local dir="$1" mode="$2"
    [ -d "${dir}" ] && return 0
    install -d -m "${mode}" "${dir}"
}

# Report a drifted directory instead of silently rewriting it.
report_dir() {
    local dir="$1" want_mode="$2" want_owner="${3:-}" have_mode have_owner
    [ -d "${dir}" ] || return 0
    have_mode="$(mode_of "${dir}")"
    have_owner="$(owner_of "${dir}")"
    if ! same_mode "${have_mode}" "${want_mode}"; then
        say "WARNING: ${dir} has mode ${have_mode}, documented as ${want_mode}."
        say "         The hook does not change it (that would alter a live directory):"
        say "         chmod ${want_mode} ${dir}"
    fi
    if [ -n "${want_owner}" ] && [ -n "${have_owner}" ] && [ "${have_owner}" != "${want_owner}" ]; then
        say "WARNING: ${dir} is owned by ${have_owner}, documented as ${want_owner}."
        say "         The hook does not change it (that would alter a live directory):"
        say "         chown ${want_owner} ${dir}"
    fi
}

ensure_log() {
    local dir
    dir="$(dirname "${LOG_FILE}")"
    refuse_shared_dir "${dir}" 'log directory'
    ensure_owned_dir "${dir}" 0700 2>/dev/null || true
    if [ ! -e "${LOG_FILE}" ]; then
        # 0600 from the start: never rely on the caller's umask.
        ( umask 077; : >"${LOG_FILE}" ) 2>/dev/null || true
    fi
}

# Run a command with a hard time limit. GNU coreutils `timeout` is used when it
# exists; otherwise a portable watchdog is used, so the hook can never hang on a
# stalled TLS handshake. Returns 124 on timeout.
run_limited() {
    local secs="$1"
    shift
    if command -v timeout >/dev/null 2>&1; then
        timeout "${secs}" "$@"
        return $?
    fi
    if command -v gtimeout >/dev/null 2>&1; then
        gtimeout "${secs}" "$@"
        return $?
    fi
    local pid waited=0
    "$@" &
    pid=$!
    while kill -0 "${pid}" 2>/dev/null; do
        if [ "${waited}" -ge "${secs}" ]; then
            kill -TERM "${pid}" 2>/dev/null || true
            sleep 1
            kill -KILL "${pid}" 2>/dev/null || true
            wait "${pid}" 2>/dev/null || true
            return 124
        fi
        sleep 1
        waited=$((waited + 1))
    done
    wait "${pid}"
}

# A real TLS handshake against the WSS listener; empty output means "no
# certificate could be read", which is not the same as "the new one is served".
WSS_PROBE_SNIPPET='
printf "" | openssl s_client -connect "${PROBE_HOST}" -servername "${PROBE_DOMAIN}" 2>/dev/null \
    | openssl x509 -noout -fingerprint -sha256 2>/dev/null | sed "s/^.*=//"
'

wss_served_fingerprint() {
    # The probe writes to a file rather than into a pipe on purpose: when the
    # limit fires, only the direct child is killed, so a stalled `openssl` can
    # outlive it for a moment. With a pipe that orphan would keep the reader's
    # stdout open and the hook would still appear to hang; with a file it
    # cannot, and the bounded result is returned immediately.
    local out_file rc=0
    out_file="$(mktemp "${TMPDIR:-/tmp}/vodnox-wss.XXXXXX")" || return 1
    export PROBE_HOST="${WSS_CHECK_HOST}"
    export PROBE_DOMAIN="${DOMAIN}"
    run_limited "${WSS_CHECK_TIMEOUT}" bash -c "${WSS_PROBE_SNIPPET}" >"${out_file}" 2>/dev/null || rc=$?
    tr -d ' \r\n' < "${out_file}"
    rm -f "${out_file}"
    return "${rc}"
}

# Atomic replacement inside the destination directory, with the documented
# ownership and mode applied to the temporary file before the rename.
publish_file() {
    local src="$1" dst="$2" tmp
    tmp="$(mktemp "$(dirname "${dst}")/.$(basename "${dst}").XXXXXX")"
    cp -p "${src}" "${tmp}"
    chown "${KEY_OWNER}" "${tmp}"
    chmod "${CERT_MODE}" "${tmp}"
    mv -f "${tmp}" "${dst}"
}

ROLLBACK_ACTIVE=0
ROLLBACK_CERT_DST=''
ROLLBACK_CERT_BACKUP=''
ROLLBACK_KEY_DST=''
ROLLBACK_KEY_BACKUP=''

restore_or_remove() {
    local dst="$1" backup="$2"
    if [ -n "${backup}" ] && [ -f "${backup}" ]; then
        publish_file "${backup}" "${dst}"
        say "  restored ${dst}"
    elif [ -f "${dst}" ]; then
        rm -f "${dst}"
        say "  removed ${dst} (it did not exist before this run)"
    fi
}

# The certificate copies are the only thing this hook changes, so a failed nginx
# step puts them back exactly as they were. The backups stay on disk for the
# operator, and nothing else — nginx, Asterisk, any live call — is touched.
rollback_and_fail() {
    local reason="$1"
    say "ERROR: ${reason}"
    if [ "${ROLLBACK_ACTIVE}" = "1" ]; then
        say "restoring the certificate copies this hook replaced:"
        restore_or_remove "${ROLLBACK_CERT_DST}" "${ROLLBACK_CERT_BACKUP}"
        restore_or_remove "${ROLLBACK_KEY_DST}" "${ROLLBACK_KEY_BACKUP}"
        say "rollback complete; the previous copies are back in place (backups kept in ${BACKUP_DIR})"
    fi
    exit 1
}

# ---------------------------------------------------------------------------
# 0. Only run when Certbot actually deployed a lineage for our domain.
# ---------------------------------------------------------------------------

ensure_log

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
[ -r "${SRC_KEY}" ] || fail "renewed private key is unreadable: ${SRC_KEY}"

command -v openssl >/dev/null 2>&1 || fail "openssl is required"

# Nothing below may re-mode, re-own or write into a shared system directory.
refuse_shared_dir "${KEYS_DIR}" 'Asterisk keys directory'
refuse_shared_dir "${BACKUP_DIR}" 'backup directory'

if [ -L "${KEYS_DIR}" ]; then
    fail "${KEYS_DIR} is a symlink; refusing to publish certificates through it"
fi

ensure_owned_dir "${KEYS_DIR}" "${DIR_MODE}" || fail "cannot create ${KEYS_DIR}"
[ -d "${KEYS_DIR}" ] || fail "the keys directory is not usable: ${KEYS_DIR}"
report_dir "${KEYS_DIR}" "${DIR_MODE}" "${KEY_OWNER}"

ensure_owned_dir "${BACKUP_DIR}" "${BACKUP_DIR_MODE}" || fail "cannot create ${BACKUP_DIR}"
[ -d "${BACKUP_DIR}" ] || fail "the backup directory is not usable: ${BACKUP_DIR}"
report_dir "${BACKUP_DIR}" "${BACKUP_DIR_MODE}" "${BACKUP_OWNER}"

say "renewal deployed for ${DOMAIN} from ${LINEAGE}"

# ---------------------------------------------------------------------------
# 1. The certificate must cover the domain, be valid, and match the key.
# ---------------------------------------------------------------------------

# openssl feature probes. LibreSSL — the system openssl on macOS — has neither
# `x509 -checkhost` nor `pkey -check`; the checks below fall back to the
# portable equivalent instead of failing on a build that lacks the flag.
x509_help="$(openssl x509 -help 2>&1 || true)"
pkey_help="$(openssl pkey -help 2>&1 || true)"

case "${x509_help}" in
    *-checkhost*)
        openssl x509 -in "${SRC_CERT}" -noout -checkhost "${DOMAIN}" >/dev/null 2>&1 \
            || fail "the renewed certificate does not cover ${DOMAIN}"
        ;;
    *)
        cert_text="$(openssl x509 -in "${SRC_CERT}" -noout -text 2>/dev/null || true)"
        case "${cert_text}" in
            *"DNS:${DOMAIN}"* | *"CN=${DOMAIN}"* | *"CN = ${DOMAIN}"*) : ;;
            *) fail "cannot confirm the renewed certificate covers ${DOMAIN}" ;;
        esac
        ;;
esac

openssl x509 -in "${SRC_CERT}" -noout -checkend "$((MIN_DAYS * 86400))" >/dev/null 2>&1 \
    || fail "the renewed certificate expires within ${MIN_DAYS} days; refusing to publish it"

case "${pkey_help}" in
    *-check*)
        openssl pkey -in "${SRC_KEY}" -noout -check >/dev/null 2>&1 \
            || fail "the renewed private key is not a valid key"
        ;;
esac

cert_pub="$(openssl x509 -in "${SRC_CERT}" -noout -pubkey 2>/dev/null | openssl sha256)"
key_pub="$(openssl pkey -in "${SRC_KEY}" -pubout 2>/dev/null | openssl sha256)"
[ -n "${cert_pub}" ] && [ "${cert_pub}" = "${key_pub}" ] \
    || fail "the renewed private key does not match the renewed certificate"

fingerprint() {
    openssl x509 -in "$1" -noout -fingerprint -sha256 2>/dev/null | sed 's/^.*=//' | tr -d ' \r\n'
}

new_fp="$(fingerprint "${SRC_CERT}")"
say "validated ${DOMAIN} (sha256 ${new_fp})"

# ---------------------------------------------------------------------------
# 2. Decide what still has to be done. A file on disk that matches the renewed
#    certificate is NOT proof that the listener serves it, so the listener is
#    probed as well.
# ---------------------------------------------------------------------------

disk_current=0
if [ -r "${DST_CERT}" ]; then
    current_fp="$(fingerprint "${DST_CERT}")" || current_fp=''
    if [ -n "${current_fp}" ] && [ "${current_fp}" = "${new_fp}" ]; then
        disk_current=1
    fi
fi

served_fp_before="$(wss_served_fingerprint)" || served_fp_before=''

if [ "${disk_current}" = "1" ] && [ "${served_fp_before}" = "${new_fp}" ]; then
    say "already current: ${DST_CERT} and the WSS listener on ${WSS_CHECK_HOST} both present sha256 ${new_fp}; nothing to do"
    exit 0
fi

# ---------------------------------------------------------------------------
# 3. Replace the copies (after backing them up), unless they already match.
# ---------------------------------------------------------------------------

if [ "${disk_current}" = "1" ]; then
    say "the published copy already is the renewed certificate, but the WSS listener does not present it yet; reloading only"
else
    STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

    if [ -f "${DST_CERT}" ]; then
        ROLLBACK_CERT_BACKUP="${BACKUP_DIR}/${DOMAIN}.fullchain.pem.${STAMP}.bak"
        cp -p "${DST_CERT}" "${ROLLBACK_CERT_BACKUP}"
        chmod 0600 "${ROLLBACK_CERT_BACKUP}"
        say "backup: ${ROLLBACK_CERT_BACKUP}"
    fi
    if [ -f "${DST_KEY}" ]; then
        ROLLBACK_KEY_BACKUP="${BACKUP_DIR}/${DOMAIN}.key.${STAMP}.bak"
        cp -p "${DST_KEY}" "${ROLLBACK_KEY_BACKUP}"
        chmod 0600 "${ROLLBACK_KEY_BACKUP}"
        say "backup: ${ROLLBACK_KEY_BACKUP}"
    fi

    publish_file "${SRC_CERT}" "${DST_CERT}"
    publish_file "${SRC_KEY}" "${DST_KEY}"

    ROLLBACK_CERT_DST="${DST_CERT}"
    ROLLBACK_KEY_DST="${DST_KEY}"
    ROLLBACK_ACTIVE=1

    say "published ${DST_CERT} and ${DST_KEY} (${KEY_OWNER} ${CERT_MODE})"
fi

# ---------------------------------------------------------------------------
# 4. Nginx: validate the configuration before reloading it. A failure here is a
#    real failure — non-zero exit, and the replaced copies are rolled back.
# ---------------------------------------------------------------------------

if command -v nginx >/dev/null 2>&1 && [ -d "${NGINX_CONF_DIR}" ]; then
    if nginx_test_output="$(nginx -t 2>&1)"; then
        if command -v systemctl >/dev/null 2>&1; then
            if systemctl reload nginx; then
                say "reloaded: nginx"
            else
                rollback_and_fail "nginx reload failed; nginx was not restarted, and the previous certificate copies were restored"
            fi
        elif nginx -s reload; then
            say "reloaded: nginx"
        else
            rollback_and_fail "nginx reload failed; nginx was not restarted, and the previous certificate copies were restored"
        fi
    else
        say "nginx -t output: ${nginx_test_output}"
        rollback_and_fail "nginx -t failed; nginx was NOT reloaded (fix the configuration, then reload nginx manually)"
    fi
fi

# ---------------------------------------------------------------------------
# 5. Asterisk: re-read http.conf with 'core reload' (never a restart, never a
#    module unload), then confirm the listener really presents the renewed
#    certificate. Without that confirmation nothing is claimed.
# ---------------------------------------------------------------------------

if command -v asterisk >/dev/null 2>&1; then
    if asterisk -rx 'core reload' >/dev/null 2>&1; then
        say "reloaded: asterisk 'core reload' (no restart, no module unload; active calls untouched)"
    else
        say "WARNING: 'core reload' returned non-zero"
    fi

    served_fp_after="$(wss_served_fingerprint)" || served_fp_after=''

    if [ "${served_fp_after}" = "${new_fp}" ]; then
        say "verified: the WSS listener on ${WSS_CHECK_HOST} presents the renewed certificate (sha256 ${new_fp})"
    else
        if [ -z "${served_fp_after}" ]; then
            say "NOTICE: no certificate could be read from ${WSS_CHECK_HOST} within ${WSS_CHECK_TIMEOUT}s."
        else
            say "NOTICE: the WSS listener on ${WSS_CHECK_HOST} still presents sha256 ${served_fp_after}, not the renewed ${new_fp}."
        fi
        say "        Nothing is restarted and no module is unloaded; active calls are untouched."
        say "        The renewed copies are in place, so the listener will pick them up on its"
        say "        next reload/restart. To apply them in a maintenance window:"
        say "            asterisk -rx 'core restart when convenient'"
        exit 1
    fi
else
    say "notice: the asterisk CLI was not found; no reload and no WSS verification were performed"
fi

log "done"
exit 0
