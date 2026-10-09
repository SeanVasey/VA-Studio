# Independent runtime directory initialization review

**Decision: APPROVE** exact functional source
`69a405a6480edd031aad9f40e9f83c32e8adcc24` for the bounded repair of Codex
finding `4227097077`. No unresolved product finding remains in this delta.

The real provisioning stage invokes the inline Python initializer after creating
the protected root/release/backup directories and before later provisioning
stages. It opens every path component with `O_DIRECTORY|O_NOFOLLOW`, retains
parent descriptors while creating/opening children, and changes ownership and
mode through the resulting descriptors. Its current-entry inode comparison
refuses detected directory replacement. A new private `.gitignore` is created
exclusively without following links, then written and adjusted through its file
descriptor. An existing file opens no-follow/nonblocking and must be regular with
one link; its contents, inode and mode are preserved. Errors can leave partial
initialization in admitted runtime directories, without redirecting these
changes into a symlink target.

## Independent probes

```sh
python3 docs/verification/staging-ops-20261009/independent-review/runtime-dir-probe.py --source-sha 69a405a6480edd031aad9f40e9f83c32e8adcc24
```

[Final receipt](runtime-dir-final.txt): exit 0, **6 methods PASS**, covering
**18 filesystem states** plus the actual provisioning call-site assertion:

- Twelve directory/link/special-file states refuse: evidence, temporary/private
  roots, PHP upload/system children, branding, nginx FastCGI, and private
  `.gitignore` dangling/existing symlinks, hardlinks, FIFO and directory entries.
  Protected fixture targets retain their bytes, metadata and directory contents.
- Fresh normal initialization has the required modes and new tracked ignore
  bytes. A preexisting ordinary ignore file retains its inode, bytes and mode.
- Four deterministic races cover directory replacement before open and after
  open, ignore replacement before exclusive creation, and ignore replacement
  after exclusive creation. All preserve the protected target. The first three
  refuse; the last safely writes only the captured newly created ignore inode.

The last case deliberately demonstrates a limit: an independent application
writer can replace a pathname after admission. Its visible ignore entry can
become a symlink while initialization returns success, but the root write and
metadata changes remain on the already captured new inode. Approval certifies
that inode safety, not persistent visible-path health during concurrent writes.
The README should describe refusal of a **detected** directory entry replacement.

[Expanded old-source receipt](runtime-dir-red-expanded.txt), exact
`c378295c16310a64ff4f729d7d6480b6778fb998`: exit 1, **6 methods / 12 failing
states / 2 explicit new-only skips**. Physical escapes include new directories
through temporary/private links and a new file through a dangling ignore link;
other failures demonstrate unsafe-entry admission. New descriptor-race/call-site
methods explicitly skip because the old implementation has no such initializer.
Normal initialization already passes.

[Initial old receipt](runtime-dir-red.txt) retains **3 methods / 7 failing
states**. That smaller probe checked metadata but omitted child-directory
contents, so it missed the private-root escape; the expanded probe corrects the
observation. [First green](runtime-dir-first-green.txt) retains **6 PASS** with an
additional parent-rename experiment in an entirely owned fixture. That experiment
models broader authority than an application has on the protected kit root; the
final probe replaces it with the application-capable post-create ignore race.
No failed attempt or outcome was discarded. Three unittest progress-line trailing
spaces in each old-source receipt are normalized for whitespace checks only.

The new probe executes the exact inline Python body without editing it. The
nginx global-tree traversal is mapped onto an owned mirror, and app/nginx UID/GID
are the fixture's real unprivileged identity. Actual no-follow descriptor opens,
creation, writes, metadata syscalls and closure remain genuine. Old source uses
its actual shell block and GNU `install`, with privileged ownership arguments
removed and `chown` traced. These receipts do not prove root ownership changes
or host provisioning; no global host path, service or database is mutated.

Independent Bash parse and `shellcheck -x ops/staging/provision.sh` exit 0. Owner
exact-source receipts inspected show **74 operations tests PASS**, genuine
**PHP 8.4.26: 13 tests / 63 assertions PASS**, and the focused old **12 failures /
1 explicit new-only skip** followed by current **3 PASS**.

## Carry and host limits

The explicit diff from approved `c378295c` contains only the initializer and
replacement stage call in `provision.sh`, its new canonical regression test, and
README/CHANGELOG explanation. The remaining product-source diff is empty,
exit 0. Prior app/backup MySQL authentication, administrator defaults custody,
release/operation custody, runtime and nginx approvals carry on unchanged paths.

Actual Forge UID/group ownership, protected host ancestry, package/service/mount
effects, provider credentials, off-host backup/key recovery and a real Stripe
TEST purchase remain unverified. Final publication source equivalence, preflight,
Codex review and expected-head merge gates remain the owner's responsibility.
