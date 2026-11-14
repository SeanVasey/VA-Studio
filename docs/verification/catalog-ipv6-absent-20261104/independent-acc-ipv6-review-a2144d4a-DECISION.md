# Independent security review: ACC-IPV6 (catalog socket binding on a kernel without IPv6)

- Reviewer: Claude (independent subagent), 2026-11-04
- Commit reviewed: `a2144d4afb5f8ff8bc685d047c786f856878a765` (`a2144d4a`) on branch `claude/catalog-ipv6-absent-20261104`, parent
  `main` `048244a6`. It changes `app/Domain/Customers/ProductionIdentity/CatalogSocketBinding.php`,
  `tests/Unit/ProductionIdentity/CatalogSocketBindingTest.php` and `docs/verification/catalog-ipv6-absent-20261104/README.md`.
- I first reviewed these bytes as an uncommitted working-tree diff. After the commit I checked `git diff a2144d4a~1 a2144d4a -- app tests`:
  the code and test hunks are identical to what I reviewed. HEAD is `a2144d4a` and the worktree is clean. The only addition is a packet
  row recording native `CurrentCatalogAdmissionTest` OK 7/42 on this no-IPv6 host, with the TLS-downgrade subcase
  `UNEXECUTED_NO_PRIMARY_TLS_PROFILE`. I did not reproduce that native result. I re-ran the unit test at `a2144d4a`: OK 5/21.
  This approval covers `a2144d4a` only.

## Decision: APPROVE

No P0, P1 or P2 findings. Three P3 follow-ups are below; none blocks the merge.

## Core argument (Q1): the absent table cannot cause a wrong binding

`prove()` only accepts a row whose `inet_pton(local_ip) === inet_pton(client)`, and later checks `inet_pton(remote_ip) === inet_pton(host)`.
Rows from tcp6 (width 32) always decode to 16-byte addresses, IPv4-mapped ones included (`::ffff:a.b.c.d`). Rows from tcp (width 8)
always decode to 4-byte addresses. So the two tables match disjoint request families:

- With an IPv4 request (client and host are 4-byte), tcp6 rows can never enter `$matches`. Replacing tcp6 with a header-only table
  leaves the match set, the uniqueness count and the returned row unchanged.
- With an IPv6 or IPv4-mapped request, tcp has no candidate, so the empty table gives `count($matches) === 0` and the proof refuses.
  The new test's second half shows this.
- The tcp6 table only affects other outcomes through refusals: a duplicate inode across tables, a malformed owned tcp6 row, or the
  `MAX_BYTES`/`MAX_ROWS`/header checks on tcp6. A real IPv6-less kernel can't produce any of these. Even on an IPv6-capable host,
  forcing the absent path could only remove refusals caused by kernel-format corruption. It could never add or substitute a match.

The worst case of a wrong "absent" decision is therefore an availability change (an IPv6 primary refuses, which fails closed). It is
never a false binding.

Dual-stack and IPv4-mapped addresses: these only exist when the IPv6 stack exists, in which case tcp6 is present and read as before.
Behavior for mapped client endpoints doesn't change; it already refused against tcp for the reason above.

Module loaded mid-request: `ipv6.ko` can't be unloaded, so if tcp6 is absent when checked, the stack was absent at every earlier
moment, including when the primary socket and the fd snapshot were made. If the module loads after the check, any IPv6 socket it
enables is newer than the fd snapshot. `CurrentCatalog` calls `attest()` twice and compares the results. If the module loads
between the two calls, an IPv4 primary gives identical rows, so the comparison still holds. An IPv6 primary can't exist in that window.

Network namespaces: `/proc/self/net` shows the netns of the thread-group leader (`/proc/thread-self/net` shows the calling thread's).
A socket stays in the netns it was created in, so after a `setns()` the primary is simply missing and the proof refuses, as it did
before this change. Whether the IPv6 stack exists is kernel-wide, not per netns: `ipv6.disable=1` or a missing module removes
`tcp6`/`if_inet6` from every netns, and pernet registration adds them to every netns once the stack loads. So no netns can lack tcp6
while the stack exists. (Exception: a tcp6 pernet init failure aborts creation of that netns.)

## Q2: environment attacker (no PHP code)

To make tcp6 and if_inet6 look absent while IPv6 exists, the attacker needs to control the process's view of `/proc`: bind-mount over
`/proc/<pid>/net`, a fake or FUSE `/proc`, or a chroot with a synthetic tree. The original code already trusted that view completely.
The same capability could bind-mount a header-only regular file over `/proc/<pid>/net/tcp6` (`wrapper_type` stays `plainfile`, and
nothing checks `PROC_SUPER_MAGIC`), or fake tcp and the fd links outright. Absence gives no more than an empty-file overlay already
did, and by Q1 hiding IPv6 rows gains nothing anyway.

Other ways to hide files fail closed or don't apply:
- `hidepid=1/2/invisible` and `subset=pid` never hide `/proc/self/net/*`.
- LSM denial (AppArmor/SELinux) passes `access(F_OK)` (inode_permission with no rwx bits returns early), so the code falls through to
  `fopen`, gets EACCES, raises a warning, and the handler taints and refuses.
- Docker/K8s masked paths don't include `/proc/net`. A `/dev/null` mask would exist, read as empty, and fail the header check.
- `open_basedir` that allows `tcp` but excludes `tcp6`/`if_inet6`: I tested this on PHP 8.4.27. `file_exists` raises
  "open_basedir restriction in effect" warnings, so the class handler taints and refuses. It doesn't silently return false.

## Q3: `file_exists` under the class's protections

- The call runs inside the protected region: `ipv6Table()` is evaluated inside `snapshot()`'s `try`, after `nativeProvider()` has
  restored `file://`, while the fixed refusing handler from line 258 is active. `fixedFile(false)` runs first because PHP evaluates
  array elements left to right, so its `plainfile` check passed immediately beforehand.
- `file_exists` is unqualified-safe (`\file_exists`), and is now in `internalFunctions()`. A disabled-and-redeclared userland
  `file_exists` taints. Disabled-only makes `ReflectionFunction` throw, which also taints.
- With the native plain wrapper, PHP 8.4 `php_stat(FS_EXISTS)` calls `access(F_OK)` without the stat cache, after a warning-emitting
  open_basedir check. Under normal conditions it emits no warnings. The only warning source is open_basedir (tested), and that fails
  closed (it taints, just as `fopen(tcp6)` already would). The paths are constant, so it can't hit a NUL or empty path.
- Gap: `file_exists` dispatches through the `file` wrapper that is current at call time, and unlike `opendir`/`fopen` it leaves no
  `wrapper_type` evidence to check (P3-1). I confirmed this with a user wrapper: its `url_stat` answered all three probes, while `fopen`
  through the same wrapper reported `user-space`.

## Q4: header constant and test

- `ABSENT_IPV6_TABLE` matches `table()`'s header regex (`remote_address` alternative, single spaces match `\s+`, the trailing `\n` is
  removed by `rtrim`). `table()` returns `[]` for width 32 and width 8 (checked via reflection), and the size, NUL and row-count limits
  pass.
- On this host, `snapshot()` invoked via reflection returned the real tcp table, 1 owned inode, and `tcp6 === ABSENT_IPV6_TABLE`.
  Host facts: `ipv6.disable=1` on the kernel cmdline, no `tcp6`/`if_inet6`/`/proc/sys/net/ipv6`.
- The new test proves that the constant parses as an empty table, that an IPv4 primary still binds, and that an IPv6 primary refuses.
  It does not exercise `ipv6Table()` (P3-2).

## Q5: anything else weakened

Nothing else changes. When tcp6 or if_inet6 exists, or tcp is missing, the code path is byte-for-byte the old `fixedFile(true)`: a
present but unreadable tcp6 still refuses, and an unreadable tcp refuses earlier. The public constant adds no capability, since
`prove()` already accepted any string. The `PHP_VERSION` pin, the Linux/mysqlnd checks and the `requested()` validation are untouched.

## Findings

### P3-1: the IPv6 presence probe leaves no wrapper evidence (`CatalogSocketBinding.php:312`)
- Failure path: userland code that runs asynchronously during `snapshot()` (a `pcntl_async_signals` handler, or a destructor run by
  GC) re-registers a userland `file` wrapper after `nativeProvider()` and the tcp `fopen`. Its `url_stat` then reports tcp6 and
  if_inet6 as absent and tcp as present, and the absent table is returned. Every other wrapper-dispatched read in the class
  (`opendir`, `fopen`) is checked afterwards for `plainfile`. This probe isn't.
- Impact: by Q1 this can't produce a wrong binding (IPv4 matches are unaffected, IPv6 refuses). It breaks consistency with the
  class's "no provider injection" pattern rather than its safety. It also needs attacker PHP code running inside the process.
- Fix (optional): decide presence from a verified directory listing, for example
  `opendir('/proc/self/net', $context)` + `wrapper_type === 'plainfile'` + `readdir`, then require `tcp` and test for `tcp6`/`if_inet6`
  in the listed names, and drop `file_exists`. Alternatively, keep the current code and record in the docblock that the probe is
  unverified but can't affect matching.

### P3-2: the decision logic is untested (`CatalogSocketBindingTest.php:46-54`, `CatalogSocketBinding.php:310-317`)
- Failure path: changing `&&` to `||`, dropping the if_inet6 corroboration, or returning the constant unconditionally would all pass
  the suite, because the test only feeds the constant into `prove()`. The unconditional version would turn every IPv6 primary into a
  refusal on IPv6 hosts. That fails closed, but it's a regression nothing would catch.
- Fix: add a host-conditional reflection test of `snapshot()`, in the style of `CatalogNativeProviderTest`. If `/proc/self/net/tcp6`
  exists, assert `$tcp6 !== ABSENT_IPV6_TABLE` and that it starts with the kernel header (the kernel's padding differs from the
  constant's). If tcp6 and if_inet6 are both absent, assert identity with the constant. CI runners, which have IPv6, then cover one
  branch and this harness covers the other.

### P3-3: the kernel invariant and the redundant tcp probe are undocumented (`CatalogSocketBinding.php:306-312`; packet "Assumptions")
- "No tcp6 and no if_inet6 means no IPv6 socket" is a Linux-kernel invariant. Non-kernel procfs implementations (WSL1, gVisor
  hostinet, FUSE `/proc`) aren't guaranteed to keep it. By Q1 a violation would only cost availability or kernel-corruption refusals,
  not correctness. Production (Forge Ubuntu VPS) runs a real kernel.
- `\file_exists('/proc/self/net/tcp')` is redundant: tcp was already read successfully just before, and `fixedFile(false)` refuses
  first otherwise. It's harmless.
- Fix: expand the docblock and the packet's assumption with the family-disjointness argument (the real reason the empty table is
  safe) and the non-unloadable-module point. Optionally drop the redundant probe or add a comment explaining it.

## Not checked

- Native MySQL admission on this host (not run, per instruction; the main session owns mysqld). The packet's row at `a2144d4a`
  (CurrentCatalogAdmissionTest OK 7/42, TLS-downgrade subcase unexecuted) is the author's evidence, not mine.
- Behavior on a real Forge host, hosted MySQL CI shards and Foundation CI.
- No deterministic proof of concept for the P3-1 mid-snapshot wrapper swap (needs timed async signals or GC triggering). Only the
  primitive (`file_exists` follows a userland `file` wrapper without evidence) was demonstrated.
- PHP ZTS builds (`VCWD_ACCESS` through the realpath cache). I reasoned about them but didn't test.
- Kernel sources were not re-read for this review. The module-unload and pernet claims come from known kernel behavior.
- How `CurrentCatalog` derives `client_endpoint` and the server-side view of mapped addresses (unchanged by this diff).
- The packet's PHP-pin and collation notes (out of scope; they look correctly raised to Sean rather than changed).

## Evidence (commands run)

- `php …phpunit… tests/Unit/ProductionIdentity/CatalogSocketBindingTest.php` (SQLite env from the brief): OK, 5 tests, 21 assertions.
- Same runner over `tests/Unit/ProductionIdentity/`: OK, 74 tests, 1650 assertions. `php -l` on the class: clean.
- `php -d open_basedir=/proc/self/fd/:/proc/self/net/tcp:… -r 'file_exists(...)'`: warnings for tcp6/if_inet6 (the class handler
  would taint and refuse).
- Reflection probe in scratchpad: `snapshot()` → absent constant on this host. `table(ABSENT_IPV6_TABLE, 32|8)` → `[]`. A userland
  `file` wrapper's `url_stat` served all three `file_exists` probes, and `fopen` reported `wrapper_type=user-space`.
- Host: PHP 8.4.27 CLI NTS, kernel cmdline includes `ipv6.disable=1`.
