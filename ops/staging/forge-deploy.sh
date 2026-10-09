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
#   1. frozen environment checked; controlled services/writers quiesced before a candidate is allocated;
#      then a fresh checkout of the exact SHA in <root>/releases/<SHA>, proven clean;
#   2. composer install --no-dev from composer.lock, npm ci && npm run build, Vite manifest present;
#   3. persistent private storage attached by bind mount (never a symlink), proven by inode;
#   4. the Forge .env validated for the staging profile and installed 0600;
#   5. one root lease encloses build/sealing; activation freshly quiesces under its own retained lease;
#   6. a backup of the database, private storage and .env, and an isolated restore proof, before migrating;
#   7. maintenance in the new release, migrate --pretend, migrate --force, config/route/view/event caches,
#      vasey:doctor, redacted readiness reports;
#   8. atomic switch of `current`, PHP-FPM and workers started on it and proven, maintenance lifted, GET / = 200.
# No storage:link: nothing uses the public disk (previews, artwork and site images stream through controllers).
# No separate queue:restart: the quiesce signals it and then stops every worker; they start on the new release.
#
# A failed quiesce or partial resume leaves service/writer state unconfirmed. Inspect ctl status and
# establish quiesce before recovery in docs/ops/staging-runbook.md "Rollback". Privileged steps use ctl.
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
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
EVIDENCE=$ROOT/evidence/$STAMP-${SHA:0:12}
ACTIVATION_STARTED=0
ENV_SNAPSHOT=''

# Composer's artisan hooks and every cache/migration command must use the candidate .env, not
# application settings inherited from Forge's deployment shell. Preserve ordinary OS/tool state.
while IFS= read -r name; do
  case "$name" in
    APP_*|DB_*|STRIPE_*|PRODUCTION_*|VASEY_TEST_*|VASEY_OPERATIVE_*|SESSION_*|CACHE_*|QUEUE_*|MAIL_*|FILESYSTEM_DISK|MEDIA_*|CONTACT_*|REDIS_*|AWS_*|BROADCAST_*) unset "$name" ;;
  esac
done < <(compgen -e)

runtime_gate() {
  local release=$1 env_file=$2 previous=''
  local args=("$env_file" "$VASEY_EXPECTED_APP_ENV" "$VASEY_STAGING_HOST" "$VASEY_DB_NAME")
  if [ -L "$CURRENT" ]; then
    previous=$(readlink -f "$CURRENT")
    args+=("$previous/.env")
  fi
  env -i PATH="$PATH" LC_ALL=C "$PHP" "$release/ops/staging/validate-runtime.php" "${args[@]}" \
    || die "runtime profile refused"
}

freeze_runtime_environment() {
  # Forge may edit its mirror .env while a build or snapshot runs. Admit and install one private copy.
  ENV_SNAPSHOT=$(mktemp "$ROOT/evidence/.candidate-env.XXXXXXXX") || die "cannot stage candidate environment"
  cat -- "$RUNTIME_ENV" > "$ENV_SNAPSHOT" || die "cannot stage candidate environment"
  chmod 0600 "$ENV_SNAPSHOT"
  RUNTIME_ENV=$ENV_SNAPSHOT
}

exec 9>>"$ROOT/.deploy.lock"
flock -n 9 || die "another deploy or the nightly backup is running"

on_exit() {
  local code=$?
  [ -z "$ENV_SNAPSHOT" ] || rm -f -- "$ENV_SNAPSHOT"
  [ "$code" = 0 ] && return 0
  if [ "$ACTIVATION_STARTED" = 1 ]; then
    echo "forge-deploy: FAILED (exit $code) during an activation/configuration attempt; service and writer state is unconfirmed." >&2
    echo "forge-deploy: inspect ctl status and establish quiesce before recovery: docs/ops/staging-runbook.md#rollback" >&2
  else
    echo "forge-deploy: FAILED (exit $code) before the quiesce: the served release was not touched." >&2
  fi
}
trap on_exit EXIT

# ---------------------------------------------------------------- 0. runtime .env checks (values never printed)
[ -f "$RUNTIME_ENV" ] && [ ! -L "$RUNTIME_ENV" ] || die "Forge .env missing at $RUNTIME_ENV (Forge > Site > Environment)"
chmod 0600 "$RUNTIME_ENV"
freeze_runtime_environment
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
# Fail closed on ambiguous encodings during the cheap pre-build check. The real dotenv/configuration
# gate validates the protected current release before quiesce and the built candidate before attachment.
while IFS= read -r flag; do
  case "$(envget "$flag")" in false|'(false)'|'') ;; *) die ".env production checkout flags must be false or unset" ;; esac
done < <(sed -n 's/^\(PRODUCTION_CHECKOUT_[A-Z_]*ENABLED\)=.*/\1/p' "$RUNTIME_ENV")
step "runtime .env passes the staging profile checks (APP_ENV=$VASEY_EXPECTED_APP_ENV, APP_DEBUG=false)"

# ---------------------------------------------------------------- 1. fresh immutable checkout
if [ -e "$REL" ]; then
  if [ "$(readlink -f "$CURRENT" 2>/dev/null || true)" = "$REL" ]; then
    if cmp -s "$RUNTIME_ENV" "$REL/.env"; then
      "${CTL[@]}" healthy "$SHA" || die "served SHA is not healthy; inspect ctl status and recover explicitly"
      step "$SHA is already the served release and its .env is unchanged; nothing to do"; exit 0
    fi
    # Environment-only change (for example the seller tag or Lane B's test-commerce values): the release stays
    # immutable, its .env and cached configuration are replaced while every writer is stopped.
    step "$SHA is the served release; the Forge .env changed: refreshing configuration"
    runtime_gate "$REL" "$RUNTIME_ENV"
    [ "$(envget APP_KEY)" = "$(grep '^APP_KEY=' "$REL/.env" | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/")" ] \
      || die "APP_KEY changes require a separately reviewed key-custody/rotation procedure"
    ACTIVATION_STARTED=1
    "${CTL[@]}" refresh "$SHA" "$RUNTIME_ENV"
    cmp -s "$RUNTIME_ENV" "$REL/.env" || die ".env not installed"
    step "configuration refreshed on $SHA"; exit 0
  fi
  die "release $SHA already exists but is not current; to return to it follow the runbook's rollback, never rebuild in place"
fi
FIRST_INSTALL=1
if [ -L "$CURRENT" ]; then
  previous_release=$(readlink -f -- "$CURRENT") || die "served release is invalid"
  previous_sha=$(basename -- "$previous_release")
  [[ "$previous_sha" =~ ^[0-9a-f]{40}$ ]] && [ "$previous_release" = "$RELEASES/$previous_sha" ] \
    && [ -d "$previous_release" ] || die "served release is not canonical"
  # Validate with the already protected release before downtime; repeat on the built candidate below.
  runtime_gate "$previous_release" "$RUNTIME_ENV"
  FIRST_INSTALL=0
fi
step "prepare under one root-held build lease (quiesce, allocate, build, attach and seal)"
ACTIVATION_STARTED=1
install -d -m 0750 "$ROOT/evidence/$STAMP-${SHA:0:12}"
"${CTL[@]}" prepare "$SHA" "$RUNTIME_ENV" "$EVIDENCE"
[ "$(stat -c %d:%i "$REL/storage/app/private")" = "$(stat -c %d:%i "$ROOT/private")" ] && [ ! -L "$REL/storage/app/private" ] \
  || die "private storage not attached"
# Recheck tracked source after protection, not only before the app-owned build window.
SEALED_GIT=(env -i PATH=/usr/bin:/bin LC_ALL=C GIT_NO_REPLACE_OBJECTS=1 GIT_CONFIG_GLOBAL=/dev/null
  git -c safe.directory="$REL" -c core.fsmonitor=false --git-dir="$REL/.git" --work-tree="$REL")
[ "$("${SEALED_GIT[@]}" rev-parse HEAD)" = "$SHA" ] || die "sealed checkout is not the requested SHA"
"${SEALED_GIT[@]}" diff --quiet --no-ext-diff --no-textconv HEAD -- || die "tracked source changed before sealing"

# ---------------------------------------------------------------- 5-6. activation holds one root lease across fresh quiesce and snapshot
step "activate under one root-held lease (fresh quiesce, snapshot/proof, migrate/cache, switch, healthy resume)"
"${CTL[@]}" activate "$SHA" "$EVIDENCE"
[ "$(readlink -f "$CURRENT")" = "$REL" ] || die "current does not point at $SHA"
{
  echo "sha=$SHA"
  echo "deployed_utc=$(date -u +%FT%TZ)"
  echo "operator=$(id -un)"
  echo "first_install=$FIRST_INSTALL"
} > "$EVIDENCE/DEPLOY"
step "pruning old releases"
"${CTL[@]}" prune || echo "forge-deploy: WARNING: prune failed; old releases kept" >&2
step "deployed $SHA; evidence in $EVIDENCE"
