# Inquiry administration: current authority locking

October 1, 2026. The correction protects private operations from stale MySQL repeatable-read authority and cached Filament records. Current authority stays locked through use. Its two-engine proof passes; full Foundation acceptance remains pending.

## Mechanism

`view`, `openInbox` and `transition` lock the persisted `User`, check Gate `administer-catalog` and MFA, and retain the lock through reading/auditing or transitioning. Revocation first prevents the private operation; authorization first completes before revocation. Ordinary preflights remain unchanged.

Trusted eager `authorizedRead` wraps List `getTableRecords`, `getTableRecord` and `getAllTableRecordsCount`, plus View `resolveRecord` and `getRecord`, including cached records. Returned builders, relations, lazy collections and generators are refused. Deferred queries and earlier preflights alone do not protect this boundary.

The new 25-case class covers 18 operation/revocation races in both lock orders, three cached/fresh getter revocations, three stale repeatable-read getter revocations and one transaction/deferred-result guard. Independent workers observe MySQL contention on the exact operator `users.PRIMARY` row. Full raw-column preservation covers 18 declared tables: `users`, `customer_inquiries`, `audit_events`, `site_publications`, `site_releases`, `site_publication_revisions`, `checkout_intents`, `checkout_sessions`, `checkout_observations`, `orders`, `order_lines`, `order_attempts`, `quotes`, `quote_lines`, `quote_pricings`, `inventory_reservations`, `inventory_claims` and `promotion_uses`. Raw comparisons allow only the ordered authority revocation and, when authorized first, its expected operation/audit; all unrelated retained evidence and synthetic gateway calls remain unchanged. This does not assert preservation of the whole application schema.

## Exact successful proof

Named [run 36878659973](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36878659973), attempt 1, created `14:44:01Z`, concluded **success**. Automatic Focused feedback is separate. Tested [commit c123af4](https://github.com/VASEYDEV/VASEYAUDIO/commit/c123af4e2fe42e266f96eb0ba6d2357bf9afdf94) has tree `5d296c6c346b5480f4bc080eb90e4fe5b462696f`, sole parent [50007cf](https://github.com/VASEYDEV/VASEYAUDIO/commit/50007cfb36d281ba360b5f91139ccf3da3eef6ab), tree `328d107ed498fb7f8537521e318b636bd2cf74b9`, and grandparent `43f39e90c3fb8916bfd27dd97d45acf4b0c4d328`.

Six administration PHP files match reviewed donor `7aa2230e3af6155c3fca53acf472a439278cbaa1`; notification allowances remain. The 948-entry proof is 947 permanent entries plus one temporary workflow. Original receipts verify event/ancestry, all hashes, both patches and unchanged-source bookends; removing that workflow restores the permanent tree.

Live discovery and JUnit agree on 128 unique identities in six files: inquiry HTTP 78, inquiry admin 9, inquiry concurrency 9, notification work 6, notification concurrency 1 and administration concurrency 25.

| Engine | Recorded / executed / skipped | Assertions | CLI / JUnit seconds | Post-behavior quality |
| --- | --- | ---: | --- | --- |
| SQLite | 128 / 97 / 31 | 2,903 | 7.830 / 7.415806 | Syntax 28 and Pint 28 pass |
| MySQL | 128 / 128 / 0 | 5,954 | 448.988 / 448.961956 | Syntax 28 and Pint 28 pass |

Both have zero errors/failures, positive assertions in every execution and unchanged strict issue flags. SQLite's exact current-policy skips are the old nine inquiry races, notification race, 18 administration races and three stale-snapshot getters; its four common new administration cases execute. MySQL executes every identity, including all 31 database-specific cases. SQLite does not prove those races.

Runtimes: PHP 8.4.26, PHPUnit 12.5.34, Pint 1.32.1 and Composer 2.10.3; SQLite 3.45.1 fresh `:memory:`; MySQL 8.4.11, `REPEATABLE-READ`, `lower_case_table_names=0`. Both start with zero tables. Locked Composer install/validate/audit pass. Genuine FFmpeg/ffprobe, prlimit, qpdf, pdfinfo and gcc support the media/artwork fixture; scanner/providers are test-bound.

| Engine | Job | Original artifact | Bytes | ZIP SHA-256 |
| --- | --- | --- | ---: | --- |
| SQLite | [110424521423](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36878659973/job/110424521423) | [11170495984](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36878659973/artifacts/11170495984) | 97,274 | `caf8c38850de7e6d659acc590a2bd098acada48fcfb9d7ea83d3bd54c19ecd2d` |
| MySQL | [110424521134](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36878659973/job/110424521134) | [11171136693](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36878659973/artifacts/11171136693) | 97,183 | `6bbac5f15cfe64d048f475c0938fa472358c5fffd70cf28dead5589f069e2164` |

Each original has 23 unique CRC-valid members, 21 verified raw digests and safe source/evidence bookends. SQLite job timestamps are `14:44:04Z–14:47:08Z`; MySQL `14:44:04Z–14:54:55Z`. These are execution facts, not performance predictions.

## Failed predecessor and limits

[Run 36876322060](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36876322060) on `b4e1cb3335a6527a98b9fe5b5f07bbc9523e244f` remains **failed**: both behavioral selections passed (SQLite 128/97/31, 2,903 assertions; MySQL 128/128/0, 5,830), then Pint reported one worker newline around `=>`. The reviewed correction changes only whitespace. Originals: [SQLite 11169321514](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36876322060/artifacts/11169321514), SHA `90907a447da2b51aa9ce84418abd77a530fe2fceb61b58d6bc51725bf02b986a`; [MySQL 11169408329](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36876322060/artifacts/11169408329), SHA `0d569644521f14f41fbd1fde4bb868292e5ead049d638764e4dc7b377e768e66`.

This slice also checks operator admission with durable notification-intent retention. Intake/notifications remain disabled and real transport unbound; the customer receipt stays `saved`. Synthetic `submitted` proves transport acceptance only. Immutable inquiry/policy/operator associations and uncertain-outcome fencing remain. These six files do not execute the dedicated notification migration recovery cases or replace the cancelled 158-case MySQL history. No frontend/native/PWA, real-provider or production enablement is proved. Final accepted-base Foundation remains mandatory; PR #89, #90 and #91 are not accepted by this narrow proof.
