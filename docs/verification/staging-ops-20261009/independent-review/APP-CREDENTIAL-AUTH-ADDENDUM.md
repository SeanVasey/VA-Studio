# Independent persisted app credential authentication review

**Decision: APPROVE exact functional source
`381d116bbea46f9f3dd5a7bc05f80b147d77ca8e` for the bounded development change.**
Codex finding 4226910300 is resolved. No unresolved material finding remains in this
delta; Sean's actual Forge credential and grants remain unverified.

After unchanged file-custody admission, provisioning now authenticates the persisted
app credential before grants. A subshell creates a mode 0600 temporary defaults file
under the protected secrets directory, unsets its credential array, then invokes the
client with a scrubbed environment, login paths disabled, TCP and a five-second
connection timeout. Exact `vasey_app@127.0.0.1` identity is required. The subshell EXIT
trap removes the temporary file and preserves the original result; cleanup failure
refuses grants. Passwords stay out of process arguments and environment. Existing
account creation, orphan refusal and no-rotation behavior are unchanged.

The independent selection was:

```sh
python3 docs/verification/staging-ops-20261009/independent-review/app-credential-auth-probe.py --source-sha 381d116bbea46f9f3dd5a7bc05f80b147d77ca8e
```

[Final receipt](app-credential-auth-final.txt): **1 method PASS** across five states:
correct credential, failed authentication, wrong user, wrong host and forced cleanup
failure. It exercises the actual complete app-credential provision block, checks
private temporary bytes/mode/link count and client flags, asserts authentication
before grants, unchanged persisted file and ordinary-exit cleanup. Deliberate cleanup
failure leaves one private file and blocks grants; fixture teardown removes it.
Root ownership and client/database outcomes here are bounded substitutes.

[Old-source red](app-credential-auth-red.txt), at exact `444131c5`: **1 method /4 failing
states**, exit 1. Even a correct file reaches grants without authentication, and failed,
wrong-user and wrong-host responses cannot prevent success. [Initial current green](app-credential-auth-initial-green.txt)
passes those four states; the final selection adds the cleanup-failure property.
Only progress-line whitespace and three empty assertion-message suffix spaces in
the new red receipt are normalized for diff checks; outcomes are unchanged.

The genuine native proof used the existing guarded disposable-server helper:

```sh
source /workspace/.va-studio-toolchain/activate.sh
MYSQL_TEST_BASEDIR=/workspace/.va-studio-toolchain/mysql84 MYSQL_TEST_PORT=3306 MYSQL_TEST_LIBRARY_PATH=/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64:/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64/mysql/private bash scripts/dev/with-mysql-test-server.sh /workspace/.va-studio-toolchain/standalone/bin/php8.4 docs/verification/staging-ops-20261009/independent-review/app-credential-auth-native.php 381d116bbea46f9f3dd5a7bc05f80b147d77ca8e
```

[Native receipt](app-credential-auth-native.txt): exit 0, genuine **MySQL 8.4.11**
client/server. Before account mutation, the driver verifies testing/disposable schema
and root/loopback settings, unique helper datadir, empty socket and native version.
The exact source custody/authentication functions accept the correct private credential
and reject a wrong password. A raw native client first proves successful authentication
as `vasey_app@localhost`; the source rejects that wrong identity. Private temporary
client files are removed after all three ordinary outcomes, including hidden files.
Root ownership is modeled; password authentication, client/server, bytes/modes and
cleanup are genuine. A fixed adapter restores only native MySQL libraries after
`env -i`. Private files and disposable server are cleaned; no credential is printed
or retained in the repository.

I inspected owner exact-source receipts: **68 ops PASS**, genuine PHP 8.4.26 runtime
**13 tests /63 assertions PASS**. Independent provision Bash syntax, Shellcheck `-x`
and native-driver Pint checks pass. Product source outside this provision function
and its canonical test has an empty diff from prior approved publication
`444131c5151ee7bdc7b3c1aeb3a322d73f9a140a`, excluding inspected docs/CHANGELOG.
Prior bounded approvals carry on unchanged paths.

This establishes admission and fixture authentication, not real host permissions,
grants, interrupted-process cleanup under SIGKILL, backup/restore shipping or Stripe
TEST delivery. Existing host/key/builder/runtime acceptance limits remain open. A
publication successor needs explicit source-equivalence carry and final exact-head
review/preflight gates before merge.
