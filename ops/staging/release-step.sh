#!/usr/bin/env bash
# shellcheck disable=SC2015  # Compound predicates intentionally refuse when either requirement fails.
# Fixed unprivileged steps, invoked by ctl while the root operation lease remains held.
set -euo pipefail
umask 022
export LC_ALL=C
CONF=/etc/vasey-staging/staging.conf
# shellcheck source=/dev/null
. "$CONF"
: "${VASEY_ROOT:?}" "${VASEY_MIRROR:?}" "${VASEY_PHP:?}" "${VASEY_APP_USER:?}"
die() { echo "release-step: $*" >&2; exit 1; }
step() { echo "release-step: $*"; }
[ "$(id -un)" = "$VASEY_APP_USER" ] || die "wrong application identity"
MODE=${1:-} SHA=${2:-} EVIDENCE=${3:-}
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || die "invalid release SHA"
REL=$VASEY_ROOT/releases/$SHA
PHP=$VASEY_PHP
art() { (cd "$REL" && env -i PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C "$PHP" artisan "$@" --no-interaction --no-ansi); }

case "$MODE" in
  build)
    [ "$#" = 4 ] || die "build requires SHA, evidence and protected environment"
    candidate=$4
    git clone --quiet --no-checkout --no-hardlinks -- "$VASEY_MIRROR" "$REL"
    git -C "$REL" -c advice.detachedHead=false checkout --quiet --detach "$SHA"
    [ "$(git -C "$REL" rev-parse HEAD)" = "$SHA" ] || die "checkout differs from requested SHA"
    [ -z "$(git -C "$REL" status --porcelain --untracked-files=all --ignored)" ] || die "dirty checkout"
    [ "$(git -C "$REL" rev-parse 'HEAD^{tree}')" = "$(git -C "$REL" write-tree)" ] || die "checkout tree differs"
    chmod 0755 "$REL"
    composer_bin=$(command -v composer) || die "composer not found"
    (cd "$REL" && "$PHP" "$composer_bin" install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative)
    [ -f "$REL/vendor/autoload.php" ] && [ -f "$REL/vendor/composer/installed.json" ] || die "composer produced no vendor"
    (cd "$REL" && npm ci --no-audit --no-fund --loglevel=error && npm run build --silent)
    [ -f "$REL/public/build/manifest.json" ] || die "no Vite manifest"
    sha256sum "$REL/composer.lock" "$REL/package-lock.json" "$REL/public/build/manifest.json" | sed "s#$REL/##" > "$EVIDENCE/build.sha256"
    install -m 0600 -- "$candidate" "$REL/.env"
    args=("$REL/.env" "$VASEY_EXPECTED_APP_ENV" "$VASEY_STAGING_HOST" "$VASEY_DB_NAME")
    if [ -L "$VASEY_ROOT/current" ]; then args+=("$(readlink -f "$VASEY_ROOT/current")/.env"); fi
    env -i PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C "$PHP" "$REL/ops/staging/validate-runtime.php" "${args[@]}" \
      || die "built runtime profile refused"
    ;;
  activate)
    [ "$#" = 4 ] && [[ "$4" =~ ^[01]$ ]] || die "activate requires SHA, evidence and first-install state"
    FIRST_INSTALL=$4
    art down >/dev/null
    art migrate:status > "$EVIDENCE/migrate-status-before.txt" 2>&1 || true
    # Self-verifying trigger migrations cannot all run under --pretend; retain it as evidence only.
    art migrate --pretend > "$EVIDENCE/migrate-pretend.sql.txt" 2>&1 \
      || step "WARNING: migrate --pretend failed; retained as evidence"
    art migrate --force > "$EVIDENCE/migrate.txt" 2>&1 || die "migration failed; restore verified snapshot before retry"
    art migrate:status > "$EVIDENCE/migrate-status-after.txt" 2>&1
    art config:cache >/dev/null
    art route:cache >/dev/null
    art view:cache >/dev/null
    art event:cache >/dev/null
    art vasey:doctor --json > "$EVIDENCE/doctor.json" 2>/dev/null || true
    # shellcheck disable=SC2016
    failed=$("$PHP" -r '$d = json_decode(file_get_contents($argv[1]), true); if (! is_array($d) || ! isset($d["checks"])) { echo "unreadable"; exit; } echo implode(",", array_column(array_filter($d["checks"], fn ($c) => $c["status"] === "fail"), "id"));' "$EVIDENCE/doctor.json")
    if [ -n "$failed" ]; then
      [ "$FIRST_INSTALL" = 1 ] && [ "$failed" = operator ] || die "doctor failed checks: $failed"
      step "only operator fails; first install requires operator creation next"
    fi
    art vasey:commerce-readiness --json > "$EVIDENCE/commerce-readiness.json" 2>/dev/null || true
    art vasey:stripe-preflight --json > "$EVIDENCE/stripe-preflight.json" 2>/dev/null || true
    ;;
  *) die "unsupported release step" ;;
esac
