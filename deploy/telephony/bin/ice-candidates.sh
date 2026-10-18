#!/usr/bin/env bash
#
# Set the ICE host candidates in /etc/asterisk/rtp.conf.
#
# WHY: when the Asterisk host sits behind NAT it only advertises its private
# address inside SDP, so WebRTC peers cannot reach it and the call connects with
# NO AUDIO in either direction. This writes Asterisk's own, dependency-free fix
# — a [ice_host_candidates] section mapping the private address to the public
# one — so no external STUN/TURN service is required.
#
# The tool is safe to re-run:
#   * it validates both addresses before touching anything;
#   * it keeps a timestamped backup of the current file;
#   * it removes any previous [ice_host_candidates] section first, so repeated
#     runs can never leave duplicate sections behind;
#   * it writes the file atomically (temp file in the same directory + rename)
#     and preserves the original ownership and permissions;
#   * it reloads the RTP module only. Asterisk is never restarted and active
#     calls are not touched.
#
# The values are per server. They are NOT defaults: pass the pair that belongs
# to the host you are configuring (see README, section 7).

set -euo pipefail

RTP_CONF="${VODNOX_RTP_CONF:-/etc/asterisk/rtp.conf}"
BACKUP_DIR="${VODNOX_BACKUP_DIR:-/var/backups/vodnox-telephony}"
SECTION='ice_host_candidates'
PRIVATE_IP=''
PUBLIC_IP=''
RELOAD=1

fail() { echo "error: $*" >&2; exit 1; }

usage() {
    cat <<'USAGE'
Set the ICE host candidates in /etc/asterisk/rtp.conf (safe to re-run).

  sudo bin/ice-candidates.sh --private <private-ip> --public <public-ip> [options]

Options:
  --private <ip>     the host's own address behind the NAT  (required)
  --public  <ip>     the address the internet sees for it   (required)
  --rtp-conf <path>  rtp.conf location (default /etc/asterisk/rtp.conf)
  --no-reload        write the file but do not reload the RTP module
  -h, --help         this help
USAGE
    exit 0
}

while [ $# -gt 0 ]; do
    case "$1" in
        --private)   PRIVATE_IP="${2:-}"; shift 2 ;;
        --public)    PUBLIC_IP="${2:-}";  shift 2 ;;
        --rtp-conf)  RTP_CONF="${2:-}";   shift 2 ;;
        --no-reload) RELOAD=0;            shift ;;
        -h|--help)   usage ;;
        *) fail "unknown argument: $1 (try --help)" ;;
    esac
done

[ -n "${PRIVATE_IP}" ] || fail "--private is required (the host's own address behind the NAT)"
[ -n "${PUBLIC_IP}" ]  || fail "--public is required (the address the internet sees)"

# ---------------------------------------------------------------------------
# Validate the values before anything is written.
# ---------------------------------------------------------------------------

valid_ipv4() {
    local ip="$1" part
    case "${ip}" in
        ''|*[!0-9.]*) return 1 ;;
    esac
    local IFS='.'
    # shellcheck disable=SC2206
    local parts=(${ip})
    [ "${#parts[@]}" -eq 4 ] || return 1
    for part in "${parts[@]}"; do
        case "${part}" in
            ''|*[!0-9]*) return 1 ;;
        esac
        [ "${part}" -le 255 ] || return 1
        # reject a leading zero ("01"), which other tools read as octal
        [ "${part}" = "0" ] || [ "${part#0}" = "${part}" ] || return 1
    done
    return 0
}

valid_ipv4 "${PRIVATE_IP}" || fail "--private is not a valid IPv4 address: ${PRIVATE_IP}"
valid_ipv4 "${PUBLIC_IP}"  || fail "--public is not a valid IPv4 address: ${PUBLIC_IP}"
[ "${PRIVATE_IP}" != "${PUBLIC_IP}" ] || fail "--private and --public must be different addresses"

[ -f "${RTP_CONF}" ] || fail "${RTP_CONF} does not exist — is this the Asterisk host?"
[ -r "${RTP_CONF}" ] || fail "${RTP_CONF} is not readable"
[ "$(id -u)" = "0" ] || fail "run as root (sudo): both the write and the reload need it"

# ---------------------------------------------------------------------------
# Back up the current file (never overwrite the working configuration blindly).
# ---------------------------------------------------------------------------

install -d -m 0750 "${BACKUP_DIR}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="${BACKUP_DIR}/rtp.conf-${STAMP}-pre-ice.bak"
cp -p "${RTP_CONF}" "${BACKUP}"
chmod 0600 "${BACKUP}"
echo "backup: ${BACKUP}"

# ---------------------------------------------------------------------------
# Build the new content: drop any previous [ice_host_candidates] section, then
# append exactly one, then swap the file in atomically.
# ---------------------------------------------------------------------------

DIR="$(dirname "${RTP_CONF}")"
TMP="$(mktemp "${DIR}/.rtp.conf.vodnox-ice.XXXXXX")"
cleanup() { rm -f "${TMP}"; }
trap cleanup EXIT

awk '
    {
        header = $0
        gsub(/[[:space:]]/, "", header)
        if (header ~ /^\[/) {
            if (header == "['"${SECTION}"']") { drop = 1; next }
            drop = 0
        }
        if (drop) { next }
        print
    }
' "${RTP_CONF}" > "${TMP}"

# The appended section must start on its own line.
if [ -s "${TMP}" ] && [ "$(tail -c 1 "${TMP}" | wc -l)" = "0" ]; then
    printf '\n' >> "${TMP}"
fi

{
    printf '\n; ICE host candidates — written by deploy/telephony/bin/ice-candidates.sh\n'
    printf '; Maps the private address to the public one so NAT does not mute the call.\n'
    printf '[%s]\n' "${SECTION}"
    printf '%s => %s\n' "${PRIVATE_IP}" "${PUBLIC_IP}"
} >> "${TMP}"

MODE="$(stat -c '%a' "${RTP_CONF}")"
OWNER="$(stat -c '%u:%g' "${RTP_CONF}")"
chown "${OWNER}" "${TMP}"
chmod "${MODE}" "${TMP}"
mv -f "${TMP}" "${RTP_CONF}"
trap - EXIT

# ---------------------------------------------------------------------------
# Confirm the result: exactly one section, and the mapping is present.
# ---------------------------------------------------------------------------

COUNT="$(grep -cE "^[[:space:]]*\[${SECTION}\][[:space:]]*$" "${RTP_CONF}" || true)"
[ "${COUNT}" = "1" ] || fail "expected exactly one [${SECTION}] section, found ${COUNT}"
grep -qF "${PRIVATE_IP} => ${PUBLIC_IP}" "${RTP_CONF}" \
    || fail "the mapping was not written to ${RTP_CONF}"

# ---------------------------------------------------------------------------
# Reload the RTP module only — never a restart.
# ---------------------------------------------------------------------------

if [ "${RELOAD}" = "1" ]; then
    if command -v asterisk >/dev/null 2>&1; then
        asterisk -rx 'module reload res_rtp_asterisk.so'
        echo "reloaded: res_rtp_asterisk.so (no restart; active calls untouched)"
    else
        echo "warning: the asterisk CLI was not found — reload manually:" >&2
        echo "  asterisk -rx 'module reload res_rtp_asterisk.so'" >&2
    fi
fi

echo
echo "done: ${RTP_CONF} advertises ${PRIVATE_IP} => ${PUBLIC_IP}"
echo "verify by placing a call: the RTP counters must increase on both ends."
