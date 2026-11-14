# Production free grants (family 256): HTTP mount

The customer HTTP mount for family 256 is built and ships off. `production-free-grants.http_mounted` is the literal
`false` in `config/production-free-grants.php`, next to `enabled => false` and `provenance => null`. Mounting
approves nothing: no terms, assets, storage host, renderer profile or sources adapter. Live use needs Sean's explicit
authorization and the activation inputs listed at the end.

## What is mounted when the flag is true

`routes/web.php` requires `routes/production-free-grants.php` only when `App\Http\ProductionFree\ProductionFreeHttpMount`
admits: an exact boolean `true` read from the loaded configuration storage (no repository method, no provider
resolution) and a cached environment of `local` or `testing`. A string `"true"`, `1`, `null`, an array or the
`production` environment registers nothing.

| Method | Path | Body | Domain call |
| --- | --- | --- | --- |
| GET | `/production-free-grants/library` | none | `ProductionFreeGrantLibrary::index` |
| GET | `/production-free-grants/origins/{origin}` | none | `ProductionFreeGrantLibrary::show` |
| POST | `/production-free-grants/definitions/{definition}/review` | JSON `{declaredName}` | `ProductionFreeGrants::review` |
| POST | `/production-free-grants/definitions/{definition}/accept` | JSON assent input | `ProductionFreeGrants::accept` |
| POST | `/production-free-grants/origins/{origin}/authorizations` | JSON `{originSeal, role}` | `ProductionFreeGrantDownloads::authorize` |
| POST | `/production-free-grants/authorizations/{authorization}/redeem` | form `token`, `_token`, or JSON `{token}` | `ProductionFreeGrantDownloads::redeem` |

All six routes use the web session, real CSRF, session blocking (`block(20, 5)`) and per-tier throttles, without
Inertia. Each tier has its own limiter prefix (reads 60, review 30, accept and authorize 20, redeem 20 a minute), so
library views cannot spend the write or redemption budget. There is no page. The bearer token is only ever in a
request body and is retained only as a hash.

## The root seam

`App\Http\ProductionFree\ProductionFreePrivacy` is prepended in `bootstrap/app.php`, like the Tax and account-feature
privacy middleware. It runs before the web group on every request under `production-free-grants/*`:

- Unmounted (including a stale cached route table after the flag is withdrawn): a plain 404 before any session,
  identity or SQL work.
- GET or POST only; no method override; no query string; no `Range` or `If-Range` (whole-file delivery only); same
  origin; identity content encoding.
- GET has no body. JSON bodies are at most 4 KiB and duplicate object keys are refused. The native redemption form is
  at most 4 KiB and holds exactly `token` and `_token`.
- Every response is private: `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`,
  `X-Robots-Tag: noindex, nofollow`, `X-Content-Type-Options: nosniff`, `Vary: Cookie`. Every failure is one JSON
  shape, `{"code": ..., "reason": ...}`, where `reason` is the domain's fixed refusal code.

Refusal mapping: `not_found`, `not_open`, `not_entitled` and a disabled policy are 404; `identity_refused`,
`token_refused` and `provenance` are 403; invalid input is 422; `expired` and `revoked` are 410; `rate_limited` is 429
with `Retry-After: 60`; `contention` is 503 with `Retry-After: 1` and code `PRODUCTION_FREE_GRANT_CONTENTION`
(the A1-6 retryable mapping; the same request key is the safe retry); `outcome_unknown` is 503 with code
`PRODUCTION_FREE_GRANT_OUTCOME_UNKNOWN`; schema, spool and connection failures are 503; stale input, a used key or
token, a cap and a pending original are 409.

## Identity, commit and first byte

- The request-bound capsule (`ProductionFreeGrantHttpBinding`, composed from Codex's ff0) captures the T23 marker,
  session, customer guard, resolved actor and plain configuration once. It is re-proved inside every command's commit,
  before JSON is returned and before the first header of a download.
- Every command commits through `ProductionFreeGrantCommitObserver`: ordinary committing listeners run first, then the
  command's terminal proof (graph, capsule, owner, policy) and the permanent parent floor, immediately before the
  physical commit. A failure that rolls back the owned frame is a refusal. A failure after the physical commit is
  `outcome_unknown`. So is a frame found already gone: a listener's raw or implicit commit (for example DDL on MySQL),
  or a commit or rollback followed by a reopen, may have made the write durable before the proof. The observer detects
  that but cannot prevent or undo it, so it is never reported as a refusal. A deadlock or lock wait timeout stays the
  retryable `contention`.
- A redemption's response runs the transfer's committed read before its first header: a read-only committed frame
  re-proves the owner and re-reads the exact Free rows without locks, on the transfer's original deadline. A
  withdrawal after the redemption commit (revocation, owner, policy, mount, session) sends no download header and no
  file byte, keeps the redemption and answers `outcome_unknown`. The customer can authorize again if still entitled.

## Send timeout (A6-2, mount part): a deployment requirement

The transfer enforces a minimum progress rate between chunks (PR #129) and the response writes each chunk in 64 KiB
pieces, flushes, and stops at once when PHP reports the client gone. PHP cannot interrupt a write that blocks on a
client that stopped reading. Until the web server ends that write, the request keeps its spool slot (the snapshot is
an unlinked file held by a `flock` lease; the operating system releases both when the worker ends the request). A host
that mounts this must therefore set:

- a web server send timeout (for nginx, `send_timeout` and `fastcgi_send_timeout`) short enough that a stalled client
  cannot hold a slot for long, for example 30 seconds;
- a PHP-FPM `request_terminate_timeout` of at least `transfer_max_seconds` plus the snapshot bound, so a healthy slow
  transfer is not cut inside its own deadline;
- spool slots (`spool_slots`) and free space sized for the expected concurrent downloads.

None of this is verified on a host yet.

## Renderer binding

`AppServiceProvider` now binds `ProductionFreeGrantRendererProcess` like the other grant renderers: plain in the CLI,
with the validated PHP CLI process factory outside it (M-16, `docs/ops/staging-runbook.md` section 7). The binding is
inert while the mount and policy are off: the mount has no render endpoint, and a render under the shipped policy
refuses `disabled` before the renderer is resolved. Render-worker wiring is not part of this mount.

## Rehearsal

For an explicitly authorized local or testing rehearsal, clear and rebuild the configuration and route caches under
the intended literal flags. A route cache built earlier cannot gain these routes from a later setting, and the root
seam refuses a withdrawn flag even when an old route table is still loaded.

## Open before any activation

Unchanged by this mount: the real sources adapter (F-7: refuse withdrawn, quarantined or taken-down assets, host-local,
bounding its own `open()`), the renderer r2 profile or hash-checked fonts, approved terms text and assets, production
storage, spool sizing and the send timeout above, the A1-1 drain runbook before any profile revision, A1-7/F-8 seal-key
rotation, and `verified_production` provenance (the policy still refuses anything but `synthetic_rehearsal` in
`local`/`testing`). Staff Filament actions (F-3) are not built.
