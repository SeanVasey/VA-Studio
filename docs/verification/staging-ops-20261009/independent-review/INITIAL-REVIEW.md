# Independent security review: Forge staging kit, first candidate

Reviewed source: `1d4b8f4361bd2cfd2263e2f61a6a077b09eba681` (PR #63 source
`c786098558bd9917145217bb0c28bc02a256bcfb`, integrated reviewed main and D1).
Decision: **BLOCKED pending the seven concrete repairs below.** This is an
independent review; the reviewer did not edit product scripts or tests, provision
a host, mount storage, use provider credentials, or push a commit.

## Concrete findings

| ID | Finding | Consequence | Independent evidence |
| --- | --- | --- | --- |
| F1 | `provision.sh` creates the activation parent `root:root 0755`, while the application-user deploy directly creates `current.next` and renames it to `current`. | Every first activation fails on directory write permission. | `red-probes.txt`: bounded nonroot activation under a parent without app write is denied. The directory ownership and original activation lines were read from the exact candidate. |
| F2 | `cmd_detach` resolves an app-writable release path with `mountpoint` and `umount` without the pin/canonical checks used by attach. | The sudo application user can dispatch a root unmount against a host mount outside the release tree by substituting a path component. | `red-probes.txt`: an owned release target symlink to `/proc` is accepted by genuine `mountpoint`; a harmless `umount` substitute records the escaped request. No real unmount was executed. |
| F3 | The grep-only production-flag admission does not use Laravel dotenv parsing. | A quoted production checkout enable flag is admitted as safe although genuine Laravel `Env` evaluates it to boolean true. | `red-probes.txt`: `PRODUCTION_CHECKOUT_ENABLED="true"` is enabled by the real locked Laravel/Dotenv packages and the deploy validator exits zero. Effective inherited environment also needs to agree with the runtime that creates `config:cache`. |
| F4 | Charset-rendering normalization is applied to the whole dump. | Different retained row text can be equated by the restore verifier, contrary to its exact-row claim. | `red-probes.txt`: two different synthetic INSERT literals become identical under the exact candidate sed expression. Normalize only proven schema rendering, preserving all data/string bytes. |
| F5 | A failed reproof leaves a previous `RESTORE_CHECK` success marker. | The runbook's rollback acceptance marker can claim current validity after integrity verification has failed. | `red-reproof.txt`: corrupt synthetic dump/archive hashes refuse, exit one, but the old `result=RESTORE_VERIFIED` remains. |
| F6 | Deploy EXIT reporting treats the phase flag as proof that every writer stopped. | A failed quiesce or a partially completed resume reports false stopped-writer certainty to the recovery operator. | `red-deploy-outcome.txt`: the actual EXIT handler, with the exact possible phase state, prints "writers are stopped" without checking writer state. |
| F7 | Same-SHA environment refresh overwrites the served `.env` without the pre-change snapshot used by full deploys. | A failed refresh or accidental key replacement loses the previously served key/configuration needed for recovery; the rollback path does not restore `env.backup`. | `red-environment-refresh.txt`: the exact refresh branch with harmless control/PHP substitutes records old key at quiesce and new key at resume, with no snapshot. |

The first four probes run with:

```sh
python3 docs/verification/staging-ops-20261009/independent-review/review_probes.py
```

`red-probes.txt` records the original four cases: four failures, exit one. The
later F5, F6 and F7 isolated runs each record one failure, exit one. These are
intentional red safety assertions, not passing implementation evidence.

## Native nginx/FPM evidence

```sh
source /workspace/.va-studio-toolchain/activate.sh
python3 docs/verification/staging-ops-20261009/independent-review/nginx_fpm_probe.py
```

`nginx-fpm-routing.txt`: **26 checks pass, exit zero**, real nginx **1.26.3** and
PHP **8.4.26 FPM**. The actual kit site and both pool templates are rendered into
an owned temporary prefix, with high loopback TLS port, owned paths, and synthetic
credentials. The backend is a tiny synthetic FastCGI responder rather than Laravel.

Observed:

- Ordinary routes, static build content, webhook trailing-slash/alternate-prefix
  routes and double-escaped paths require basic auth (401).
- GET, HEAD, PUT, PATCH, DELETE and OPTIONS on the webhook refuse (403).
- POST normalized spellings (`..`, repeated slash, percent escape) reach only
  `REQUEST_URI=/webhooks/stripe`; the query string is empty and the entry point is
  `index.php`. An encoded query delimiter remains protected.
- Authenticated requests do not serve synthetic dotfile or PHP source content.
- A real FPM request reports `fpm-fcgi` and 8.4.26; a stronger upstream
  `Referrer-Policy: no-referrer` is preserved without an appended weaker policy.
- An unauthenticated webhook body larger than 1 MiB refuses (413).

The two earlier attempts are preserved as `nginx-fpm-harness-attempt1.txt` and
`nginx-fpm-harness-attempt2.txt`: the owned harness initially omitted nginx's
compiled distribution temp paths. These are harness configuration failures;
the product site syntax was valid and the final run overrides all temp paths.

## Conditions and untested host behavior

Actual Forge/DigitalOcean credentials are unavailable until Sean supplies access.
No provisioning, privileged bind/unbind, real sudoers/service control, AppArmor
policy activation, hostname/DNS, ACME renewal, host backup shipping/encryption,
real Stripe signature verification, or live application purchase is claimed.
The kit remains private Stripe TEST staging preparation, with production and
paid-download activation conditions still open. Native PHP/nginx probes do not
prove real Forge activation. Any final approval must name the repaired source
and assess its privileged-path boundary; this first candidate is blocked.
