# Independent Composer environment ordering review

**Decision: APPROVE exact functional source
`2eee8d420aa822414841a801318b04888e4e71ac` for the bounded development change.**
Codex finding 4226653387 is resolved: the fixed build helper installs the captured
candidate environment as mode0600 after clean-checkout proof and before Composer
can run its Laravel/Filament hooks. Final built-runtime admission remains in place.

I inspected the exact helper and canonical regression delta. All product paths
except the helper and its new canonical test have an empty diff from previously
approved publication `ee9d1af29a2cd4c8e19a357f9a5091135e6df125`, excluding docs,
CHANGELOG and the inspected kit README ordering sentence. Root leases, interruption
custody, sealer, payment runner, runtime validator and environment capture are
unchanged; the prior bounded approval carries on those paths.

The independent command was:

```sh
python3 docs/verification/staging-ops-20261009/independent-review/composer-ordering-probe.py --source-sha 2eee8d420aa822414841a801318b04888e4e71ac
```

[Old-source red](composer-ordering-red.txt), at exact `ee9d1af2`: **1 method FAIL**, exit1;
the actual helper's native Composer post-autoload-dump `@php artisan package:discover`
hook exits23 because `.env` is absent. [Final receipt](composer-ordering-final.txt):
**1 method PASS**, exit0 at `2eee8d42`. This uses genuine PHP8.4.26, Composer2.8.8,
Git clone/checkout, a locked local dependency and actual Composer event execution.
The hook sees the exact frozen bytes at mode0600 before npm, without inherited
APP_ENV. A conflicting untracked mirror profile does not replace the frozen input;
the original captured file remains unchanged at mode0440.

The hook is a minimal owned PHP application, not Laravel package discovery itself;
npm and final runtime admission are substitutes. Composer repositories disable
Packagist and the local dependency needs no download. No real credential, provider
or host operation occurs. This proves ordering and private-file mode, not a complete
Laravel dependency installation or Forge build.

I inspected the owner's separate exact-source receipts: **61 ops PASS** and genuine
PHP8.4.26 runtime **13 tests /63 assertions PASS**. The changed helper also passed
independent Bash syntax and Shellcheck `-x`; whitespace checks pass. No new assertion
or receipt normalization was needed.

No unresolved material finding remains in this narrow delta. All previous actual-host,
database authentication, backup shipping, Stripe TEST purchase, key-custody and
builder-trust limits remain. A later publication candidate needs an explicit empty
product-source diff and evidence inspection before this approval carries to it.
