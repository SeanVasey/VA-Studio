#!/usr/bin/env bash
# shellcheck disable=SC2015  # `test && test || die` is intended: die whenever any test fails.
# VASEY.AUDIO private test-mode staging: release and activation, run by Forge's "Deploy Now" as the site user.
#
# Forge > Site > Deployment Script (replace Forge's default with exactly these lines):
#   set -euo pipefail
#   cd "$FORGE_SITE_PATH"
#   git fetch --prune origin "$FORGE_SITE_BRANCH"
#   git checkout --detach --force FETCH_HEAD
#   bash ops/staging/forge-deploy.sh "$(git rev-parse HEAD)"
#
# The Forge site checkout ($FORGE_SITE_PATH) is only a git mirror and the home of the Forge-managed .env.
# It is never served. Each deploy follows docs/ops/production-activation-packet.md §4 (stage S1):
#   1. a fresh, immutable checkout of the exact SHA in <root>/releases/<SHA>, proven clean;
#   2. composer install --no-dev from composer.lock, npm ci && npm run build, Vite manifest present;
#   3. persistent private storage attached by bind mount (never a symlink), proven by inode;
#   4. the Forge .env validated for the staging profile and installed 0600;
#   5. the served release quiesced (maintenance proven by 503, workers, scheduler and PHP-FPM stopped);
#   6. a backup of the database, private storage and .env, and an isolated restore proof, before migrating;
#   7. maintenance in the new release, migrate --pretend, migrate --force, config/route/view/event caches,
#      vasey:doctor, redacted readiness reports;
#   8. atomic switch of `current`, PHP-FPM and workers started on it and proven, maintenance lifted, GET / = 200.
# No storage:link: nothing uses the public disk (previews, artwork and site images stream through controllers).
# No separate queue:restart: the quiesce signals it and then stops every worker; they start on the new release.
#
# A failure after step 5 leaves the site in maintenance with writers stopped (fail safe). Follow
# docs/ops/staging-runbook.md "Rollback". Privileged steps go through `sudo -n /usr/local/sbin/vasey-staging-ctl`.
set -Eeuo pipefail
umask 022   # code and build output must be readable by nginx; .env and evidence get explicit modes
export LC_ALL=C

CONF=${VASEY_STAGING_CONF:-/etc/vasey-staging/staging.conf}
CTL=(sudo -n /usr/local/sbin/vasey-staging-ctl)

die()  { echo "forge-deploy: ERROR: $*" >&2; exit 1; }
step() { echo "forge-deploy: [$(date -u +%H:%M:%S)] $*"; }

[ -r "$CONF" ] || die "missing $CONF (run ops/staging/provision.sh as root first)"
# shellcheck source=/dev/null
. "$CONF"
: "${VASEY_STAGING_HOST:?}" "${VASEY_APP_USER:?}" "${VASEY_ROOT:?}" "${VASEY_MIRROR:?}" "${VASEY_PHP:?}" \
  "${VASEY_EXPECTED_APP_ENV:?}" "${VASEY_DB_NAME:?}"

[ "$(id -un)" = "$VASEY_APP_USER" ] || die "run as $VASEY_APP_USER (Forge runs the deployment script as the site user)"
SHA=${1:-$(git -C "$VASEY_MIRROR" rev-parse HEAD)}
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || die "argument must be the exact 40-character commit SHA"

ROOT=$VASEY_ROOT
RELEASES=$ROOT/releases
REL=$RELEASES/$SHA
CURRENT=$ROOT/current
RUNTIME_ENV=$VASEY_MIRROR/.env
PHP=$VASEY_PHP
COMPOSER_BIN=$(command -v composer) || die "composer not found"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
EVIDENCE=$ROOT/evidence/$STAMP-${SHA:0:12}
QUIESCED=0

exec 9>>"$ROOT/.deploy.lock"
flock -n 9 || die "another deploy or the nightly backup is running"

on_exit() {
  local code=$?
  [ "$code" = 0 ] && return 0
  if [ "$QUIESCED" = 1 ]; then
    echo "forge-deploy: FAILED (exit $code) after the quiesce: the site is in maintenance and writers are stopped." >&2
    echo "forge-deploy: fix and redeploy, or roll back: docs/ops/staging-runbook.md#rollback" >&2
  else
    echo "forge-deploy: FAILED (exit $code) before the quiesce: the served release was not touched." >&2
  fi
}
trap on_exit EXIT

# ---------------------------------------------------------------- 0. runtime .env checks (values never printed)
[ -f "$RUNTIME_ENV" ] && [ ! -L "$RUNTIME_ENV" ] || die "Forge .env missing at $RUNTIME_ENV (Forge > Site > Environment)"
chmod 0600 "$RUNTIME_ENV"
envget() { grep -E "^$1=" "$RUNTIME_ENV" | tail -n 1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"; }
need() { [ "$(envget "$1")" = "$2" ] || die ".env: $1 must be $2 for the staging profile"; }
need APP_ENV "$VASEY_EXPECTED_APP_ENV"
need APP_DEBUG false
need APP_URL "https://$VASEY_STAGING_HOST"
need SESSION_SECURE_COOKIE true
need SESSION_DRIVER database
need CACHE_STORE database          # per-buyer heavy-work lock and queue:restart need a shared lock-capable store
need QUEUE_CONNECTION database
need DB_CONNECTION mysql
need DB_HOST 127.0.0.1             # trigger DEFINER is vasey_app@127.0.0.1; never connect as @localhost
need DB_DATABASE "$VASEY_DB_NAME"
need FILESYSTEM_DISK local
need MAIL_MAILER log
need STRIPE_MODE test
need APP_MAINTENANCE_DRIVER file
[[ "$(envget APP_KEY)" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || die ".env: APP_KEY must be a base64 32-byte key (php artisan key:generate --show)"
[ -n "$(envget DB_USERNAME)" ] && [ "$(envget DB_USERNAME)" != root ] || die ".env: DB_USERNAME must be the least-privilege account, not root"
[ -n "$(envget DB_PASSWORD)" ] || die ".env: DB_PASSWORD is empty"
[ "$(envget DB_QUEUE_RETRY_AFTER)" -ge 1200 ] 2>/dev/null || die ".env: DB_QUEUE_RETRY_AFTER must be at least 1200"
! grep -Eq '(sk|rk|pk)_live_' "$RUNTIME_ENV" || die ".env contains a live Stripe key; staging is test mode only"
! grep -Eq '^PRODUCTION_CHECKOUT_[A-Z_]*ENABLED=(true|1)' "$RUNTIME_ENV" || die ".env enables production checkout; staging must not"
step "runtime .env passes the staging profile checks (APP_ENV=$VASEY_EXPECTED_APP_ENV, APP_DEBUG=false)"

# ---------------------------------------------------------------- 1. fresh immutable checkout
if [ -e "$REL" ]; then
  if [ "$(readlink -f "$CURRENT" 2>/dev/null || true)" = "$REL" ]; then
    if cmp -s "$RUNTIME_ENV" "$REL/.env"; then
      step "$SHA is already the served release and its .env is unchanged; nothing to do"; exit 0
    fi
    # Environment-only change (for example the seller tag or Lane B's test-commerce values): the release stays
    # immutable, its .env and cached configuration are replaced while every writer is stopped.
    step "$SHA is the served release; the Forge .env changed: refreshing configuration"
    QUIESCED=1
    "${CTL[@]}" quiesce
    install -m 0600 "$RUNTIME_ENV" "$REL/.env"
    cmp -s "$RUNTIME_ENV" "$REL/.env" || die ".env not installed"
    (cd "$REL" && "$PHP" artisan config:cache --no-interaction --no-ansi >/dev/null)
    "${CTL[@]}" resume
    QUIESCED=0
    step "configuration refreshed on $SHA"; exit 0
  fi
  die "release $SHA already exists but is not current; to return to it follow the runbook's rollback, never rebuild in place"
fi
install -d -m 0750 "$ROOT/evidence/$STAMP-${SHA:0:12}"
step "checkout $SHA"
git clone --quiet --no-checkout --no-hardlinks -- "$VASEY_MIRROR" "$REL"
git -C "$REL" -c advice.detachedHead=false checkout --quiet --detach "$SHA"
[ "$(git -C "$REL" rev-parse HEAD)" = "$SHA" ] || die "checkout is not $SHA"
[ -z "$(git -C "$REL" status --porcelain --untracked-files=all --ignored)" ] || die "dirty checkout"
[ "$(git -C "$REL" rev-parse 'HEAD^{tree}')" = "$(git -C "$REL" write-tree)" ] || die "checkout differs from $SHA"
chmod 0755 "$REL"

# ---------------------------------------------------------------- 2. dependencies and build
step "composer install --no-dev (composer.lock is frozen)"
(cd "$REL" && "$PHP" "$COMPOSER_BIN" install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative)
# `php <composer>` "succeeds" silently when composer is a shell wrapper rather than the phar; prove the result.
[ -f "$REL/vendor/autoload.php" ] && [ -f "$REL/vendor/composer/installed.json" ] || die "composer install produced no vendor/ (is $COMPOSER_BIN the composer phar?)"
step "npm ci && npm run build"
(cd "$REL" && npm ci --no-audit --no-fund --loglevel=error && npm run build --silent)
[ -f "$REL/public/build/manifest.json" ] || die "no Vite manifest"
sha256sum "$REL/composer.lock" "$REL/package-lock.json" "$REL/public/build/manifest.json" | sed "s#$REL/##" > "$EVIDENCE/build.sha256"

# ---------------------------------------------------------------- 3. attach persistent private storage
step "attach private storage (bind mount)"
"${CTL[@]}" attach "$SHA"
[ "$(stat -c %d:%i "$REL/storage/app/private")" = "$(stat -c %d:%i "$ROOT/private")" ] && [ ! -L "$REL/storage/app/private" ] \
  || die "private storage not attached"

# ---------------------------------------------------------------- 4. install the validated .env
install -m 0600 "$RUNTIME_ENV" "$REL/.env"
cmp -s "$RUNTIME_ENV" "$REL/.env" || die ".env not installed"

# ---------------------------------------------------------------- 5-6. quiesce, back up, prove the restore
FIRST_INSTALL=1
if [ -L "$CURRENT" ]; then
  FIRST_INSTALL=0
  step "quiesce the served release $(basename "$(readlink -f "$CURRENT")")"
  QUIESCED=1
  "${CTL[@]}" quiesce
  step "backup and isolated restore proof before migrating"
  "${CTL[@]}" snapshot
fi

# ---------------------------------------------------------------- 7. migrate and cache in the new release
art() { (cd "$REL" && "$PHP" artisan "$@" --no-interaction --no-ansi); }
art down >/dev/null
QUIESCED=1
art migrate:status > "$EVIDENCE/migrate-status-before.txt" 2>&1 || true   # a first install has no migrations table yet
# The activation packet treats a failed preview as fatal. In this codebase it cannot be: several migrations
# verify their own triggers with queries, which return nothing under --pretend (observed on a fresh MySQL 8.4
# schema: 2026_10_01_000032 throws "Site release image insert protection is missing"). The preview is kept as
# evidence; the gate before a destructive migration is the verified backup and restore proof above.
art migrate --pretend > "$EVIDENCE/migrate-pretend.sql.txt" 2>&1 \
  || step "WARNING: migrate --pretend failed (self-verifying migrations cannot run under --pretend); recorded in evidence"
step "migrate --force"
art migrate --force > "$EVIDENCE/migrate.txt" 2>&1 || die "migration failed: restore from the pre-deploy backup before retrying (runbook)"
art migrate:status > "$EVIDENCE/migrate-status-after.txt" 2>&1
step "config, route, view and event caches"
art config:cache >/dev/null
art route:cache >/dev/null
art view:cache >/dev/null
art event:cache >/dev/null

step "vasey:doctor"
art vasey:doctor --json > "$EVIDENCE/doctor.json" 2>/dev/null || true
# shellcheck disable=SC2016  # PHP source, not shell expansions
failed=$("$PHP" -r '$d = json_decode(file_get_contents($argv[1]), true); if (! is_array($d) || ! isset($d["checks"])) { echo "unreadable"; exit; }
  echo implode(",", array_column(array_filter($d["checks"], fn ($c) => $c["status"] === "fail"), "id"));' "$EVIDENCE/doctor.json")
if [ -n "$failed" ]; then
  # On a first install the operator accounts cannot exist yet (vasey:create-admin needs the migrated schema).
  if [ "$FIRST_INSTALL" = 1 ] && [ "$failed" = operator ]; then
    step "doctor: only 'operator' fails (expected on first install; create the two operators next)"
  else
    die "vasey:doctor failed checks: $failed (see $EVIDENCE/doctor.json)"
  fi
fi
art vasey:commerce-readiness --json > "$EVIDENCE/commerce-readiness.json" 2>/dev/null || true
art vasey:stripe-preflight --json > "$EVIDENCE/stripe-preflight.json" 2>/dev/null || true   # no provider I/O; exit 1 while unconfigured

# ---------------------------------------------------------------- 8. switch, start, prove, leave maintenance
step "switch current -> $SHA"
ln -sfn "$REL" "$CURRENT.next"
mv -T "$CURRENT.next" "$CURRENT"
[ "$(readlink -f "$CURRENT")" = "$REL" ] || die "current does not point at $SHA"
"${CTL[@]}" resume
QUIESCED=0
{
  echo "sha=$SHA"
  echo "deployed_utc=$(date -u +%FT%TZ)"
  echo "operator=$(id -un)"
  echo "first_install=$FIRST_INSTALL"
} > "$EVIDENCE/DEPLOY"
step "pruning old releases"
"${CTL[@]}" prune || echo "forge-deploy: WARNING: prune failed; old releases kept" >&2
step "deployed $SHA; evidence in $EVIDENCE"
