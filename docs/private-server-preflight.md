# Private-server configuration preflight

The preflight makes the [hosting preparation](private-server-readiness.md) checkable before a server topology is selected. It validates the current disabled-commerce preparation baseline and optionally inspects local runtime prerequisites. It does not deploy the application, establish effective configuration or authorize sales.

The command parses a file through the locked `vlucas/phpdotenv` dependency. It never sources that file, boots Laravel, loads cached application configuration, connects to MySQL, runs a configured executable, sends mail or modifies files. It prints fixed check IDs and statuses; it does not print configuration values, private paths, parser exceptions or secret-derived hashes. Install the locked Composer dependencies before running it.

## Validate the committed preparation template

From the selected checkout:

```sh
php scripts/ops/private-server-preflight.php --template
```

Exit 0 with `TEMPLATE_VALID` means that the template preserves the preparation controls and deliberately blank installation fields. It does **not** mean those fields have been configured. Reviewing the same blank template with `--env-file` produces `BLOCKED` and exit 1.

## Inspect a protected installation file

Run as the intended application/worker identity, supplying the absolute canonical path to the actual configuration file. The following path is illustrative; it is not an installed location:

```sh
php scripts/ops/private-server-preflight.php --env-file /srv/va-studio/config/runtime.env
php scripts/ops/private-server-preflight.php --env-file /srv/va-studio/config/runtime.env --runtime
```

The input must be a regular file with one hard link, no symlink components and no more than 128 KiB. Owner-only `0400`/`0600` and owner plus trusted-group read `0440`/`0640` are accepted. World access, group write, execute bits and special permission bits are refused before parsing. Operators remain responsible for the owner's identity, trusted group membership, parent-directory protection and access-control lists. The checker does not change permissions or resolve a deployment symlink on the operator's behalf.

Use one explicit scalar `KEY=value` assignment per line, with ordinary dotenv quoting/comments. Duplicate keys, bare inherited names, multiline values and truncated quoted lines are rejected. References resolve against preceding assignments in this file only; the invoking shell cannot supply a missing secret through interpolation. Do not put real credentials in the command line, Git or general support transcripts.

The baseline checks include:

- Production application mode with debugging disabled; MySQL, encrypted database sessions, secure/HttpOnly/SameSite cookies, private local storage and asynchronous database queues.
- Queue retry longer than the current 900-second media timeout and 960-second claim lease.
- All eight inquiry/test-commerce activation flags disabled, test Stripe mode and inactive test/provider policy fields blank. Mail remains `log` until a separately reviewed transport/workflow is ready.
- A populated HTTPS application origin, correctly shaped application key, named non-root database identity/endpoint and media executable/tag/hash settings. A named DB identity does not prove least-privilege grants, and a correctly shaped key or tag hash does not prove retained identity or approved bytes.
- No unreviewed alternate DB URL, queue/session DB connection, config-cache path or storage-path override in the inspected file.

`--runtime` additionally checks Linux, PHP 8.4, the required PHP extensions, a non-root execution identity, configured media executable availability and the checkout's canonical readable/writable `0700` private root. It only records whether the standard config-cache file exists; it never loads or clears it. Tool presence is not proof of supported versions, scanner signatures, isolation or successful processing. It does not create the private root or test writes there.

## Staging profile

A hosted test-mode rehearsal runs with `APP_ENV=staging`. Inspect its file with the staging profile:

```sh
php scripts/ops/private-server-preflight.php --env-file /srv/va-studio/config/staging.env --profile=staging
php scripts/ops/private-server-preflight.php --env-file /srv/va-studio/config/staging.env --profile=staging --runtime
```

The staging profile keeps every hosted baseline above (debugging off, MySQL, encrypted secure sessions, `STRIPE_MODE=test`, inquiries off, `log` mail) and requires `APP_ENV=staging`. It differs only where staging admits the Stripe-test commerce chain: the seven test-commerce switches may be `true` or `false` (nothing else), and the test policy and credential fields may be populated. It then requires a blank or `sk_test_`-shaped `STRIPE_TEST_SECRET_KEY`, a blank or `acct_`-shaped account and `whsec_`-shaped webhook secret, no `sk_live_`/`rk_live_` value anywhere in the file, and a blank `PRODUCTION_CHECKOUT_FUNDS_MODE`. The production profile is the default and is unchanged; `--profile=production` selects it explicitly. Every report names its `profile`.

## Interpret the result

| Result | Meaning | Next action |
| --- | --- | --- |
| `TEMPLATE_VALID`, exit 0 | Prepared template checks pass; installation values remain blank | Select and inspect the real host, then populate protected configuration |
| `FILE_CHECKS_PASSED`, exit 0 | The supplied file meets this preparation baseline, plus any requested local runtime checks | Verify effective web/worker configuration and the outstanding operational evidence |
| `BLOCKED`, exit 1 | At least one prerequisite, input or baseline check failed | Correct the named check in the intended environment; no automatic repair occurs |

Every result includes `deployment_ready: false`. This script must not be used as a production deployment or cutover authorization gate. Its fixed `unverified` list identifies the remaining boundaries: effective cached/process configuration; actual MySQL version, connection, grants and schema; HTTPS/proxy/private-file exposure; supervised workers and shared storage; scanner detection/signatures; approved tag bytes and end-to-end media; mail/scheduler delivery; backup restore/rollback; production commerce and cutover.

In particular, service-manager variables and cached Laravel configuration can override a correct file. Reconcile the selected release, web runtime and each worker's effective configuration without exposing their values. Preserve retained `APP_KEY`/reviewed previous keys, rebuild configuration through the controlled release, and restart supervised workers. This checker intentionally does not clear a live cache, generate a key, run migrations, install a service or enable a commerce flag.

## Executed development verification

On 2026-10-02, the real PHP 8.4 CLI passed all 16 isolated guard tests:

```sh
python3 scripts/ops/test-private-server-preflight.py
php -l scripts/ops/private-server-preflight.php
php vendor/bin/pint --test scripts/ops/private-server-preflight.php
git diff --check
```

The test runner uses `php` on `PATH`; `PRIVATE_PREFLIGHT_PHP` can select an explicit PHP binary. The tests cover exposed/read-only inputs, symlink/hard-link refusal, bounds, duplicate/truncated input, secret redaction, unsafe activation, retry ordering, incomplete credentials, precedence overrides, no shell evaluation or parent-environment interpolation, and runtime inspection without executing configured tools. All fixtures and any executable sentinels are synthetic and temporary.

The first guard run caught an unfinished quoted line silently omitted by the underlying dotenv parser; explicit single-line assignment validation now rejects it. It also caught reuse of a read-only test fixture; the harness now recreates that isolated file before each case. These are checker/harness corrections, not observed defects on Sean's server. No actual private server or Mac was accessed; no database, service, credential, media file, backup or deployment was created by this verification.
