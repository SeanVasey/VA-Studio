# Catalog socket binding on a kernel without IPv6 (lane ACC-IPV6, 2026-11-04)

Development evidence from the Claude Code harness on branch `claude/catalog-ipv6-absent-20261104`, from `main` `048244a6`.
It is not final or Foundation acceptance.

## Defect

`CatalogSocketBinding::snapshot()` (account guard, PR #190) proves that the identity catalog's primary connection is the
process's own direct TCP socket. It reads `/proc/self/fd`, `/proc/self/net/tcp` and `/proc/self/net/tcp6`, and refuses if
any of them cannot be opened. A kernel built or booted without IPv6 (`ipv6.disable=1` is a common hardening setting, and
this harness's Firecracker VM has no IPv6 stack) has no `tcp6` file. On such a host every identity migration, and every
native test that migrates the identity schema, refuses with `Current private catalog admission required.`, whatever the
connection.

## Fix

`snapshot()` now takes the IPv6 table from `ipv6Table()`. When `/proc/self/net/tcp6` and `/proc/self/net/if_inet6` are both
absent and `/proc/self/net/tcp` is present, the kernel has no IPv6 stack, no IPv6 socket can exist, and the empty table
(`ABSENT_IPV6_TABLE`, header only) is the complete snapshot. In every other case the existing `fixedFile(true)` read runs
unchanged, so a present-but-unreadable `tcp6` still refuses. `file_exists` joins the internal-function list that
`requireUntainted()` checks. `prove()` is unchanged: an IPv6 primary can never bind against the empty table.

## Assumptions

- IPv6 sockets need the IPv6 stack, and the stack exposes `/proc/self/net/if_inet6` and `/proc/self/net/tcp6` whenever it
  is present (built in or loaded as a module). The check runs after the primary connection exists, so a module loaded
  later cannot have created the primary socket.
- `file_exists` goes through the same restored native `file://` wrapper as the other reads (`nativeProvider()` runs first).

## Verification (this harness)

| Check | Result |
| --- | --- |
| `CatalogSocketBindingTest` (new case: the absent table adds no row and an IPv6 primary refuses), `CatalogNativeProviderTest`, `CatalogPrivacyTest` on SQLite | OK, 21 tests, 86 assertions |
| `php -l`, Pint on both changed files | clean |
| `CurrentCatalogAdmissionTest` on private MySQL 8.4.11, this no-IPv6 host, PHP 8.4.27 | OK, 7 tests, 42 assertions (23 min); the TLS-downgrade subcase reports `UNEXECUTED_NO_PRIMARY_TLS_PROFILE` (no TLS profile here). On `main` the same file refuses all 7 here |

## Environment notes for native runs in this harness

Main's binding also requires, and these are environment facts, not code changes:

- PHP exactly 8.4.27 (`CatalogSocketBinding::attest`). The harness upgraded its PHP packages from 8.4.26 to 8.4.27.
- A literal-IP client endpoint: the private server runs with `--skip-name-resolve` and a `root@127.0.0.1` account.
- Databases created `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` (the account guard checks the trigger
  `DATABASE_COLLATION`), as earlier private native setups did.

## Not changed, raised to Sean

The exact `PHP_VERSION === '8.4.27'` pin refuses every later patch release. CI (`setup-php` '8.4') and Forge install the latest
8.4 patch, so identity migrations will refuse on hosts and hosted MySQL shards as soon as 8.4.28 ships. Changing it needs a
reviewed policy, not a silent edit. Hosted MySQL shards also use the `mysql:8.4` image's default collation
(`utf8mb4_0900_ai_ci`) for `vaseyaudio_test`; whether the account guard's collation check then refuses on CI has not been run.

## Untested

Independent review, the full native identity suites, a real Forge host, Foundation CI.

## Independent review at `a2144d4a`

Decision: **APPROVE** ([`independent-acc-ipv6-review-a2144d4a-DECISION.md`](independent-acc-ipv6-review-a2144d4a-DECISION.md)),
no P0 to P2. The empty table can only drop refusals caused by corrupt kernel output, never cause a false binding: tcp rows
decode to four bytes and tcp6 rows to sixteen, so each request matches rows from one table only.

- P3-2 (the decision logic was untested) and P3-3 (the reasoning was undocumented) are fixed in the next commit: a
  host-conditional `snapshot()` test (the read path on IPv6 hosts, the substitution here) and the docblock. Mutation: with the
  substitution disabled, the new test errors on this host (killed). `tests/Unit/ProductionIdentity/` OK, 75 tests, 1653 assertions.
- P3-1 (the presence check uses `file_exists`, which leaves no `plainfile` wrapper evidence) is deferred: it needs attacker
  PHP code inside the process and, per the review, still cannot produce a false binding.
