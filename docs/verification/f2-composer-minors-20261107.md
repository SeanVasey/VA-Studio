# F2 Composer patch/minor batch, 2026-11-07

This changes only `composer.lock` (34 packages, all patch or minor, with `composer.json` and the content hash unchanged). It
supersedes Dependabot #180 for the packages taken here. Evidence is from the Claude Code harness on PHP 8.4.27 with SQLite.

## Included

Filament 5.10.2 → 5.11.1 (ten packages), `inertiajs/inertia-laravel` 3.5.1 → 3.7.0, `phpunit/phpunit` 12.5.34 → 12.5.43, and
their resolved dependencies: `symfony/console` 7.4.23, `symfony/finder`/`process`/`string` 8.1, the contracts 3.8,
`nikic/php-parser` 5.9, `league/csv` 9.29 and the rest of the lock delta. The lock was produced with
`composer update filament/filament inertiajs/inertia-laravel phpunit/phpunit --with-dependencies --with laravel/framework:13.38.0 --with symfony/console:^7.4`.

## Deliberately held, with the reason

| Package | Why it is not in this batch |
| --- | --- |
| `tecnickcom/tc-lib-*` (14 packages; Dependabot #162, #164) | The contract render profiles (`resources/contracts/test-v1`, `test-v2`) and `MemberGrantRenderProfileRegistry` pin each package's exact version and reference. Updating them makes contract issuance refuse by design (`TcpdfContractRendererTest` and the grant rendering suites fail with `profile_changed`). An update needs a new reviewed render profile; see [`production-contract-profile.md`](../production-contract-profile.md), increment 3. |
| `stripe/stripe-php` 21.3.2 → 21.3.3 | `BillingProviderPin` pins the SDK version, reference and model-file hashes. 21.3.3 makes billing refuse with `provider_pin` (`BillingDefaultOffBootTest` and the membership award suites). This is a payment change and needs its own reviewed lane. |
| `laravel/framework` 13.38 → 13.40 | Upstream #62248 ("Roll back to savepoint on concurrency errors in nested transactions") changes how a nested lock-wait failure behaves. `MediaUploadCommitRecoveryTest::test_nested_lock_wait_failure_keeps_bytes_when_laravel_restores_depth_without_rollback` then loses the asset row while the quarantined bytes stay on disk. Adapting the media-recovery design is a reviewed domain change. |
| `symfony/console` 8.1 | A major version. Laravel accepts 7.4 or 8, so it stays on 7.4 here. |

## Results

| Check | Result |
| --- | --- |
| `composer validate --strict` | valid |
| `composer audit --locked` | no advisories |
| Full PHPUnit suite, four shards (`scripts/ci/phpunit-shards.py --shards=4`), on this lock | 10,341 tests, 9 failures, 761 skipped (the SQLite census). Shard 1 OK (2,586 tests); shard 2 1 failure; shard 3 7 failures; shard 4 1 failure. |
| The 9 failures, rerun on `main`'s unchanged lock | **all 9 fail identically on main**, so none comes from this batch |

The 9 failures that already fail on `main`:

- 4 retained-migration rollback cases (`CustomerConsentMigrationTest`, `CustomerListeningMigrationTest` ×2,
  `ProductionBuyerAssentObservationsTest`). They broke in #196 and are fixed in #201.
- 4 in `IdentityHistoricalConfigurationAdmissionTest` and 1 in `ProductionTaxCheckoutFrozenV1Test` (the `CheckoutSchema.php`
  source hash). These are tracked separately as main regressions.

Not run here: native MySQL, the browser specs and Foundation CI. Filament assets are published by the existing
`post-autoload-dump` `filament:upgrade` script and are not committed.
