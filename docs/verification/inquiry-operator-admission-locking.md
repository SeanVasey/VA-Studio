# Inquiry operator admission locking

The admission transaction previously locked the site publication but read the configured operator without a lock. A concurrent admin, email-verification or required MFA revocation could commit after that read and before the private inquiry and receipt audit were inserted. A late user lock followed by ordinary Gate/MFA reloads also remains unsafe under an already established MySQL repeatable-read view.

`SubmitInquiry` retains the publication-first lock order and requests the server-controlled current-lock mode of `InquiryPolicy`. That mode requires an active transaction and uses `users.PRIMARY` locking reads for the configured user, the existing `administer-catalog` Gate and the existing configured panel/provider MFA rule. Every authority reload is current and the user lock is retained through transaction completion. The typed option defaults to false; all ordinary callers and public contact projection keep their existing read behavior. No client field chooses this mode, no authority predicate is duplicated in the UI, and no retained model is trusted as the current operator.

The underlying contracts are documented by [Laravel 13 additional Gate context](https://laravel.com/framework/docs/13.x/authorization#supplying-additional-context) and MySQL 8.4's [locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html) and [consistent nonlocking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html). Current locking reads use current row data and block conflicting updates until transaction completion; ordinary repeatable reads can retain their earlier view. This change addresses operator authority, not a general redesign of site-publication snapshot reads.

The original three inquiry race identities, method bodies and publication-mutex helper/worker stay unchanged. A separate helper and PHP worker add six source-defined cases in `CustomerInquiryConcurrencyTest`:

| Revocation | Admission locks first | Revocation locks first |
| --- | --- | --- |
| `is_admin` removed | One inquiry and receipt audit commit before revocation | Generic unavailable; no inquiry or receipt audit |
| `email_verified_at` removed | Same saved-before-revocation boundary | Same denial boundary |
| Required `app_authentication_secret` removed | Same saved-before-revocation boundary | Same denial boundary |

These cases require committed synthetic fixtures, a real encrypted MFA secret and the configured MFA provider. The admission PHP process deliberately establishes an eligible ordinary repeatable-read snapshot before either user lock. Two independent PHP workers use independent MySQL connections; the parent observes a real `performance_schema.data_lock_waits` waiter/blocker on the exact configured `users.PRIMARY` row before releasing the first worker. The revocation worker does not take a publication lock. No mocked authority or same-process transaction is offered as MySQL proof.

All unrelated raw user columns must remain exact. Rejected admission preserves every raw inquiry, audit, publication, release and publication-history row. Successful admission retains the exact payload, owner/key/hash, notice, retention reference, operator association and release/hash, adds one receipt audit, and preserves the prior raw audit and site evidence. After revocation, both new submissions and exact replay are denied without changing the retained raw evidence. The existing process/barrier limits remain 50/30/20 seconds; no retry, bypass, provider send or new production activation is added.

The existing SQLite policy keeps its class/base-method schema and adds one precise method pair. Its six provider identities must expand from actual discovery; no dataset suffix, duplicate or whole-class allowance is introduced. The three selected files are `CustomerInquiryHttpTest.php`, `CustomerInquiryAdminTest.php` and `CustomerInquiryConcurrencyTest.php`. The source-defined expectation is 96 cases: 78 HTTP, 9 admin and 9 concurrency. Actual focused discovery must confirm this before any census claim. Expected engine behavior is 96 executed/no skips on MySQL and 87 executed/9 exact MySQL-only skips on SQLite.

Available local checks:

```sh
python3 scripts/ci/test-phpunit-shards.py
python3 scripts/ci/test-database-receipts.py
git diff --check
```

The unchanged Python safeguards passed 35 partition and 24 receipt tests. There is no local PHP executable: PHP syntax, Pint, genuine current discovery and PHPUnit/MySQL execution remain pending. The source-defined cases and static checks do not establish concurrency, fresh complete Foundation acceptance, expected-head merge, provider delivery, production enablement or parent/package completion. Earlier source-specific or failed runs do not accept this successor.
