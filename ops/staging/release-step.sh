#!/usr/bin/env bash
# shellcheck disable=SC2015  # Compound predicates intentionally refuse when either requirement fails.
# Fixed unprivileged steps, invoked by ctl while the root operation lease remains held.
# build, admit and activate run as the application account; build-isolated runs as the dedicated build account
# (VASEY_BUILD_USER), which never sees the application's environment, evidence or private storage.
set -euo pipefail
umask 022
export LC_ALL=C
CONF=/etc/vasey-staging/staging.conf
# shellcheck source=/dev/null
. "$CONF"
: "${VASEY_ROOT:?}" "${VASEY_MIRROR:?}" "${VASEY_PHP:?}" "${VASEY_APP_USER:?}"
die() { echo "release-step: $*" >&2; exit 1; }
step() { echo "release-step: $*"; }
MODE=${1:-} SHA=${2:-} EVIDENCE=${3:-}
if [ "$MODE" = build-isolated ]; then
  [ -n "${VASEY_BUILD_USER:-}" ] && [ "$VASEY_BUILD_USER" != "$VASEY_APP_USER" ] && [ "$(id -un)" = "$VASEY_BUILD_USER" ] \
    || die "wrong build identity"
else
  [ "$(id -un)" = "$VASEY_APP_USER" ] || die "wrong application identity"
fi
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
    # Composer's Laravel hooks boot the application; admit the captured profile first.
    install -m 0600 -- "$candidate" "$REL/.env"
    # ctl runs this step under `env -i`. Composer refuses to start without HOME or COMPOSER_HOME, and npm keeps its
    # cache under HOME. Give both a fresh private home for this build only, outside every release, and remove it on
    # exit: a reused home under the app-owned tmp could carry a planted .npmrc or Composer plugin into a sealed build.
    BUILD_HOME=$(mktemp -d "$VASEY_ROOT/tmp/build-home.XXXXXXXXXX") || die "cannot create a private build home"
    trap 'rm -rf -- "$BUILD_HOME"' EXIT
    chmod 0700 -- "$BUILD_HOME"
    export HOME=$BUILD_HOME COMPOSER_HOME=$BUILD_HOME/composer npm_config_cache=$BUILD_HOME/npm
    composer_bin=$(command -v composer) || die "composer not found"
    (cd "$REL" && "$PHP" "$composer_bin" install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative)
    [ -f "$REL/vendor/autoload.php" ] && [ -f "$REL/vendor/composer/installed.json" ] || die "composer produced no vendor"
    (cd "$REL" && npm ci --no-audit --no-fund --loglevel=error && npm run build --silent)
    [ -f "$REL/public/build/manifest.json" ] || die "no Vite manifest"
    sha256sum "$REL/composer.lock" "$REL/package-lock.json" "$REL/public/build/manifest.json" | sed "s#$REL/##" > "$EVIDENCE/build.sha256"
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
    # No `migrate --pretend`: identity migrations run DDL through the raw PDO, which pretend mode cannot intercept, so a
    # "dry run" would really change the schema before the guarded migration. The verified snapshot is the safety net.
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
  build-isolated)
    # The dedicated build account, with the site up. The slot is root:<build group> 0770, so the application can neither
    # traverse nor write it; nothing here reads the environment, the evidence or the mirror. The source arrives on stdin
    # as a git bundle that the application account wrote from the mirror: git hashes every object it imports, so the
    # checkout below is the exact SHA whatever those bytes were, or the build refuses.
    [ "$#" = 2 ] || die "isolated build requires only the SHA"
    [ -d "$REL" ] && [ ! -L "$REL" ] && [ -z "$(ls -A -- "$REL")" ] || die "build slot must be an empty directory"
    BUILD_HOME=$(mktemp -d "$VASEY_ROOT/build/home.XXXXXXXXXX") || die "cannot create a private build home"
    trap 'rm -rf -- "$BUILD_HOME"' EXIT
    chmod 0700 -- "$BUILD_HOME"
    mkdir -m 0700 -- "$BUILD_HOME/tmp"
    # Every cache, configuration and temporary file of Composer, npm, Node and PHP stays in this fresh home: nothing shared
    # with the application (its tmp, /tmp, a reused cache) can feed the build. The release is root-owned, so git needs it
    # named safe; the variable form reaches Composer's own git calls too.
    export HOME=$BUILD_HOME COMPOSER_HOME=$BUILD_HOME/composer npm_config_cache=$BUILD_HOME/npm TMPDIR=$BUILD_HOME/tmp \
      XDG_CACHE_HOME=$BUILD_HOME/cache XDG_CONFIG_HOME=$BUILD_HOME/config XDG_DATA_HOME=$BUILD_HOME/data \
      GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=$REL
    cat > "$BUILD_HOME/source.bundle"
    git clone --quiet --no-checkout -- "$BUILD_HOME/source.bundle" "$REL"
    git -C "$REL" -c advice.detachedHead=false checkout --quiet --detach "$SHA"
    git -C "$REL" remote remove origin
    rm -f -- "$BUILD_HOME/source.bundle"
    [ "$(git -C "$REL" rev-parse HEAD)" = "$SHA" ] || die "checkout differs from requested SHA"
    [ -z "$(git -C "$REL" status --porcelain --untracked-files=all --ignored)" ] || die "dirty checkout"
    [ "$(git -C "$REL" rev-parse 'HEAD^{tree}')" = "$(git -C "$REL" write-tree)" ] || die "checkout tree differs"
    # No .env: Laravel's package discovery and Filament's upgrade (the Composer hooks) need no application settings, and
    # the build output does not depend on them. The protected environment is installed by root after sealing.
    composer_bin=$(command -v composer) || die "composer not found"
    (cd "$REL" && "$PHP" "$composer_bin" install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative)
    [ -f "$REL/vendor/autoload.php" ] && [ -f "$REL/vendor/composer/installed.json" ] || die "composer produced no vendor"
    (cd "$REL" && npm ci --no-audit --no-fund --loglevel=error && npm run build --silent)
    [ -f "$REL/public/build/manifest.json" ] || die "no Vite manifest"
    [ ! -e "$REL/.env" ] && [ ! -L "$REL/.env" ] || die "the isolated build must not create an environment file"
    ;;
  admit)
    # The application account, site up, on the sealed isolated build with root's protected .env installed: build evidence
    # and the real runtime admission that the legacy build runs at its end.
    [ "$#" = 3 ] || die "admit requires SHA and evidence"
    [ -f "$REL/vendor/autoload.php" ] && [ -f "$REL/vendor/composer/installed.json" ] || die "composer produced no vendor"
    [ -f "$REL/public/build/manifest.json" ] || die "no Vite manifest"
    sha256sum "$REL/composer.lock" "$REL/package-lock.json" "$REL/public/build/manifest.json" | sed "s#$REL/##" > "$EVIDENCE/build.sha256"
    args=("$REL/.env" "$VASEY_EXPECTED_APP_ENV" "$VASEY_STAGING_HOST" "$VASEY_DB_NAME")
    if [ -L "$VASEY_ROOT/current" ]; then args+=("$(readlink -f "$VASEY_ROOT/current")/.env"); fi
    env -i PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C "$PHP" "$REL/ops/staging/validate-runtime.php" "${args[@]}" \
      || die "built runtime profile refused"
    ;;
  *) die "unsupported release step" ;;
esac
