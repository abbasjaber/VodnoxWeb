#!/usr/bin/env bash
#
# VODNOX queue worker for shared cPanel hosting.
#
# WHY THIS SHAPE
#   On a cPanel host there is no root access, no systemd and (usually) no
#   Supervisor, so a long-lived `php artisan queue:work` process cannot be kept
#   alive. Instead a per-minute Cron entry runs this script. Each run drains the
#   queue for a bounded time and exits; Cron starts the next one.
#
# SAFETY
#   * Only ONE copy runs at a time. A lock is taken before the worker starts and
#     a second invocation exits immediately — no overlapping workers.
#   * The run is time-bounded (--max-time) so a stuck job can never keep a worker
#     alive forever, and the per-job --timeout is kept BELOW the queue
#     connection's retry_after so a slow job is never handed to a second worker
#     while it is still running (which is what causes duplicate processing).
#   * Every path is absolute and derived from this script's own location, so it
#     can only ever touch the VODNOX project. It never scans for, or signals,
#     any other process — no pkill/kill of other projects' workers.
#   * Output goes to a VODNOX-specific log; `queue:work` never prints job
#     payloads, so no job data or telephony credential lands in the log.
#
# WHAT IT IS NOT
#   `php artisan queue:restart` does NOT start a worker — it only asks running
#   workers to exit after their current job. If this Cron entry is not installed,
#   the queue simply does not run.
#
# INSTALL (cPanel → Cron Jobs → Add New Cron Job), every minute:
#
#   * * * * * /bin/bash /home/<cpanel-user>/public_html/<app>/deploy/cpanel/queue-worker.sh >> /dev/null 2>&1
#
#   Override the PHP binary inline if the host needs a specific one:
#
#   * * * * * VODNOX_PHP=/opt/cpanel/ea-php82/root/usr/bin/php /bin/bash /home/<cpanel-user>/public_html/<app>/deploy/cpanel/queue-worker.sh >> /dev/null 2>&1
#
# VERIFY
#   php artisan vodnox:queue-status                 # backlog + failed count
#   cat storage/logs/vodnox-queue-worker.log        # worker output
#   cat storage/framework/vodnox-queue-worker.heartbeat   # last successful run
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration (all overridable from the Cron command line).
# ---------------------------------------------------------------------------

APP_DIR="${VODNOX_APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

# --timeout must stay BELOW the queue connection's retry_after (config/queue.php:
# DB_QUEUE_RETRY_AFTER, default 90s). Keep these two in sync.
RETRY_AFTER="${VODNOX_QUEUE_RETRY_AFTER:-90}"
JOB_TIMEOUT="${VODNOX_JOB_TIMEOUT:-60}"
TRIES="${VODNOX_TRIES:-3}"
SLEEP="${VODNOX_SLEEP:-3}"
MAX_TIME="${VODNOX_MAX_TIME:-55}"
QUEUE_NAME="${VODNOX_QUEUE:-default}"

LOG_FILE="${VODNOX_WORKER_LOG:-${APP_DIR}/storage/logs/vodnox-queue-worker.log}"
HEARTBEAT_FILE="${VODNOX_WORKER_HEARTBEAT:-${APP_DIR}/storage/framework/vodnox-queue-worker.heartbeat}"
LOCK_FILE="${VODNOX_WORKER_LOCK:-${APP_DIR}/storage/framework/vodnox-queue-worker.lock}"
LOG_MAX_BYTES="${VODNOX_WORKER_LOG_MAX_BYTES:-5242880}"   # 5 MiB

# ---------------------------------------------------------------------------
# Resolve an absolute PHP binary. Plesk/cPanel Cron often ships a minimal PATH,
# so the EA-PHP locations are probed explicitly before falling back to PATH.
# ---------------------------------------------------------------------------

detect_php() {
    local candidate
    for candidate in \
        "${VODNOX_PHP:-}" \
        /usr/local/bin/php \
        /usr/bin/php \
        /opt/cpanel/ea-php84/root/usr/bin/php \
        /opt/cpanel/ea-php83/root/usr/bin/php \
        /opt/cpanel/ea-php82/root/usr/bin/php; do
        [ -n "${candidate}" ] && [ -x "${candidate}" ] && { printf '%s\n' "${candidate}"; return 0; }
    done
    if command -v php >/dev/null 2>&1; then
        command -v php
        return 0
    fi
    return 1
}

PHP_BIN="$(detect_php || true)"

if [ ! -f "${APP_DIR}/artisan" ]; then
    echo "error: ${APP_DIR}/artisan not found — is VODNOX_APP_DIR correct?" >&2
    exit 1
fi

if [ -z "${PHP_BIN}" ]; then
    echo "error: no PHP binary found; set VODNOX_PHP in the Cron command." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Refuse a configuration that would let two workers pick up the same job.
# ---------------------------------------------------------------------------

if [ "${JOB_TIMEOUT}" -ge "${RETRY_AFTER}" ]; then
    echo "error: --timeout (${JOB_TIMEOUT}s) must stay below retry_after (${RETRY_AFTER}s)." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Take the lock. A second Cron run exits quietly while one worker is running.
# ---------------------------------------------------------------------------

mkdir -p "$(dirname "${LOCK_FILE}")" "$(dirname "${LOG_FILE}")" "$(dirname "${HEARTBEAT_FILE}")"

lock_fd=9
if command -v flock >/dev/null 2>&1; then
    eval "exec ${lock_fd}>\"\${LOCK_FILE}\""
    if ! flock -n "${lock_fd}"; then
        exit 0
    fi
else
    # Fallback for hosts without flock: a lock directory is atomic on POSIX.
    LOCK_DIR="${LOCK_FILE}.d"
    if ! mkdir "${LOCK_DIR}" 2>/dev/null; then
        exit 0
    fi
    trap 'rmdir "${LOCK_DIR}" 2>/dev/null || true' EXIT
fi

# ---------------------------------------------------------------------------
# Keep the VODNOX worker log from growing without bound on a shared host.
# ---------------------------------------------------------------------------

if [ -f "${LOG_FILE}" ]; then
    size="$(wc -c <"${LOG_FILE}" 2>/dev/null || echo 0)"
    if [ "${size:-0}" -gt "${LOG_MAX_BYTES}" ]; then
        mv -f "${LOG_FILE}" "${LOG_FILE}.1" 2>/dev/null || true
    fi
fi

# ---------------------------------------------------------------------------
# Run one bounded pass. `queue:work` output contains job class names only —
# never payloads — so nothing sensitive is written to the log.
# ---------------------------------------------------------------------------

{
    echo "--- $(date -u +%Y-%m-%dT%H:%M:%SZ) worker start (queue=${QUEUE_NAME}, timeout=${JOB_TIMEOUT}s, max-time=${MAX_TIME}s) ---"
} >>"${LOG_FILE}"

set +e
"${PHP_BIN}" "${APP_DIR}/artisan" queue:work \
    --queue="${QUEUE_NAME}" \
    --tries="${TRIES}" \
    --timeout="${JOB_TIMEOUT}" \
    --sleep="${SLEEP}" \
    --max-time="${MAX_TIME}" \
    --stop-when-empty \
    >>"${LOG_FILE}" 2>&1
status=$?
set -e

if [ "${status}" -ne 0 ]; then
    echo "--- $(date -u +%Y-%m-%dT%H:%M:%SZ) worker exited with status ${status} ---" >>"${LOG_FILE}"
    exit "${status}"
fi

# Liveness marker for the operator (overwritten each run).
date -u +%Y-%m-%dT%H:%M:%SZ >"${HEARTBEAT_FILE}"

exit 0
