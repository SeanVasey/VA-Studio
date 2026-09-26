# Read-only test commerce operations

Status: **Merged through [PR #66](https://github.com/VASEYDEV/VASEYAUDIO/pull/66), 2026-09-26.** This increment follows accepted payment finalization in PR #64 and accepted WP-08 original-contract issuance in PR #65. Its focused authorization/privacy checks, full CI and independent review are recorded in the [ordered acceptance record](development-order.md#current-increment-and-next-handoff). It does not resolve a paid exception, activate delivery, refund a payment or establish production readiness.

Verified staff can open **Test commerce → Test payment exceptions** or **Test commerce → Test contract issuance** in the existing Studio administration panel. Both lists use the existing `administer-catalog` gate, verified-email requirement and panel MFA policy. Every Livewire request reauthorizes the current actor and checks the current panel’s required MFA through Filament’s configured providers. A component mounted before staff access or MFA enrollment is removed does not retain permission, and newly required MFA applies on refresh. No new role system or wider customer-data permission is introduced.

The domain reader scopes every query to retained test-mode payment/finalization rows matching `payments.stripe.account_id`. A missing, invalid or different account configuration never falls back to all accounts. Historical visibility does not depend on checkout, payment-processing, finalization or contract-issuance enablement flags. Removing an issuance flag can stop work without hiding its retained queue from staff.

## What the lists show

| List | Visible information | Meaning |
| --- | --- | --- |
| Test payment exceptions | Opaque order/finalization IDs, bounded reason, confirmation and finalization timestamps | A retained paid-exception record; occupied resources still require a separate reviewed resolution workflow. |
| Test contract issuance | Opaque order/grant/request/document IDs, bounded work status/reason, attempts and retry/claim/issue timestamps | Recorded progress for each paid test grant, including grants without a request. |

Both lists are read-only. There are no create/edit/delete/bulk/export actions, document links, refund controls, reissue buttons or reset controls. Search is limited to opaque order/grant locators. Filters narrow the configured account scope. Pagination offers 10, 25 or 50 records, and a tampered page-size request is bounded to 25 instead of accepting an unbounded “all” value. Times are labeled UTC.

The reader selects a fixed set of metadata columns. It does not decrypt buyer, seller, terms, provider evidence or grant input. It does not select ciphertexts, storage paths, claim tokens or hashes into results. Hash equality is used inside SQL only for structural binding checks. Refreshing a list does not call a provider, render a PDF, read original bytes, dispatch a job or change a commerce record.

## Issuance states and limits

| Recorded status | Interpretation |
| --- | --- |
| Not requested | A paid grant has no contract-render request or original manifest. |
| Waiting | The request has pending work. |
| Processing | Work has a retained claim; the displayed expiry helps identify abandoned claims. |
| Retry scheduled | Work retains a retry reason and its next eligible time. |
| Needs attention | Work is quarantined; this list cannot reset it. |
| Original recorded | The completed work, request, grant and original manifest have matching structural identities and input/profile hashes. |
| Evidence needs attention | Missing work, an unexpected completed state, or inconsistent request/outbox/original bindings prevent a normal progress label. |

**Original recorded is a database observation, not a current filesystem health check or active entitlement.** A missing physical file still has its immutable retained manifest. Recovery must preserve that manifest and restore the original exact bytes through the existing operator procedure; this page does not inspect or repair it. The lists do not replace the complete cryptographic order/grant verifier used at issuance or future delivery activation.

The same configured test-account scope applies to search, filters and refreshed requests. Other accounts and live payments cannot enter these views. A missing account configuration produces an empty scoped view rather than displaying another account's records.

## Verification and next work

`TestCommerceOperationsTest` covers guest/customer/unverified-admin denial, direct-route and Livewire authorization, removal of staff access, initial and reactive panel MFA, private-field omission, account scoping, historical flag withdrawal, recorded state transitions, missing work, cross-bound originals, no runtime side effects and bounded page sizes. The focused suite passed locally on PHP 8.4.26 and SQLite: `php artisan test --filter=TestCommerceOperationsTest --no-ansi` ran 13 tests / 148 assertions in 25.146 seconds, including actual Filament HTTP and Livewire requests. The initial 11-case run passed 10 tests and exposed an overbroad no-dispatch fixture assertion: synthetic media jobs existed before the read began. Resetting the queue fake after fixture construction measures only the read boundary; the unchanged no-write/provider/render checks and full rerun passed. Independent review subsequently found that an already mounted component could refresh after required MFA enrollment was withdrawn. The shared page now checks Filament’s configured MFA providers on every request. Both lists have regressions for withdrawn enrollment and MFA becoming required after mounting; the 13-case rerun passed. The [ordered acceptance record](development-order.md#current-increment-and-next-handoff) tracks full MySQL/SQLite CI and final independent review against the integrating PR's actual source.

After this visibility increment, continue the ordered [WP-08 activation and secure-delivery handoff](development-order.md#next-dependency-ready-development-slices). Financial paid-exception resolution, verified unpaid-resource release, refunds/disputes, granular finance/support permissions and active customer recovery require their separate reviewed policies and state transitions. Visibility alone completes none of those actions.
