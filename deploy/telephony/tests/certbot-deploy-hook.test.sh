#!/usr/bin/env bash
#
# Behavioural tests for certbot/vodnox-telephony-deploy-hook.sh.
#
# Why this exists instead of a `bash -n` check: the hook writes into live system
# directories, and the rules that matter are behavioural —
#   * a directory that already exists keeps its mode and owner (the regression
#     this suite is built around: the log directory used to be created with
#     `install -d -m 0700 "$(dirname "$LOG")"`, which chmods /var/log itself
#     when the log lives there);
#   * a failing `nginx -t` or nginx reload exits non-zero *and* restores the
#     certificate copies the hook had replaced;
#   * the WSS check is bounded by a timeout and compares the certificate the
#     listener *presents*, not the copy on disk;
#   * nothing in the hook ever restarts Asterisk or unloads a module.
#
# Everything runs inside a temporary fixture tree with no root privileges:
# `id`, `nginx`, `systemctl`, `asterisk` and `openssl` are reached through a stub
# PATH and ownership is targeted at the current user. Nothing outside the
# fixture directory is read or written.
#
# That isolation has to be complete. The hook behaves differently depending on
# which commands it finds, and the GitHub Ubuntu runner ships nginx and systemd:
# a test that quietly relied on `command -v nginx` failing was green on a laptop
# and red in CI. A neutral stub for every command the hook probes for is
# therefore installed with each fixture, and the suite asserts the hook cannot
# reach the host's copy.
#
# Usage:  bash deploy/telephony/tests/certbot-deploy-hook.test.sh
# Exit:   0 when every assertion holds, 1 otherwise.

set -uo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOOK="$(cd "${TESTS_DIR}/.." && pwd)/certbot/vodnox-telephony-deploy-hook.sh"
BASH_BIN="$(command -v bash)"
REAL_OPENSSL="$(command -v openssl || true)"
REAL_ID="$(command -v id || true)"

if [ ! -f "${HOOK}" ]; then
    echo "cannot find the hook at ${HOOK}" >&2
    exit 2
fi
if [ -z "${REAL_OPENSSL}" ]; then
    echo "openssl is required for these tests" >&2
    exit 2
fi

PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); printf 'ok   - %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf 'FAIL - %s\n' "$1"; }
assert_eq() {
    if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (want [$2], got [$1])"; fi
}
assert_contains() {
    case "$1" in *"$2"*) ok "$3" ;; *) bad "$3 (output does not contain [$2])" ;; esac
}
assert_not_contains() {
    case "$1" in *"$2"*) bad "$3 (output contains [$2])" ;; *) ok "$3" ;; esac
}
norm_mode() {
    local m
    m="$(stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1")"
    printf '%s' "${m#0}"
}
fingerprint_of() {
    "${REAL_OPENSSL}" x509 -in "$1" -noout -fingerprint -sha256 2>/dev/null | sed 's/^.*=//'
}

# ---------------------------------------------------------------------------
# Fixture
# ---------------------------------------------------------------------------

WORK="$(mktemp -d)"
FAKE="${WORK}/fixture"
STUBS="${WORK}/stubs"

DOMAIN="pbx.vodnox.com"
SELF_OWNER="$(id -un):$(id -gn)"

mkdir -p "${FAKE}/etc/asterisk/keys" "${FAKE}/etc/nginx" "${FAKE}/var/log" \
    "${FAKE}/var/backups" "${STUBS}"
chmod 0755 "${FAKE}/etc" "${FAKE}/etc/nginx" "${FAKE}/var/log" "${FAKE}/var/backups"
chmod 0750 "${FAKE}/etc/asterisk/keys"

KEYS_DIR="${FAKE}/etc/asterisk/keys"
NGINX_DIR="${FAKE}/etc/nginx"
CERT_PATH="${KEYS_DIR}/${DOMAIN}.fullchain.pem"
KEY_PATH="${KEYS_DIR}/${DOMAIN}.key"
BACKUP_PATH="${FAKE}/var/backups/vodnox-telephony"
LOG_PATH="${FAKE}/var/log/vodnox-telephony/certbot-deploy.log"
LINEAGE="${FAKE}/lineage"
RENEWED_DOMAINS="${DOMAIN}"
WSS_TIMEOUT=10

cleanup() { rm -rf "${WORK}"; }
trap cleanup EXIT

make_cert() {
    local dir="$1" cn="$2"
    mkdir -p "${dir}"
    cat > "${WORK}/openssl.cnf" <<CNF
[req]
distinguished_name = dn
x509_extensions = v3
prompt = no
[dn]
CN = ${cn}
[v3]
subjectAltName = DNS:${cn}
basicConstraints = CA:TRUE
CNF
    "${REAL_OPENSSL}" req -x509 -newkey rsa:2048 -nodes -sha256 -days 365 \
        -config "${WORK}/openssl.cnf" -keyout "${dir}/privkey.pem" \
        -out "${dir}/fullchain.pem" >/dev/null 2>&1
}

fresh_fixture() {
    rm -rf "${FAKE}/etc/asterisk/keys" "${FAKE}/var/backups/vodnox-telephony" \
        "${FAKE}/var/log/vodnox-telephony" "${FAKE}/var/log/certbot-deploy.log" \
        "${FAKE}/lineage"
    mkdir -p "${FAKE}/etc/asterisk/keys" "${FAKE}/etc/nginx" "${FAKE}/var/log" \
        "${FAKE}/var/backups"
    chmod 0755 "${FAKE}/etc" "${FAKE}/etc/nginx" "${FAKE}/var/log" "${FAKE}/var/backups"
    chmod 0750 "${FAKE}/etc/asterisk/keys"
    make_cert "${FAKE}/lineage" "${DOMAIN}"

    BACKUP_PATH="${FAKE}/var/backups/vodnox-telephony"
    LOG_PATH="${FAKE}/var/log/vodnox-telephony/certbot-deploy.log"
    LINEAGE="${FAKE}/lineage"
    RENEWED_DOMAINS="${DOMAIN}"
    WSS_TIMEOUT=10
    rm -f "${STUBS}"/*
    stub_defaults
    # Certbot runs deploy hooks as root; report uid 0 so the happy paths are
    # reachable (the single non-root case overrides this with stub_id_non_root).
    stub_id_root
}

# A published copy that is *not* the renewed certificate (an earlier renewal).
seed_previous_release() {
    make_cert "${WORK}/previous" "${DOMAIN}"
    cp "${WORK}/previous/fullchain.pem" "${CERT_PATH}"
    cp "${WORK}/previous/privkey.pem" "${KEY_PATH}"
    chmod 0640 "${CERT_PATH}" "${KEY_PATH}"
}

# ---------------------------------------------------------------------------
# Stubs
# ---------------------------------------------------------------------------

stub_openssl_serving() { # presents $1 instead of the real network
    local pem="$1"
    cat > "${STUBS}/openssl" <<STUB
#!/usr/bin/env bash
if [ "\${1:-}" = "s_client" ]; then
    cat '${pem}'
    exit 0
fi
exec '${REAL_OPENSSL}' "\$@"
STUB
    chmod +x "${STUBS}/openssl"
}

stub_openssl_silent() { # nothing answers on the port
    cat > "${STUBS}/openssl" <<STUB
#!/usr/bin/env bash
if [ "\${1:-}" = "s_client" ]; then
    exit 1
fi
exec '${REAL_OPENSSL}' "\$@"
STUB
    chmod +x "${STUBS}/openssl"
}

stub_openssl_slow() { # answers, but never finishes the handshake
    cat > "${STUBS}/openssl" <<STUB
#!/usr/bin/env bash
if [ "\${1:-}" = "s_client" ]; then
    sleep 30
    exit 0
fi
exec '${REAL_OPENSSL}' "\$@"
STUB
    chmod +x "${STUBS}/openssl"
}

stub_asterisk() {
    cat > "${STUBS}/asterisk" <<'STUB'
#!/usr/bin/env bash
echo "asterisk stub: $*"
exit 0
STUB
    chmod +x "${STUBS}/asterisk"
}

stub_nginx_ok() {
    cat > "${STUBS}/nginx" <<'STUB'
#!/usr/bin/env bash
echo "nginx stub: $*"
exit 0
STUB
    chmod +x "${STUBS}/nginx"
}

stub_nginx_test_fails() {
    cat > "${STUBS}/nginx" <<'STUB'
#!/usr/bin/env bash
if [ "${1:-}" = "-t" ]; then
    echo "nginx: [emerg] synthetic configuration failure" >&2
    exit 1
fi
exit 0
STUB
    chmod +x "${STUBS}/nginx"
}

stub_systemctl_ok() {
    cat > "${STUBS}/systemctl" <<'STUB'
#!/usr/bin/env bash
echo "systemctl stub: $*"
exit 0
STUB
    chmod +x "${STUBS}/systemctl"
}

stub_systemctl_fails() {
    cat > "${STUBS}/systemctl" <<'STUB'
#!/usr/bin/env bash
echo "systemctl stub: refusing" >&2
exit 1
STUB
    chmod +x "${STUBS}/systemctl"
}

stub_id_non_root() {
    cat > "${STUBS}/id" <<STUB
#!/usr/bin/env bash
if [ "\${1:-}" = "-u" ]; then
    echo 1000
    exit 0
fi
exec '${REAL_ID}' "\$@"
STUB
    chmod +x "${STUBS}/id"
}

# The hook requires uid 0 because Certbot runs deploy hooks as root. The tests
# run unprivileged, so `id` is stubbed to report root; ownership is aimed at the
# current user, which keeps every write inside the fixture.
stub_id_root() {
    cat > "${STUBS}/id" <<STUB
#!/usr/bin/env bash
if [ "\${1:-}" = "-u" ]; then
    echo 0
    exit 0
fi
exec '${REAL_ID}' "\$@"
STUB
    chmod +x "${STUBS}/id"
}

# ---------------------------------------------------------------------------
# Hermetic environment
# ---------------------------------------------------------------------------

# Neutral stubs for every command the hook probes for with `command -v`. They are
# installed by fresh_fixture so the outcome of a test never depends on what the
# host happens to have installed; a test that needs a different behaviour
# overrides the stub it cares about (stub_nginx_test_fails, stub_systemctl_fails,
# stub_asterisk, ...).
stub_defaults() {
    stub_asterisk
    stub_nginx_ok
    stub_systemctl_ok
}

# The guarantees under test only mean something if the hook really consulted the
# stubs. This fails loudly — and says which host command leaked in — instead of
# letting a host-installed nginx/systemd silently steer the hook.
assert_hook_env_is_isolated() {
    local c resolved
    for c in id asterisk nginx systemctl; do
        resolved="$(PATH="${STUBS}:/usr/bin:/bin:/usr/sbin:/sbin" command -v "${c}" 2>/dev/null || true)"
        case "${resolved}" in
            "${STUBS}/"*) ok "the hook's ${c} is the stub (not a host copy)" ;;
            *) bad "the hook's ${c} is the stub, but resolved to [${resolved}]" ;;
        esac
    done
}

# ---------------------------------------------------------------------------
# Runner
# ---------------------------------------------------------------------------

RC=0
OUT=''

run_hook() {
    OUT="$(env -i \
        PATH="${STUBS}:/usr/bin:/bin:/usr/sbin:/sbin" \
        HOME="${WORK}" \
        VODNOX_TLS_DEFAULTS="${WORK}/no-such-defaults-file" \
        VODNOX_TLS_DOMAIN="${DOMAIN}" \
        VODNOX_ASTERISK_KEYS_DIR="${KEYS_DIR}" \
        VODNOX_TLS_OWNER="${SELF_OWNER}" \
        VODNOX_TLS_CERT_MODE='0640' \
        VODNOX_TLS_DIR_MODE='0750' \
        VODNOX_BACKUP_DIR="${BACKUP_PATH}" \
        VODNOX_TLS_LOG="${LOG_PATH}" \
        VODNOX_TLS_NGINX_CONF_DIR="${NGINX_DIR}" \
        VODNOX_TLS_WSS_HOST='127.0.0.1:8089' \
        VODNOX_TLS_WSS_TIMEOUT="${WSS_TIMEOUT}" \
        RENEWED_LINEAGE="${LINEAGE}" \
        RENEWED_DOMAINS="${RENEWED_DOMAINS}" \
        "${BASH_BIN}" "${HOOK}" 2>&1)"
    RC=$?
}

backup_count() {
    if [ -d "${BACKUP_PATH}" ]; then
        ls -1 "${BACKUP_PATH}" | wc -l | tr -d ' '
    else
        printf '0'
    fi
}

# ===========================================================================
# 0. The fixture is hermetic: the host's own nginx / systemd / asterisk can
#    never answer a probe the hook makes.
# ===========================================================================

printf '\n== the fixture does not leak host commands ==\n'
fresh_fixture
assert_hook_env_is_isolated

# ===========================================================================
# 1. It only acts on the certificate it was told about.
# ===========================================================================

printf '\n== skip paths ==\n'
fresh_fixture
LINEAGE=''
run_hook
assert_eq "${RC}" "0" "no RENEWED_LINEAGE: exits 0"
assert_eq "$(ls -A "${KEYS_DIR}")" "" "no RENEWED_LINEAGE: the keys directory stays empty"

fresh_fixture
RENEWED_DOMAINS='another.example.com'
run_hook
assert_eq "${RC}" "0" "a different domain: exits 0"
assert_eq "$(ls -A "${KEYS_DIR}")" "" "a different domain: nothing is published"

# ===========================================================================
# 2. Shared system directories are refused, not re-moded.
# ===========================================================================

printf '\n== shared system directories ==\n'
fresh_fixture
BACKUP_PATH='/etc/asterisk'
run_hook
assert_eq "${RC}" "1" "a shared system directory: fails with a non-zero exit"
assert_contains "${OUT}" "refusing to use /etc/asterisk" "a shared system directory: says why"
assert_eq "$(ls -A "${KEYS_DIR}")" "" "a shared system directory: nothing was written"

fresh_fixture
LOG_PATH='/var/log/certbot-deploy.log' # the shared directory itself
run_hook
assert_eq "${RC}" "1" "a log placed directly in /var/log: fails before touching the system"
assert_contains "${OUT}" "refusing to use /var/log" "a log placed directly in /var/log: says why"
assert_eq "$(ls -A "${KEYS_DIR}")" "" "a log placed directly in /var/log: nothing was written"

# ===========================================================================
# 3. Root is required.
# ===========================================================================

printf '\n== privileges ==\n'
fresh_fixture
stub_id_non_root
run_hook
assert_eq "${RC}" "1" "a non-root run: fails"
assert_contains "${OUT}" "must run as root" "a non-root run: says why"
assert_eq "$(ls -A "${KEYS_DIR}")" "" "a non-root run: nothing was written"

# ===========================================================================
# 4. The parent directories keep the mode they had (the reported regression).
# ===========================================================================

printf '\n== parent directories are never re-moded ==\n'
fresh_fixture
stub_openssl_serving "${LINEAGE}/fullchain.pem"
stub_asterisk
stub_nginx_ok
stub_systemctl_ok
run_hook
assert_eq "${RC}" "0" "publish: exits 0 when the listener presents the renewed certificate"
assert_contains "${OUT}" "verified: the WSS listener" "publish: the presented certificate is verified"
assert_eq "$([ -f "${CERT_PATH}" ] && echo yes || echo no)" "yes" "publish: the certificate copy exists"
assert_eq "$([ -f "${KEY_PATH}" ] && echo yes || echo no)" "yes" "publish: the key copy exists"
assert_eq "$(norm_mode "${CERT_PATH}")" "640" "publish: the certificate copy is 0640"
assert_eq "$(norm_mode "${KEY_PATH}")" "640" "publish: the key copy is 0640"
assert_eq "$(norm_mode "${FAKE}/etc/asterisk/keys")" "750" "publish: the keys directory keeps its mode"
assert_eq "$(norm_mode "${FAKE}/var/log")" "755" "publish: /var/log keeps its mode"
assert_eq "$(norm_mode "${FAKE}/var/backups")" "755" "publish: /var/backups keeps its mode"
assert_eq "$(norm_mode "${FAKE}/etc")" "755" "publish: /etc keeps its mode"
assert_eq "$(norm_mode "${FAKE}/var/log/vodnox-telephony")" "700" "publish: the dedicated log directory is 0700"
assert_eq "$(norm_mode "${LOG_PATH}")" "600" "publish: the log file is 0600"
assert_eq "$(backup_count)" "0" "publish: no backup is taken when there was nothing to replace"
assert_not_contains "${OUT}" "PRIVATE KEY" "publish: no key material in the output"
assert_not_contains "$(cat "${LOG_PATH}")" "PRIVATE KEY" "publish: no key material in the log"

fresh_fixture
LOG_PATH="${FAKE}/var/log/certbot-deploy.log" # directly inside the shared directory
stub_openssl_serving "${LINEAGE}/fullchain.pem"
stub_asterisk
stub_nginx_ok
stub_systemctl_ok
run_hook
assert_eq "${RC}" "0" "log inside /var/log: the run still succeeds"
assert_eq "$(norm_mode "${FAKE}/var/log")" "755" "log inside /var/log: /var/log is still 0755"
assert_eq "$(norm_mode "${FAKE}/var/log/certbot-deploy.log")" "600" "log inside /var/log: the log file itself is 0600"

# ===========================================================================
# 5. A drifted directory is reported, never silently rewritten.
# ===========================================================================

printf '\n== drift is reported, not fixed ==\n'
fresh_fixture
chmod 0700 "${KEYS_DIR}"
stub_openssl_serving "${LINEAGE}/fullchain.pem"
stub_asterisk
stub_nginx_ok
stub_systemctl_ok
run_hook
assert_eq "${RC}" "0" "a drifted keys directory: the run still succeeds"
assert_contains "${OUT}" "WARNING: ${KEYS_DIR} has mode 700" "a drifted keys directory: the drift is reported"
assert_eq "$(norm_mode "${KEYS_DIR}")" "700" "a drifted keys directory: the hook does not chmod it"

# ===========================================================================
# 6. Re-running is a no-op.
# ===========================================================================

printf '\n== idempotency ==\n'
fresh_fixture
seed_previous_release
stub_openssl_serving "${LINEAGE}/fullchain.pem"
stub_asterisk
stub_nginx_ok
stub_systemctl_ok
run_hook
first_rc="${RC}"
first_backups="$(backup_count)"
first_fp="$(fingerprint_of "${CERT_PATH}")"
run_hook
assert_eq "${first_rc}" "0" "first run: publishes the renewed certificate"
assert_eq "${RC}" "0" "second run: exits 0"
assert_contains "${OUT}" "already current" "second run: reports that nothing is left to do"
assert_eq "$(backup_count)" "${first_backups}" "second run: no extra backup is written"
assert_eq "$(fingerprint_of "${CERT_PATH}")" "${first_fp}" "second run: the copy on disk is untouched"
assert_eq "$(fingerprint_of "${CERT_PATH}")" "$(fingerprint_of "${LINEAGE}/fullchain.pem")" "first run: the published copy is the renewed certificate"

# ===========================================================================
# 7. The listener, not the file on disk, is what gets verified.
# ===========================================================================

printf '\n== the presented certificate is what counts ==\n'
fresh_fixture
seed_previous_release
stub_openssl_serving "${WORK}/previous/fullchain.pem" # the listener still serves the old one
stub_asterisk
stub_nginx_ok
stub_systemctl_ok
run_hook
assert_eq "${RC}" "1" "a stale listener: exits non-zero"
assert_not_contains "${OUT}" "verified: the WSS listener" "a stale listener: nothing is claimed as verified"
assert_contains "${OUT}" "still presents" "a stale listener: says which certificate is served"
assert_contains "${OUT}" "core restart when convenient" "a stale listener: points at the maintenance window"
assert_eq "$(fingerprint_of "${CERT_PATH}")" "$(fingerprint_of "${LINEAGE}/fullchain.pem")" "a stale listener: the renewed copy stays published"

# ===========================================================================
# 8. A failing nginx step is a real failure, and it rolls back.
# ===========================================================================

printf '\n== nginx failures roll back ==\n'
fresh_fixture
seed_previous_release
previous_fp="$(fingerprint_of "${CERT_PATH}")"
stub_openssl_silent
stub_asterisk
stub_nginx_test_fails
run_hook
assert_eq "${RC}" "1" "nginx -t failing: exits non-zero"
assert_contains "${OUT}" "nginx -t failed" "nginx -t failing: says so"
assert_contains "${OUT}" "rollback complete" "nginx -t failing: rolls back"
assert_eq "$(fingerprint_of "${CERT_PATH}")" "${previous_fp}" "nginx -t failing: the previous certificate is back"
assert_eq "$(fingerprint_of "${KEY_PATH}")" "$(fingerprint_of "${WORK}/previous/privkey.pem")" "nginx -t failing: the previous key is back"
assert_contains "${OUT}" "nginx: [emerg] synthetic configuration failure" "nginx -t failing: the configuration error is logged"

fresh_fixture
seed_previous_release
previous_fp="$(fingerprint_of "${CERT_PATH}")"
stub_openssl_silent
stub_asterisk
stub_nginx_ok
stub_systemctl_fails
run_hook
assert_eq "${RC}" "1" "the nginx reload failing: exits non-zero"
assert_contains "${OUT}" "nginx reload failed" "the nginx reload failing: says so"
assert_contains "${OUT}" "rollback complete" "the nginx reload failing: rolls back"
assert_eq "$(fingerprint_of "${CERT_PATH}")" "${previous_fp}" "the nginx reload failing: the previous certificate is back"
assert_eq "$(backup_count)" "2" "the nginx reload failing: the backups are kept for the operator"

# ===========================================================================
# 9. The WSS check is bounded, and never hangs the renewal.
# ===========================================================================

printf '\n== the WSS check cannot hang ==\n'
fresh_fixture
# nginx and systemctl keep the neutral defaults from the fixture on purpose: the
# point here is the bounded WSS probe, so no unrelated step may fail first.
stub_openssl_slow
stub_asterisk
WSS_TIMEOUT=2
started="$(date +%s)"
run_hook
elapsed=$(( $(date +%s) - started ))
assert_eq "${RC}" "1" "a stalled listener: exits non-zero"
assert_contains "${OUT}" "no certificate could be read" "a stalled listener: reports that nothing was read"
if [ "${elapsed}" -le 20 ]; then
    ok "a stalled listener: the hook returned in ${elapsed}s (limit 20s)"
else
    bad "a stalled listener: the hook took ${elapsed}s (limit 20s)"
fi

# ===========================================================================
# 10. Static guarantees: no restart, no module unload, no shared path writes.
# ===========================================================================

printf '\n== the hook never restarts or unloads anything ==\n'
reboot_lines="$(awk '
    /^[[:space:]]*#/ { next }
    /(module (unload|reload)|core restart|systemctl restart|service [a-z]+ restart)/ {
        if ($0 !~ /say |log |echo |printf /) print FNR ": " $0
    }
' "${HOOK}")"
assert_eq "${reboot_lines}" "" "no executable restart / module unload line exists"

dir_mutations="$(grep -nE '(chmod|chown)[^|]*\$\{[A-Z_]*DIR' "${HOOK}" || true)"
assert_eq "${dir_mutations}" "" "no chmod/chown targets a directory variable"

install_dirname="$(grep -nE 'install -d[^)]*\$\(dirname' "${HOOK}" || true)"
assert_eq "${install_dirname}" "" "no install -d derives its target from a dirname() expression"

reported_dirs="$(grep -c 'report_dir "' "${HOOK}")"
if [ "${reported_dirs}" -ge 2 ]; then
    ok "existing directories are reported (report_dir is used ${reported_dirs}x)"
else
    bad "existing directories are reported (want >= 2 report_dir calls, got ${reported_dirs})"
fi

printf '\n== summary ==\n'
printf 'passed: %s   failed: %s\n' "${PASS}" "${FAIL}"
if [ "${FAIL}" -gt 0 ]; then
    exit 1
fi
exit 0
