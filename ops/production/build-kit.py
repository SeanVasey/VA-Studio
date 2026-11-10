#!/usr/bin/env python3
"""Assemble the installable production kit from the reviewed staging kit and the production overlay.

    python3 -I ops/production/build-kit.py OUTDIR            write the kit into a new directory (0700)
    python3 -I ops/production/build-kit.py --delta           print the semantic delta against the staging kit
    python3 -I ops/production/build-kit.py --check           build into a scratch directory and discard it

Why derive instead of copy. The staging kit (ops/staging/, reviewed in PR #63 and since) holds about 1,900 lines of
root-level provisioning, release sealing, deploy and backup code. Production needs the same code under production names,
plus a handful of real differences. A committed copy would have to be re-reviewed line by line and would drift. This tool
makes each derived file the staging file with (1) mechanical renames and (2) the explicit, counted patches listed below.
Every patch states how many times its anchor must match; any other count stops the build, so a change to the staging kit
that touches an anchor forces a conscious re-review here and never silently produces a different production script.
The review surface is the delta (`--delta`): the staging file after renames versus the production file, nothing else.

What is derived, copied or written for production:
    derived  provision.sh, forge-deploy.sh, release-step.sh, backup.sh, scheduler-watch.sh, bin/vasey-production-ctl,
             php-fpm/vasey-production.conf, workers/vasey-production-workers.conf, and the three nginx files: the site body
             nginx/vasey-production.conf, its http-level helpers nginx/vasey-production-http.conf and the whole-file wrapper
             nginx/vasey-production-classic.conf
    copied   seal-release.py, normalize-mysql-dump.py and tls/origin-ca.pem (byte for byte), php-fpm/vasey-paid-delivery.conf (the paid-delivery
             pool is generic and identical), validate-runtime.php (a production file in this directory)
    not shipped  anything under ops/staging/test-commerce/ and the basic-auth files: production has neither.
Nothing here installs anything: the output is a directory of files, and provision.sh (run by an operator, as root, on a
host) is what installs them.
"""
import argparse
import difflib
import hashlib
import os
import re
import shutil
import stat
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path

HERE = Path(__file__).resolve().parent
DEFAULT_STAGING = HERE.parent / "staging"


class BuildError(Exception):
    pass


# --- mechanical renames, applied in order after the semantic patches ------------------------------------------------------
RENAMES = [
    ("docs/ops/staging-runbook.md", "ops/production/README.md"),  # the production README carries the rollback section
    ("VASEY_STAGING_", "VASEY_PRODUCTION_"),
    ("vasey-staging", "vasey-production"),
    ("vasey_staging", "vasey_production"),
    ("staging.conf", "production.conf"),
    ("__STAGING_HOST__", "__PRODUCTION_HOST__"),
    ("ops/staging/", "ops/production/"),
]

# After renames and patches none of these may remain in a derived file: each names something staging-only (names, basic
# auth, the probe credentials). A staging change that introduces one must come with a patch here.
FORBIDDEN = [
    "vasey-staging", "VASEY_STAGING", "vasey_staging", "staging.conf", "__STAGING_HOST__", "ops/staging",
    "netrc", "NETRC", "htpasswd", "HTPASSWD", "auth_basic", "BASIC_USER", "basic-auth-user",
]


@dataclass
class Replace:
    """Replace `old` with `new`; `old` must occur exactly `count` times in the staging text."""
    old: str
    new: str
    why: str
    count: int = 1


@dataclass
class ReplaceBetween:
    """Replace from `start` (inclusive) up to `end` (exclusive); each marker must occur exactly once, start before end.

    `removed_sha256` pins the exact staging text being dropped, so a staging addition inside the region (a new location,
    a deny rule) stops the build instead of silently vanishing from production."""
    start: str
    end: str
    new: str
    why: str
    removed_sha256: str


@dataclass
class Regex:
    pattern: str
    new: str
    why: str
    count: int = 1


def _apply(name, text, patch):
    if isinstance(patch, Replace):
        found = text.count(patch.old)
        if found != patch.count:
            raise BuildError(f"{name}: patch '{patch.why}' matched {found} time(s), expected {patch.count}; "
                             "the staging file changed under it: re-review this patch")
        return text.replace(patch.old, patch.new)
    if isinstance(patch, ReplaceBetween):
        for marker, label in ((patch.start, "start"), (patch.end, "end")):
            found = text.count(marker)
            if found != 1:
                raise BuildError(f"{name}: patch '{patch.why}' {label} marker matched {found} time(s), expected 1; "
                                 "the staging file changed under it: re-review this patch")
        begin, finish = text.index(patch.start), text.index(patch.end)
        if begin >= finish:
            raise BuildError(f"{name}: patch '{patch.why}' markers are out of order")
        removed = hashlib.sha256(text[begin:finish].encode()).hexdigest()
        if removed != patch.removed_sha256:
            raise BuildError(f"{name}: patch '{patch.why}' would remove different staging text (sha256 {removed}); "
                             "the staging file changed under it: re-review this patch")
        return text[:begin] + patch.new + text[finish:]
    found = len(re.findall(patch.pattern, text, re.M))
    if found != patch.count:
        raise BuildError(f"{name}: patch '{patch.why}' matched {found} time(s), expected {patch.count}; "
                         "the staging file changed under it: re-review this patch")
    return re.sub(patch.pattern, patch.new.replace("\\", "\\\\"), text, flags=re.M)


def rename(text):
    for old, new in RENAMES:
        text = text.replace(old, new)
    return text


def banner(comment, source):
    return (f"{comment} DERIVED by ops/production/build-kit.py from ops/staging/{source}: the same file with mechanical renames and the\n"
            f"{comment} patches listed there (`build-kit.py --delta` prints them). Comments and messages that still say \"staging\" describe\n"
            f"{comment} the reviewed source kit. Do not edit this copy: change the source or the patch, then rebuild.\n")


@dataclass
class Derived:
    dst: str
    src: str
    mode: int
    comment: str
    patches: list


@dataclass
class Copied:
    dst: str
    src: str  # relative to the staging dir, or "production:<path>" relative to this directory
    mode: int


PROVISION = [
    Replace("# VASEY.AUDIO private test-mode staging: one-time host provisioning for what Laravel Forge does not do.\n",
            "# VASEY.AUDIO production: one-time host provisioning for what Laravel Forge does not do (no basic auth, no test commerce).\n",
            "title"),
    Replace('HOST="" APP_USER=forge ROOT=/srv/vasey-staging MIRROR="" DB_NAME=vasey_staging MYSQL_ADMIN_DEFAULTS="" APP_ENV_EXPECTED=staging\n'
            'BASIC_USER="" SKIP_PACKAGES=0\n',
            'HOST="" APP_USER=forge ROOT=/srv/vasey-staging MIRROR="" DB_NAME=vasey_staging MYSQL_ADMIN_DEFAULTS="" APP_ENV_EXPECTED=production\n'
            'SKIP_PACKAGES=0\n',
            "the deploy insists on APP_ENV=production; no basic-auth user"),
    Replace("#   --app-env NAME            APP_ENV the deploy insists on (default: staging; local is the earlier interim profile)\n",
            "#   --app-env NAME            APP_ENV the deploy insists on (production is the only value)\n", "usage comment"),
    Replace("#   --basic-auth-user NAME    staging basic-auth user; the password is read from the terminal (never an argument)\n", "",
            "no basic-auth option in the usage comment"),
    Replace("    --basic-auth-user) BASIC_USER=${2:?}; shift 2 ;;\n", "", "no basic-auth option"),
    Replace('[[ "$APP_ENV_EXPECTED" =~ ^(local|staging)$ ]] || die "--app-env must be local or staging"',
            '[ "$APP_ENV_EXPECTED" = production ] || die "--app-env must be production"',
            "only the production environment"),
    Replace("VASEY_PROBE_NETRC=/etc/vasey-staging/probe.netrc\n", "", "no probe credentials: production has no basic auth"),
    ReplaceBetween("# ---------------------------------------------------------------- 7. access credentials and nginx template\n",
                   'render "$KIT/nginx/vasey-staging.conf" /etc/vasey-staging/nginx-site.conf 0644\n',
                   "# ---------------------------------------------------------------- 7. nginx template (no basic auth, no probe credentials)\n",
                   "no htpasswd or netrc", removed_sha256="82f8b71e143dbf7b98c5948df79aeb9219c228c3f7942dd83ecca46a7f661d2d"),
    Replace("workers/vasey-staging-workers.conf bin/vasey-staging-ctl backup.sh scheduler-watch.sh normalize-mysql-dump.py seal-release.py validate-runtime.php release-step.sh; do",
            "workers/vasey-staging-workers.conf bin/vasey-staging-ctl backup.sh scheduler-watch.sh normalize-mysql-dump.py seal-release.py validate-runtime.php release-step.sh forge-deploy.sh; do",
            "the kit includes the deploy script"),
    Replace('install -m 0644 -o root -g root "$KIT/release-step.sh" /usr/local/libexec/vasey-staging/release-step.sh\n',
            'install -m 0644 -o root -g root "$KIT/release-step.sh" /usr/local/libexec/vasey-staging/release-step.sh\n'
            'install -m 0755 -o root -g root "$KIT/forge-deploy.sh" /usr/local/libexec/vasey-staging/forge-deploy.sh\n',
            "install the derived deploy script: Forge runs it from a root-owned path, not from the checkout"),
    Replace(", $HTPASSWD, /etc/vasey-staging/probe.netrc\n", "\n", "summary lists no basic-auth files"),
    Replace("nginx_dupes=$(grep -rlE --exclude-dir=conf.d -e 'zone=vasey_staging_(general|webhook):' \\\n"
            "  -e '^[[:space:]]*map[[:space:]]+[^[:space:]]+[[:space:]]+\\$vasey_staging_(referrer_policy|robots|frame_options)\\b' /etc/nginx 2>/dev/null || true)\n"
            '[ -z "$nginx_dupes" ] || die "these nginx files already define the staging zones or maps: $(echo $nginx_dupes); that is the older\n'
            '  whole-file site template. Put the interim or Forge default site config back (README step 7), then re-run"\n',
            "nginx_dupes=$(grep -rlE --exclude-dir=conf.d -e 'zone=vasey_staging_general:' \\\n"
            "  -e '^[[:space:]]*map[[:space:]]+[^[:space:]]+[[:space:]]+\\$vasey_staging_(referrer_policy|frame_options|logged_uri)\\b' \\\n"
            "  -e '^[[:space:]]*log_format[[:space:]]+vasey_staging\\b' /etc/nginx 2>/dev/null || true)\n"
            '[ -z "$nginx_dupes" ] || die "these nginx files already define the production zone, maps or log format: $(echo $nginx_dupes); that\n'
            '  is the older whole-file site template. Put Forge\'s default site config back (README step 7), then re-run"\n',
            "production's http-level names: one zone (no webhook), no robots map, plus the access-log mask map and log format"),
    Replace("Next: ops/staging/README.md step 8 onwards.", 'Next: ops/production/README.md, "Provisioning, step by step", step 8 onwards.', "next-steps pointer"),
]

FORGE_DEPLOY = [
    Replace("# VASEY.AUDIO private test-mode staging: release and activation, run by Forge's \"Deploy Now\" as the site user.\n",
            "# VASEY.AUDIO production: release and activation, run by Forge's \"Deploy Now\" as the site user.\n", "title"),
    Replace('#   bash ops/staging/forge-deploy.sh "$(git rev-parse HEAD)"\n',
            '#   bash /usr/local/libexec/vasey-staging/forge-deploy.sh "$(git rev-parse HEAD)"\n',
            "production runs the root-installed copy, not a script from the checkout"),
    Replace('die ".env: $1 must be $2 for the staging profile"', 'die ".env: $1 must be $2 for the production profile"', "message"),
    Replace("need MAIL_MAILER log\nneed STRIPE_MODE test\n",
            "need LOG_CHANNEL stderr            # the journal collects stderr; the reviewed preflight profile requires it\n",
            "MAIL_MAILER and STRIPE_MODE are not forced here; the validator (the reviewed preflight profile) is the authority"),
    Replace("! grep -Eq '(sk|rk|pk)_live_' \"$RUNTIME_ENV\" || die \".env contains a live Stripe key; staging is test mode only\"\n"
            "! grep -Eq '^PRODUCTION_CHECKOUT_[A-Z_]*ENABLED=(true|1)' \"$RUNTIME_ENV\" || die \".env enables production checkout; staging must not\"\n",
            "# Live Stripe keys and PRODUCTION_CHECKOUT_* switches are not refused here: validate-runtime.php holds them, once, for every path.\n",
            "the production holds live in the validator only"),
    ReplaceBetween("# Fail closed on ambiguous encodings during the cheap pre-build check.",
                   'step "runtime .env passes the staging profile checks',
                   "", "the checkout-switch loop moves into the validator", removed_sha256="d35f6f1a053e2a7bc768965fd891d3f05d92cb388b37d3bccfd09642e11011dd"),
    Replace('step "runtime .env passes the staging profile checks (APP_ENV=$VASEY_EXPECTED_APP_ENV, APP_DEBUG=false)"',
            'step "runtime .env passes the production profile checks (APP_ENV=$VASEY_EXPECTED_APP_ENV, APP_DEBUG=false)"', "message"),
]

CTL = [
    Replace(' "${VASEY_PROBE_NETRC:?}"', "", "no probe credentials required"),
    Replace("# nginx with the staging hostname for SNI and the certificate check, and the staging basic-auth\n"
            "# credentials from a 0600 netrc. Maintenance proof needs the exact code: a failed request is not a 503.\n",
            "# nginx with the production hostname for SNI and the certificate check, and no credentials (the site has no basic\n"
            "# auth). Maintenance proof needs the exact code: a failed request is not a 503.\n",
            "probe comment"),
    Replace("--max-time 20 --netrc-file \"$VASEY_PROBE_NETRC\" \\\n", "--max-time 20 \\\n", "probe without basic-auth credentials"),
]

# The site body. Everything the paid-delivery and upload contract and the root strategy rely on stays the staging text.
NGINX_BODY = [
    Replace("# VASEY.AUDIO private test-mode staging: nginx site body for a Forge-managed server (the inside of the site's server block).\n",
            "# VASEY.AUDIO production: nginx site body for a Forge-managed server (the inside of the site's server block). A PREPARATION\n"
            "# TEMPLATE: nothing is deployed, no DNS record exists for it and no production host has been chosen (U-02 in\n"
            "# docs/architecture/decision-register.md).\n",
            "title"),
    Replace("(tests/ops/test_staging_nginx_site.py)", "(tests/ops/test_production_nginx_site.py)", "the production test guards it"),
    Replace("# Access model: HTTP basic auth on everything. The one exception is exactly `POST /webhooks/stripe`,\n"
            "# which the application authenticates by Stripe signature (VerifyStripeWebhook). Nothing else is\n"
            "# reachable without the staging credentials.\n",
            "# Access model: public. What differs from the staging body this file is derived from, and nothing else:\n"
            "#   - no HTTP basic auth and no robots/noindex headers: the site is public and the application decides indexing;\n"
            "#   - no unauthenticated /webhooks/stripe location: there is no production webhook receiver (VerifyStripeWebhook refuses\n"
            "#     production), so a live one is a later reviewed change that must bring its own limits (1 MiB body, 60 s, POST only);\n"
            "#   - the error log goes to stderr (the journal collects it); the access log is a file, rotated by provision.sh's\n"
            "#     logrotate, in the `vasey_production` format, which masks the UUID in the two paid-delivery paths. nginx's error log\n"
            "#     still quotes the request line when an upstream fails (observed), so treat the journal as sensitive: the identifiers\n"
            "#     are short-lived authorizations, not secrets at rest.\n"
            "# Everything that carries the paid-delivery and upload contract is identical to staging.\n"
            "#\n"
            "# Private storage is never reachable from here: every root is current/public, there is no alias or storage location,\n"
            "# private files stream through controllers (public/ holds only the Vite build), dotfiles answer 404 and no PHP file but\n"
            "# index.php executes.\n"
            "#\n"
            "# Behind a CDN or proxy: do NOT put the paid-delivery paths behind a proxy whose read timeout is shorter than 3,900 s (see\n"
            "# docs/ops/monitoring-and-host-baseline.md, \"CDN and proxy\"). Trusting forwarded addresses also needs a reviewed\n"
            "# TRUSTED_PROXIES address list (the application trusts no proxy by default); the illustration is commented out in\n"
            "# nginx/vasey-staging-http.conf.\n",
            "public site: the production access model, storage and proxy notes"),
    Replace("#   - Stripe webhook: 1 MiB (VerifyStripeWebhook::MAX_BODY_BYTES).\n", "", "no webhook location"),
    Replace("ssl_session_cache shared:VaseyStagingSSL:10m;", "ssl_session_cache shared:VaseyProductionSSL:10m;", "own TLS session cache name"),
    Replace("# Private staging: every request needs the staging credentials unless a location below says otherwise.\n"
            'auth_basic "VASEY.AUDIO staging";\n'
            "auth_basic_user_file /etc/nginx/vasey-staging.htpasswd;\n\n", "", "no basic auth"),
    Replace("(including test deliveries of private masters)", "(including paid deliveries of private masters)", "comment"),
    Replace("add_header X-Robots-Tag $vasey_staging_robots always;\n", "", "no robots header: the application decides indexing"),
    ReplaceBetween("# ---- Stripe test webhook: the only unauthenticated entry point ----\n",
                   "# ---- Paid-delivery long routes", "", "no webhook location: production has no webhook receiver",
                   removed_sha256="8d23d9295789f809c571f773fca436c2d5acfeae5b1146f50c0cc5122864f224"),
    Replace('    add_header X-Robots-Tag "noindex, nofollow, noarchive" always;\n', "", "no robots header on the build output either"),
    Replace("access_log /var/log/nginx/vasey-staging-access.log;\n"
            "error_log  /var/log/nginx/vasey-staging-error.log error;\n",
            "access_log /var/log/nginx/vasey-staging-access.log vasey_staging;\n"
            "error_log  stderr error;\n",
            "masked access-log format; errors to the journal"),
]

# The http-level helpers: one zone, two header maps, plus the paid-path log mask and the commented trusted-proxy illustration.
NGINX_HTTP = [
    Replace("# VASEY.AUDIO private test-mode staging: http-level nginx helpers for the site in nginx/vasey-staging.conf.\n",
            "# VASEY.AUDIO production: http-level nginx helpers for the site in nginx/vasey-staging.conf (a preparation template).\n",
            "title"),
    Replace("# Every name below is used by nginx/vasey-staging.conf and must be defined exactly once per nginx instance: a duplicate\n"
            "# zone or map variable makes `nginx -t` fail. Do not paste these lines into the site as well. The production kit uses\n"
            "# vasey_production_* names, so both kits can share one host.\n",
            "# Every name below is used by nginx/vasey-staging.conf and must be defined exactly once per nginx instance: a second\n"
            "# zone or log format of the same name makes `nginx -t` fail, and a second map of the same variable silently replaces\n"
            "# the first. Do not paste these lines into the site as well. The staging kit uses its own names, so both kits can\n"
            "# share one host.\n",
            "the production names and what a duplicate does"),
    Replace("# Requests per client address. The webhook zone is the only one an unauthenticated client can reach.\n"
            "limit_req_zone $binary_remote_addr zone=vasey_staging_general:10m rate=30r/s;\n"
            "limit_req_zone $binary_remote_addr zone=vasey_staging_webhook:1m rate=10r/s;\n",
            "# Requests per client address.\n"
            "limit_req_zone $binary_remote_addr zone=vasey_staging_general:10m rate=30r/s;\n",
            "no webhook zone"),
    Replace('map $upstream_http_x_robots_tag $vasey_staging_robots {\n'
            '    ""      "noindex, nofollow, noarchive";\n'
            '    default "";\n'
            '}\n', "", "no robots map"),
    Replace('    ~^/embed/ "";\n'
            '    default   "SAMEORIGIN";\n'
            '}\n',
            '    ~^/embed/ "";\n'
            '    default   "SAMEORIGIN";\n'
            '}\n'
            '\n'
            '# The authorization and origin identifiers in the paid-delivery paths are capabilities: keep them out of the access log\n'
            '# (query strings on those two routes are dropped too).\n'
            'map $request_uri $vasey_staging_logged_uri {\n'
            r'    "~^(?<head>/paid-grants/(?:authorizations|origins)/)[0-9a-f-]{36}(?<tail>/(?:redeem|document))(?:\?.*)?$" "${head}:uuid${tail}";' '\n'
            '    default $request_uri;\n'
            '}\n'
            "log_format vasey_staging '$remote_addr [$time_iso8601] \"$request_method $vasey_staging_logged_uri\" $status $body_bytes_sent '\n"
            "                            'rt=$request_time \"$http_user_agent\"';\n"
            '\n'
            '# Trusted-proxy handling is OFF. If, after the reviewed application change and a reviewed address list, the site sits\n'
            '# behind a proxy that you control, nginx can restore the client address for rate limiting like this (illustration only):\n'
            '#   set_real_ip_from <reviewed proxy address or range>;\n'
            '#   real_ip_header CF-Connecting-IP;      # or X-Forwarded-For with real_ip_recursive on\n'
            '# Do not enable it with an unreviewed or unbounded list: any client could then choose its own address.\n',
            "the masked access-log format and the commented trusted-proxy illustration"),
]

# The whole-file wrapper: the same server block; only comments mention basic auth.
NGINX_CLASSIC = [
    Replace("# VASEY.AUDIO private test-mode staging: whole-file nginx site", "# VASEY.AUDIO production (preparation template): whole-file nginx site",
            "title"),
    Replace("# Encrypt HTTP-01 challenge handling, so renewals keep working without basic auth.\n",
            "# Encrypt HTTP-01 challenge handling.\n", "no basic auth"),
    Replace("# Everything else (basic auth, limits, headers, locations,", "# Everything else (limits, headers, locations,", "no basic auth"),
]

WORKERS = [
    Regex(r"^stdout_logfile=/var/log/vasey-staging/[a-z-]+\.log\nstdout_logfile_maxbytes=[0-9]+MB\nstdout_logfile_backups=[0-9]+\n",
          "stdout_logfile=NONE\nstdout_syslog=true\n",
          "workers and the scheduler log through supervisor's syslog handler (the journal), not to rotated files", count=5),
]

DERIVED = [
    Derived("provision.sh", "provision.sh", 0o755, "#", PROVISION),
    Derived("forge-deploy.sh", "forge-deploy.sh", 0o755, "#", FORGE_DEPLOY),
    Derived("release-step.sh", "release-step.sh", 0o644, "#", []),
    Derived("backup.sh", "backup.sh", 0o755, "#", []),
    Derived("scheduler-watch.sh", "scheduler-watch.sh", 0o755, "#", []),
    Derived("bin/vasey-production-ctl", "bin/vasey-staging-ctl", 0o755, "#", CTL),
    Derived("php-fpm/vasey-production.conf", "php-fpm/vasey-staging.conf", 0o644, ";", []),
    Derived("workers/vasey-production-workers.conf", "workers/vasey-staging-workers.conf", 0o644, ";", WORKERS),
    Derived("nginx/vasey-production.conf", "nginx/vasey-staging.conf", 0o644, "#", NGINX_BODY),
    Derived("nginx/vasey-production-http.conf", "nginx/vasey-staging-http.conf", 0o644, "#", NGINX_HTTP),
    Derived("nginx/vasey-production-classic.conf", "nginx/vasey-staging-classic.conf", 0o644, "#", NGINX_CLASSIC),
]

COPIED = [
    Copied("seal-release.py", "seal-release.py", 0o644),
    Copied("normalize-mysql-dump.py", "normalize-mysql-dump.py", 0o644),
    Copied("tls/origin-ca.pem", "tls/origin-ca.pem", 0o644),
    Copied("php-fpm/vasey-paid-delivery.conf", "php-fpm/vasey-paid-delivery.conf", 0o644),
    Copied("validate-runtime.php", "production:validate-runtime.php", 0o644),
]


def read(path):
    try:
        return path.read_text()
    except OSError as error:
        raise BuildError(f"cannot read {path}: {error.strerror}") from None


def derive(entry, staging, with_banner=True):
    name = entry.src
    text = read(staging / entry.src)
    for patch in entry.patches:
        text = _apply(name, text, patch)
    text = rename(text)
    for token in FORBIDDEN:
        if token in text:
            raise BuildError(f"{name}: '{token}' remains after the renames and patches; add a patch for it")
    if with_banner:
        first, _, rest = text.partition("\n")
        mark = banner(entry.comment, entry.src)
        text = (first + "\n" + mark + rest) if first.startswith("#!") else (mark + text)
    return text


def copied_bytes(entry, staging, production):
    if entry.src.startswith("production:"):
        path = production / entry.src.split(":", 1)[1]
    else:
        path = staging / entry.src
    try:
        return path.read_bytes()
    except OSError as error:
        raise BuildError(f"cannot read {path}: {error.strerror}") from None


def build(outdir, staging=DEFAULT_STAGING, production=HERE):
    """Write the kit into OUTDIR (which must not exist or must be empty). Returns {relative path: sha256}."""
    outdir = Path(outdir)
    if outdir.is_symlink():
        raise BuildError("the output directory must not be a symlink")
    if outdir.exists() and any(outdir.iterdir()):
        raise BuildError("the output directory is not empty")
    # Build everything in memory first, so a refused patch leaves no half-written kit behind.
    files = {}
    for entry in DERIVED:
        files[entry.dst] = (derive(entry, staging).encode(), entry.mode)
    for entry in COPIED:
        files[entry.dst] = (copied_bytes(entry, staging, production), entry.mode)
    manifest = "".join(f"{hashlib.sha256(body).hexdigest()}  {name}\n" for name, (body, _) in sorted(files.items()))
    files["MANIFEST.sha256"] = (manifest.encode(), 0o644)
    outdir.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(outdir, 0o700)
    for name, (body, mode) in files.items():
        target = outdir / name
        target.parent.mkdir(parents=True, exist_ok=True)
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW
        descriptor = os.open(target, flags, 0o600)
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(body)
        os.chmod(target, stat.S_IMODE(mode))
    return {name: hashlib.sha256(body).hexdigest() for name, (body, _) in files.items()}


def delta(staging=DEFAULT_STAGING):
    """Unified diff of each derived file: the staging source after renames, against the production file (banner omitted)."""
    chunks = []
    for entry in DERIVED:
        before = rename(read(staging / entry.src))
        after = derive(entry, staging, with_banner=False)
        chunks.append("".join(difflib.unified_diff(before.splitlines(True), after.splitlines(True),
                                                   f"staging/{entry.src} (renamed)", f"production/{entry.dst}", n=2)))
    return "".join(chunks)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("outdir", nargs="?")
    parser.add_argument("--delta", action="store_true", help="print the semantic delta and exit")
    parser.add_argument("--check", action="store_true", help="build into a scratch directory and discard it")
    parser.add_argument("--staging-dir", default=str(DEFAULT_STAGING))
    parser.add_argument("--production-dir", default=str(HERE))
    args = parser.parse_args(argv)
    staging, production = Path(args.staging_dir), Path(args.production_dir)
    try:
        if args.delta:
            sys.stdout.write(delta(staging))
        elif args.check:
            with tempfile.TemporaryDirectory(prefix="vasey-production-kit-") as scratch:
                hashes = build(Path(scratch) / "kit", staging, production)
            print(f"build-kit: ok, {len(hashes)} files")
        elif args.outdir:
            hashes = build(args.outdir, staging, production)
            print(f"build-kit: wrote {len(hashes)} files to {args.outdir}")
        else:
            parser.error("give OUTDIR, --delta or --check")
    except BuildError as error:
        print(f"build-kit: refused: {error}", file=sys.stderr)
        return 3
    return 0


if __name__ == "__main__":
    sys.exit(main())
