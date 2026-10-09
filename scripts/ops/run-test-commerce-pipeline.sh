#!/usr/bin/env bash
# One bounded sweep of the Stripe TEST-mode post-payment pipeline, or a bounded loop of sweeps.
#
#   scripts/ops/run-test-commerce-pipeline.sh            # one sweep (systemd timer, Forge scheduler)
#   scripts/ops/run-test-commerce-pipeline.sh --loop     # LOOP_ITERATIONS sweeps, LOOP_SLEEP_SECONDS apart (daemon)
#
# Stages, in order, each drained page by page:
#   1. vasey:process-stripe-receipts   retained webhook receipts: missed dispatch, due retries, expired leases
#   2. vasey:reconcile-test-payments   sessions without verified payment, without a webhook (every RECONCILE_INTERVAL_SECONDS)
#   3. vasey:finalize-test-payments    verified payments without a finalization
#   4. vasey:issue-test-contracts      paid grants without a committed original contract
#   5. vasey:activate-test-fulfillment paid orders without an activation proof
# Stages 2-5 follow the NEXT_AFTER cursor each command prints, so a backlog larger than one page is
# drained across bounded sweeps, not just its first page. Stages 2-5 retain their cursor at the
# page bound, then restart from the beginning after reaching the end so unresolved rows are retried.
# Stage 1 has no cursor; it repeats while a page comes back full,
# because processed, quarantined and not-yet-due receipts leave its selection.
#
# Deliberately NOT run here:
#   - vasey:control-test-delivery: enabling downloads stays a per-order operator decision.
#   - vasey:reconcile-test-checkout: it can re-send Checkout Session creation (a provider write).
# Every command here makes only Stripe GET requests (stages 1-2) or local database/file work (3-5).
#
# Run as the application user that owns storage/app/private (the same user as the queue workers).
# Configuration (environment variables):
#   APP_ROOT                   application root (default: two directories above this script)
#   PHP_BIN                    PHP CLI binary (default: php)
#   PAGE_LIMIT                 --limit per command call, 1-100 (default 25)
#   CONTRACT_PAGE_LIMIT        --limit for contract issuance, 1-100 (default 5; each renders PDFs)
#   MAX_PAGES                  page bound per stage per sweep (default 20)
#   COMMAND_TIMEOUT_SECONDS    wall-clock bound per command call (default 600)
#   RECONCILE_INTERVAL_SECONDS minimum seconds between reconcile sweeps (default 60; 0 = every sweep)
#   STATE_DIR                  lock, reconcile cursor and timestamp (default $APP_ROOT/storage/app/private/test-commerce-pipeline)
#   LOOP_ITERATIONS            sweeps per --loop run (default 60), LOOP_SLEEP_SECONDS between them (default 60)
# On the Forge kit, the fixed root-owned /etc/vasey-staging writer gate admits each sweep under a
# shared lock. Quiesce closes it before draining writers; timers/daemons cannot enter until resume.
# Exit codes: 0 stages succeeded or admission skipped, 1 a stage failed, 2 usage/untrusted admission.
# Logs: one line per item, as "<stage> <opaque id> <bounded outcome>". Command output that does not
# match those shapes is never echoed, so secrets, paths or provider bodies cannot reach the log.
set -uo pipefail
umask 077

APP_ROOT="${APP_ROOT:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)}"
PHP_BIN="${PHP_BIN:-php}"
PAGE_LIMIT="${PAGE_LIMIT:-25}"
CONTRACT_PAGE_LIMIT="${CONTRACT_PAGE_LIMIT:-5}"
MAX_PAGES="${MAX_PAGES:-20}"
COMMAND_TIMEOUT_SECONDS="${COMMAND_TIMEOUT_SECONDS:-600}"
RECONCILE_INTERVAL_SECONDS="${RECONCILE_INTERVAL_SECONDS:-60}"
STATE_DIR="${STATE_DIR:-$APP_ROOT/storage/app/private/test-commerce-pipeline}"
LOOP_ITERATIONS="${LOOP_ITERATIONS:-60}"
LOOP_SLEEP_SECONDS="${LOOP_SLEEP_SECONDS:-60}"

UUID_RE='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
OUTCOME_RE='^[a-z][a-z_]{0,63}$'

log() { printf '%s test-commerce-pipeline %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
usage() { printf 'usage: %s [--loop]\n' "$0" >&2; exit 2; }

integer_in() { [[ "$1" =~ ^[0-9]+$ ]] && (( 10#$1 >= $2 && 10#$1 <= $3 )); }

mode=once
case "${1:-}" in
  '') ;;
  --loop) mode=loop ;;
  *) usage ;;
esac
[[ $# -le 1 ]] || usage
if ! { integer_in "$PAGE_LIMIT" 1 100 && integer_in "$CONTRACT_PAGE_LIMIT" 1 100 && integer_in "$MAX_PAGES" 1 1000 \
  && integer_in "$COMMAND_TIMEOUT_SECONDS" 1 86400 && integer_in "$RECONCILE_INTERVAL_SECONDS" 0 86400 \
  && integer_in "$LOOP_ITERATIONS" 1 100000 && integer_in "$LOOP_SLEEP_SECONDS" 1 86400; }; then
  printf 'invalid numeric setting\n' >&2; exit 2
fi
[[ -f "$APP_ROOT/artisan" ]] || { printf 'APP_ROOT does not contain artisan\n' >&2; exit 2; }
command -v timeout >/dev/null 2>&1 || { printf 'coreutils timeout is required\n' >&2; exit 2; }
command -v flock >/dev/null 2>&1 || { printf 'util-linux flock is required\n' >&2; exit 2; }

writer_admit() {
  local directory=/etc/vasey-staging lock=/etc/vasey-staging/writer.lock gate=/etc/vasey-staging/writer-admission
  local ancestor mode path
  # Other local harnesses need no kit gate. An existing kit directory always requires both files;
  # partial provisioning and dangling links cannot silently fall back to an unguarded sweep.
  [[ -e "$directory" || -L "$directory" ]] || return 0
  [[ -d "$directory" && ! -L "$directory" && "$(/usr/bin/readlink -f -- "$directory")" == "$directory" ]] || return 2
  ancestor=$directory
  while :; do
    [[ "$(/usr/bin/stat -c %u -- "$ancestor")" == 0 ]] || return 2
    mode=$(/usr/bin/stat -c %a -- "$ancestor") || return 2
    (( (8#$mode & 8#022) == 0 )) || return 2
    [[ "$ancestor" != / ]] || break
    ancestor=$(/usr/bin/dirname -- "$ancestor")
  done
  for path in "$lock" "$gate"; do
    [[ -f "$path" && ! -L "$path" && -r "$path" && "$(/usr/bin/stat -c '%u %a' -- "$path")" == '0 644' ]] || return 2
  done
  # The stable lock inode is not replaced by provision or gate updates. Keep this descriptor in
  # artisan children too: killing the runner must not release an orphan child's writer admission.
  exec 8<"$lock" || return 2
  if ! /usr/bin/flock -sn 8; then
    log "writer admission held by host control; skipped"
    return 3
  fi
  [[ "$(/usr/bin/stat -c %s -- "$gate")" -le 7 ]] || return 2
  if /usr/bin/cmp -s -- "$gate" <(printf 'open\n'); then return 0; fi
  if /usr/bin/cmp -s -- "$gate" <(printf 'closed\n'); then
    log "writer admission closed; skipped"; return 3
  fi
  return 2
}

# Publish state atomically, or fail without exposing private paths. A successful sweep must not
# claim continuation/cadence was saved when disk, ownership or path errors prevented it.
save_state() {
  local target="$1" value="$2" scratch
  scratch=$(mktemp "$STATE_DIR/.state.XXXXXXXX" 2>/dev/null) || return 1
  if ! { printf '%s' "$value" > "$scratch" && mv -T -- "$scratch" "$target"; } 2>/dev/null; then
    rm -f -- "$scratch" >/dev/null 2>&1 || true
    return 1
  fi
}

clear_state() { rm -f -- "$1" >/dev/null 2>&1; }

# Runs one artisan page. Sets PAGE_ITEMS and PAGE_NEXT; returns the command's exit status.
# Only "<id> <outcome>" and "NEXT_AFTER=<uuid>" lines are logged; anything else is counted, not shown.
run_page() {
  local stage="$1" id_re="$2"; shift 2
  local output status line suppressed=0
  PAGE_ITEMS=0 PAGE_NEXT=''
  output="$( { cd -- "$APP_ROOT" && timeout --kill-after=30 "$COMMAND_TIMEOUT_SECONDS" "$PHP_BIN" artisan "$@" --no-ansi --no-interaction; } 2>/dev/null)"
  status=$?
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    if [[ "$line" =~ ^NEXT_AFTER=(.+)$ ]] && [[ "${BASH_REMATCH[1]}" =~ $UUID_RE ]]; then
      PAGE_NEXT="${line#NEXT_AFTER=}"
    elif [[ "$line" == *' '* ]] && [[ "${line%% *}" =~ $id_re ]] && [[ "${line#* }" =~ $OUTCOME_RE ]]; then
      PAGE_ITEMS=$((PAGE_ITEMS + 1))
      log "$stage ${line%% *} ${line#* }"
    else
      suppressed=$((suppressed + 1))
    fi
  done <<< "$output"
  (( suppressed == 0 )) || log "$stage suppressed $suppressed unrecognised output line(s)"
  if (( status == 124 || status == 137 )); then log "$stage timed out after ${COMMAND_TIMEOUT_SECONDS}s"; fi
  return "$status"
}

# Stage 1: no cursor. Repeat while a page is full, up to MAX_PAGES.
drain_receipts() {
  local page=0 total=0
  while (( page < MAX_PAGES )); do
    page=$((page + 1))
    if ! run_page receipts '^[1-9][0-9]{0,17}$' vasey:process-stripe-receipts --limit="$PAGE_LIMIT"; then
      log "receipts FAILED (command exit non-zero; check the test-commerce profile)"; return 1
    fi
    total=$((total + PAGE_ITEMS))
    (( PAGE_ITEMS < PAGE_LIMIT )) && { log "receipts done items=$total pages=$page"; return 0; }
  done
  log "receipts page bound reached items=$total pages=$page; the next sweep continues"
}

# Stages 2-5: follow NEXT_AFTER. A cursor_file persists progress across sweeps when the page bound
# is reached. Every cursor stage can retain unresolved rows; saving progress prevents those rows
# from starving later work. Reaching the end clears progress so earlier unresolved rows are revisited.
drain_cursor() {
  local stage="$1" command="$2" limit="$3" cursor_file="${4:-}"
  local after='' page=0 total=0
  if [[ -n "$cursor_file" && -f "$cursor_file" ]]; then
    after="$(head -c 64 -- "$cursor_file" 2>/dev/null)"
    [[ "$after" =~ $UUID_RE ]] || after=''
  fi
  while (( page < MAX_PAGES )); do
    page=$((page + 1))
    local args=("$command" --limit="$limit")
    [[ -n "$after" ]] && args+=(--after="$after")
    if ! run_page "$stage" "$UUID_RE" "${args[@]}"; then
      if [[ -n "$after" && -n "$cursor_file" ]]; then
        # A stored cursor may no longer resolve; restart from the beginning on the next sweep.
        clear_state "$cursor_file" || log "$stage FAILED (cannot clear cursor state)"
      fi
      log "$stage FAILED (command exit non-zero; check the test-commerce profile)"; return 1
    fi
    total=$((total + PAGE_ITEMS))
    if [[ -z "$PAGE_NEXT" ]] || (( PAGE_ITEMS < limit )); then
      if [[ -n "$cursor_file" ]] && ! clear_state "$cursor_file"; then
        log "$stage FAILED (cannot clear cursor state)"; return 1
      fi
      log "$stage done items=$total pages=$page"; return 0
    fi
    after="$PAGE_NEXT"
  done
  if [[ -n "$cursor_file" ]]; then
    if ! save_state "$cursor_file" "$after"; then
      log "$stage FAILED (cannot save cursor state)"; return 1
    fi
    log "$stage page bound reached items=$total pages=$page; resuming after the saved cursor next sweep"
  else
    log "$stage page bound reached items=$total pages=$page; the next sweep continues"
  fi
}

reconcile_due() {
  local stamp="$STATE_DIR/reconcile.last" last now
  (( RECONCILE_INTERVAL_SECONDS == 0 )) && return 0
  [[ -f "$stamp" ]] || return 0
  last="$(head -c 20 -- "$stamp" 2>/dev/null)"
  [[ "$last" =~ ^[0-9]+$ ]] || return 0
  now="$(date +%s)"
  (( now - last >= RECONCILE_INTERVAL_SECONDS || now < last ))
}

sweep() {
  local failed=0
  log "sweep start"
  drain_receipts || failed=1
  if reconcile_due; then
    if drain_cursor reconcile vasey:reconcile-test-payments "$PAGE_LIMIT" "$STATE_DIR/reconcile.cursor"; then
      if ! save_state "$STATE_DIR/reconcile.last" "$(date +%s)"; then
        log "reconcile FAILED (cannot save cadence state)"; failed=1
      fi
    else
      failed=1
    fi
  else
    log "reconcile not due (interval ${RECONCILE_INTERVAL_SECONDS}s)"
  fi
  drain_cursor finalize vasey:finalize-test-payments "$PAGE_LIMIT" "$STATE_DIR/finalize.cursor" || failed=1
  drain_cursor contracts vasey:issue-test-contracts "$CONTRACT_PAGE_LIMIT" "$STATE_DIR/contracts.cursor" || failed=1
  drain_cursor activate vasey:activate-test-fulfillment "$PAGE_LIMIT" "$STATE_DIR/activate.cursor" || failed=1
  log "sweep end status=$failed"
  return "$failed"
}

PIPELINE_LOCK_HELD=0
guarded_sweep() {
  local status
  writer_admit
  status=$?
  if (( status != 0 )); then
    exec 8<&-
    (( status == 3 )) && return 0
    printf 'untrusted staging writer admission\n' >&2; return 2
  fi
  # Even lock/state-directory creation occurs only after admission. Closed timer invocations make
  # no private-store changes while a snapshot is running.
  if ! { mkdir -p -- "$STATE_DIR" && chmod 700 -- "$STATE_DIR"; } 2>/dev/null; then
    exec 8<&-; printf 'cannot prepare STATE_DIR\n' >&2; return 2
  fi
  if (( PIPELINE_LOCK_HELD == 0 )); then
    if ! exec 9>> "$STATE_DIR/pipeline.lock"; then
      exec 8<&-; printf 'cannot open lock file\n' >&2; return 2
    fi
    if ! flock -n 9; then
      exec 9>&- 8<&-
      log "another sweep holds the lock; skipped"; return 0
    fi
    PIPELINE_LOCK_HELD=1
  fi
  sweep
  status=$?
  exec 8<&-
  return "$status"
}

if [[ "$mode" == once ]]; then
  guarded_sweep
  exit $?
fi

overall=0
for (( iteration = 1; iteration <= LOOP_ITERATIONS; iteration++ )); do
  guarded_sweep
  status=$?
  (( status != 2 )) || exit 2
  (( status == 0 )) || overall=1
  (( iteration < LOOP_ITERATIONS )) && sleep "$LOOP_SLEEP_SECONDS"
done
exit "$overall"
