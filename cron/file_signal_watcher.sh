#!/usr/bin/env bash

###############################################################################
# File-based Signal Watcher Daemon
#
# Listens for changes to getSignal.txt and triggers immediate processing
# via the PHP file_signal_processor.php bridge.
#
# Requirements:
#   - inotify-tools installed (inotifywait)
#   - PHP CLI available
#
# Recommended systemd service (example):
#   [Unit]
#   Description=VTM Option File Signal Watcher
#   After=network.target
#
#   [Service]
#   Type=simple
#   WorkingDirectory=/app
#   ExecStart=/app/cron/file_signal_watcher.sh
#   Restart=always
#   RestartSec=1
#   User=www-data
#
#   [Install]
#   WantedBy=multi-user.target
#
###############################################################################

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(dirname "$SCRIPT_DIR")"

SIGNAL_FILE="${APP_ROOT}/getSignal.txt"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
PROCESSOR="${SCRIPT_DIR}/file_signal_processor.php"
LOG_DIR="${APP_ROOT}/logs"
LOG_FILE="${LOG_DIR}/file_signal_watcher.log"

mkdir -p "$LOG_DIR"

log() {
  local level="$1"; shift
  local msg="$*"
  # ISO-8601 with milliseconds where supported
  local ts
  ts="$(date +"%Y-%m-%dT%H:%M:%S.%3N%z" 2>/dev/null || date +"%Y-%m-%dT%H:%M:%S%z")"
  echo "[$ts] [$level] $msg" | tee -a "$LOG_FILE" >/dev/null
}

if ! command -v inotifywait >/dev/null 2>&1; then
  log "ERROR" "inotifywait not found. Please install inotify-tools."
  exit 1
fi

if [ ! -f "$PROCESSOR" ]; then
  log "ERROR" "Processor script not found: $PROCESSOR"
  exit 1
fi

if [ ! -f "$SIGNAL_FILE" ]; then
  log "INFO" "Signal file not found; creating empty file at $SIGNAL_FILE"
  touch "$SIGNAL_FILE" || {
    log "ERROR" "Unable to create signal file at $SIGNAL_FILE"
    exit 1
  }
fi

log "INFO" "Starting file signal watcher on $SIGNAL_FILE using $PHP_BIN"

while true; do
  # -m: monitor; -e close_write,modify: react on actual writes
  inotifywait -q -m -e close_write,modify "$SIGNAL_FILE" 2>>"$LOG_FILE" | while read -r path event file; do
    # Debounce: if file is empty, processor will no-op immediately
    local_start_ns=$(date +%s%N 2>/dev/null || echo 0)
    log "INFO" "Change detected ($event) on $file; invoking processor"

    "$PHP_BIN" "$PROCESSOR"
    exit_code=$?

    local_end_ns=$(date +%s%N 2>/dev/null || echo 0)
    local duration_ms=""
    if [ "$local_start_ns" -ne 0 ] && [ "$local_end_ns" -ne 0 ]; then
      duration_ms=$(( (local_end_ns - local_start_ns) / 1000000 ))
    fi

    if [ "$exit_code" -eq 0 ]; then
      if [ -n "$duration_ms" ]; then
        log "INFO" "Processor completed successfully (exit=$exit_code, ${duration_ms}ms)"
      else
        log "INFO" "Processor completed successfully (exit=$exit_code)"
      fi
    else
      if [ -n "$duration_ms" ]; then
        log "ERROR" "Processor failed (exit=$exit_code, ${duration_ms}ms)"
      else
        log "ERROR" "Processor failed (exit=$exit_code)"
      fi
    fi
  done

  # If we got here, inotifywait loop exited; log and restart after brief delay
  log "WARN" "inotifywait loop terminated unexpectedly; restarting in 1s"
  sleep 1
done


