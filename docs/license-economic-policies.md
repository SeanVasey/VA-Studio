# Ownership and economic policies (schema v4)

This WP-04 increment adds retained ownership declarations, a precisely labeled publishing-income entitlement and a contractual recording royalty to the v3 usage, territory and duration model. It supplies no production policy, ownership allocation, rate or approval. The fixtures are synthetic and nonbinding.

## Deliberate authoring

New admin drafts use schema 4. Choose every usage, scope and economic field explicitly. For a retained v3 version, **Define economic policies** creates a linked v4 successor with its source, usage, territory, duration and availability dates preserved. The new economic decisions start blank. Add the seven new source variables and complete the policy definitions before saving. **New revision** preserves its predecessor's schema. Existing v1-to-v2 and v2-to-v3 mapping actions remain available.

The editor captures integer basis points: 100 is 1.00%, 10,000 is 100.00%. Valid form strings become integers at the form boundary; domain calls reject strings, floats, booleans, ambiguous percentages and values outside the supported range. Changing a mode to `none` removes the stale rate/basis fields before validation. There is no default rate, income entitlement or ownership declaration.

Preview the exact source, substituted source, generated cards and complete policy evidence before requesting review. Submission freezes that content. A different authorized reviewer must confirm consistency against the exact submission hash and provide actual review evidence. The software does not establish the reviewer's legal qualification or mechanically interpret contradictory policy prose.

## Exact additive contract

Schema 4 preserves every v3 field and adds exactly these roots. No unknown or stale conditional keys are accepted.

| Root | Required shape and meaning |
| --- | --- |
| `ownership` | Exactly `source_recording`, `source_composition`, `resulting_recording`, `resulting_composition`. Each contains exactly `policy_key`. Each policy must explicitly describe the named ownership subject and any transaction effect. These are declarations, not verified chain of title or calculated allocations. |
| `publishing_income` | `mode`, `policy_key`; when `mode = share`, also integer `licensor_bps` from 1 to 10,000. Mode `none` has no rate field. The denominator is total publishing income attributable to the resulting composition, defined in the retained policy. This is the licensor's entitlement, not a composition-ownership percentage, a PRO writer/publisher split or a sale-proceeds split. |
| `recording_royalty` | `mode`, `policy_key`; when `mode = rate`, also integer `rate_bps` from 1 to 10,000 and `basis = gross_receipts` or `net_receipts`. Mode `none` has neither field. The licensee pays the licensor from receipts attributable to the resulting recording. The retained policy must define receipts, deductions, accounting and payment provisions. |
| `policies` | A list of 1 to 6 definitions, each exactly `key`, `version`, `text`. Keys are distinct within this license version; every reference resolves locally and every definition is referenced. Shared policies are allowed. No remote lookups, submitted hashes or mutable document pointers substitute for complete text. |

Policy keys match `[a-z][a-z0-9-]{0,63}`. Explicit versions match `[A-Za-z0-9][A-Za-z0-9._-]{0,31}`. Text is nonblank valid plain UTF-8, up to 20,000 bytes per definition and 60,000 bytes combined. Newlines/tabs are permitted; other control characters and `{{` / `}}` delimiters are rejected. The exact text bytes and input list order remain in structured model evidence. Derived output sorts policies by ASCII key, then includes each key, version, server-calculated SHA-256 and full text.

Policy identity is scoped to the retained license version and content hash. A label reused in a successor does not change the prior definition, and the system does not claim a global policy registry. Change the policy version label deliberately when revising its text. Published and reviewed license evidence cannot be rewritten.

Neither `none` mode extinguishes external or pre-existing obligations. No remainder is automatically assigned to the buyer, no third-party share is inferred, and exclusivity never implies ownership assignment. Collaborator store-sale proceeds remain the distinct RevenueSplitVersion workflow. The distinction between recording and composition is grounded in the [U.S. Copyright Office musician guide](https://www.copyright.gov/engage/musicians/); the schema is software structure, not a recommendation for Sean's actual legal terms.

## Shared values and complete retained source

The 15 v3 source variables remain required. Schema 4 adds:

- `{{ownership.source_recording}}`, `{{ownership.source_composition}}`, `{{ownership.resulting_recording}}`, `{{ownership.resulting_composition}}`
- `{{publishing_income}}`, `{{recording_royalty}}`
- `{{policy_texts}}`, required **exactly once** to bound full-text expansion

`LicenseTerms::statements()` produces 21 compact card statements. `LicenseTerms::sourceValues()` supplies those identical statements plus complete policy text. Ownership cards identify the precise subject and retained key/version without implying a particular ownership outcome. Publishing/royalty cards identify the rate, subject, roles and denominator/basis. Policy text is literal substituted text, not executable template code. HTML previews escape both source and policy content.

The v4 review renderer retains full text in the source and evidence comparison. Policy text changes affect the model, preview and submission hashes, even if the authored variable template is unchanged. Commercial offer and provisional quote snapshots already retain the complete structured terms and review evidence; later revisions do not rewrite their captured policies.

**Buyer disclosure remains an explicit WP-05/WP-08 dependency:** the forthcoming license-detail interface and final assent/contract must display the exact referenced policy text from the selected commercial revision. A storefront card's key/version reference is not sufficient policy disclosure or assent. This increment creates no customer contract, ownership transfer, payout, statement, grant or entitlement.

## Upgrade and recovery

Deploy the v4-aware code and forward migration `2026_09_12_000012_license_economic_terms.php`. The migration installs the v4 lifecycle state guard before removing v3. It permits only the exact schema/renderer pairs 1/v1, 2/v2, 3/v3 and 4/v4, retains existing content/review guards and changes no stored license rows. v1–v3 validation and rendering remain pinned for historical evidence.

Retain v4-capable code wherever v4 evidence exists. A rollback to older-only code can make later licenses unavailable; never rewrite a retained version to fit an older schema. The migration's `down` restores v3's guard before removing v4's, for disposable or verified-compatible environments only. Deactivate an affected new offer and publish a reviewed successor while preserving original references.

See [verification](verification/license-economics.md). U-05 actual reviewed policies, U-06/WP-08 deterministic buyer contracts and historical source reconciliation remain open. After this bounded implementation is accepted, continue WP-05 detail/disclosure/device work, then WP-06 and the original commerce sequence.
