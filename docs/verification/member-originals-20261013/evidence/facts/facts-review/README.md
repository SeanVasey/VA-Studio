# Independent facts closure finding: actual old/fixed regression

Independent reviewer identified three mutable owner inputs that could change during a later profile decrypt callback after ProductionCustomerAccess had checked the owner: production-customer-identity configuration, a caller-referenced actor ID, and actor.exists. The prior facts raw proof compared database rows and member policy but omitted identity configuration and exists; its shallow actor array baseline also retained caller references.

Exact old application source: clean `bd3661ec02e3004fef3ae3d8a990e44ef5c6ce08`. Exact fixed source: `5d6c58da` (full SHA in source-relationship.json). The same external PHPUnit test bytes ran against both real implementations, using existing currentCallbacks/currentRaw and committedCallbacks/committedRaw. No missing API/signature failures or successful fake producer replaced the source. A test-only Crypt decorator delegates actual decrypt then changes one input for the actual retained profile ciphertext; it emits no plaintext/ciphertext/keys. The test first proves that seam executed, then requires refusal. Both phases use real retained plan/MFA member authoring and synthetic loopback T23 identity, on isolated SQLite :memory: only.

Old command (PHP8.4.26, activated installed toolchain, cwd `/workspace/VA-Studio-member-original-facts`):

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php -r 'require "vendor/autoload.php"; exit((new PHPUnit\TextUI\Application)->run(["phpunit", "--colors=never", "--log-junit", "/workspace/runtime/member-original-facts-evidence/facts-review/old.xml", "/workspace/runtime/member-original-facts-evidence/facts-review/MemberGrantFactsCallbackClosureTest.php"]));'
```

Actual old result: **6 tests,18 assertions,6 intended incorrect-admission failures,0 errors/skips,exit1,24.219s**. All three changes were admitted in both current and committed phases. Failures remain failures.

Fixed command uses the exact same external test plus whole unchanged positive/neighbour facts file:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php -r 'require "vendor/autoload.php"; exit((new PHPUnit\TextUI\Application)->run(["phpunit", "--colors=never", "--stop-on-failure", "--stop-on-error", "--log-junit", "/workspace/runtime/member-original-facts-evidence/facts-review/fixed.xml", "/workspace/runtime/member-original-facts-evidence/facts-review/MemberGrantFactsCallbackClosureTest.php", "tests/Feature/ProductionMemberOriginals/MemberGrantFactsTest.php"]));'
```

Actual fixed result: **10 tests,35 assertions,0 failures/errors/skips,exit0,41.072s**. Original facts positive/phase/lifetime tests remain included. These six new regression cases are committed under tests/Feature/ProductionMemberOriginals/MemberGrantFactsCallbackClosureTest.php with Pint-only formatting/import cleanup; the exact external→repository diff is retained. The production source executed by the fixed run equals the committed source byte-for-byte.

Correction captures original raw container/repository identity plus full production-customer-identity scalar/array configuration and actor exists/attributes/original **before the first source callback**. A bounded recursive copy detaches references (depth16,4096nodes,1MiB string budget). Both terminal raw phases compare those exact originals; no new owner callback, hooked clock, cap renewal, frame finish or authority/writer API was introduced. The independent reviewer subsequently read the integrated source and reported this finding source-closed, with no further P0/P1 in that narrow correction; this note is not the final independent DECISION.

The native schema source files are unchanged by this two-file correction. Completed private MySQL schema old2/10 failure2 and fixed4/19 all-pass receipts remain bounded to their actual source; these six new owner-input cases were SQLite-only. No native facts/authoring, host or provider proof is claimed.
