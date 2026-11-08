# D-30 — Buyer contract identity: legal name, postal address and Exclusive artist name

Status: decided design, 2026-11-07. Steps 4–6 are implemented behind literal-false gates; nothing is collected yet. It
implements increment 2 of the [production contract profile](../production-contract-profile.md). It records Sean's
decisions C-1, C-2 and D-1 from 2026-11-07, plus his follow-up decisions below: corrections, unsupported characters, and
business licensees.

## Decision

Production checkout freezes a versioned **contract identity declaration** inside the existing `buyer_declarations`. It does
not use a separate record.

```
{schema: 'buyer-contract-identity-v2', licensee_type: 'individual' | 'business', legal_name, representative_name | null,
 legal_name_confirmation: {confirmed: true, statement_version, statement_hash},
 postal_address: {country, lines[1..3], locality?, region?, postalCode?}, artist_name | null,
 identity_meaning: 'buyer_declared_contract_identity'}
```

- **Licensee.** A business may be the licensee (Sean, 2026-11-07). For a business, `legal_name` is the business's full
  legal name and `representative_name` is the person accepting the license for it; for an individual it is `null`. Each
  type has its own confirmation statement, and the buyer's type selects which one is frozen. A statement's version starts
  with its type (`individual-…` or `business-…`), and the two statements must have different hashes. `verify()` refuses a
  declaration whose frozen statement belongs to the other type. A type whose statement is not
  approved is not offered, so the validator refuses it.
- **Statements.** The individual statement is approved (Sean, 2026-11-07): "I confirm that this is my full legal name and
  that the license should be issued to me under it." The business statement is approved too (Sean, 2026-11-07): "I confirm
  that this is the full legal name of the business, that the license should be issued to the business under that name, and
  that I am authorized to accept it on the business's behalf." Both types may be offered once checkout is wired.

- **Binding.** The declaration rides the existing hash chain: request → `request_hash` → sealed order `payload_hash` →
  paid source `order_hash`/`source_hash` → grant origin. No hash-pinned file changes. The frozen-V1 guard stays green:
  `OrderEvidence` only compares declarations for equality, and the paid sources and render inputs copy them verbatim.
- **Legacy orders.** Orders that keep the two-key shape (`legal_name`, `identity_meaning`) stay byte-identical and valid.
  The contract binder accepts only v2 declarations and refuses legacy ones with `contract_identity_missing`. Their originals
  keep the existing paid profile and are never re-rendered.
- **Paths.** V2 `ProductionTaxCheckout` (step 5) and V1 `ProductionCheckout::review` (step 6) both capture it behind their
  own gates. Both are unmounted today. Exclusive has no production path yet (`ExclusiveInventoryGate`), so the artist name is reachable only on the test
  path until a production Exclusive consumer exists.
- **Gate.** Capture sits behind a literal-false `contract_identity_v2` gate. The gate opens together with the
  `vasey-license-pdf-v1` profile, because the current paid renderers print every input field.
  - **Implemented for the V2 tax checkout (step 5):** `production-tax-checkout.contract_identity_v2`, admitted only as an
    exact `true` in `local` or `testing` (`TaxCheckoutPolicy::contractIdentityV2()`).
  - **Gate on:**
    - `ProductionTaxCheckout::order` requires the v2 declaration and refuses the legacy name-only shape;
    - the statements come from `ContractIdentityStatements`, which holds the approved texts with versions
      `individual-legal-name-confirmation-v1` and `business-legal-name-confirmation-v1`, hashed over the exact text;
    - the printable set comes from the `vasey-license-pdf-v2` faces (`ContractPrintableSet::checkout()`, since 2026-11-08;
      before that, the `test-v2` DejaVu metric files through `ContractGlyphCoverage::pinnedContractFaces()`). The profile
      manifest must match its pinned hash and each metric file the manifest's SHA-256, and the set is restricted to the
      profile's `allowed_scripts`. A test pins that the accepted set is a subset of what the `test-v2` paid renderers accept,
      so checkout refuses whatever they would refuse after payment;
    - the preview offers the licensee types and statement texts.
  - **Implemented for the V1 checkout (step 6):** `production_checkout.contract_identity_v2`, a separate literal `false`,
    admitted only as an exact `true` in `local` or `testing` (`ContractIdentityPolicy::contractIdentityV2()`). With it on,
    `ProductionCheckout::review` requires the v2 declaration from the same statements and faces and refuses the name-only
    shape. `ProductionCheckout::contractIdentityOffer()` lists the licensee types and statements, and the review response
    shows the identity the order will bind (type, names, the formatted address and the statement version). Assent copies the
    reviewed declaration unchanged. Exclusive stays unreachable: `false` is passed and `ExclusiveInventoryGate` is unchanged.
  - **Statement binding (step 6, both checkouts):** the declaration input echoes the `statementVersion` the client displayed
    next to the tick (`ContractIdentityDeclarationV2::fromDisplayedInput`). It must equal the offered statement for the
    buyer's licensee type (`hash_equals`), or the request refuses with `changed`. The server checks the version the client
    says it displayed; it cannot see the screen. A new wording is always a new version, and a test pins each approved
    version to the SHA-256 of its exact text, so a wording change without a new version fails. The offer also lists each
    statement's `statementHash` for display; the echo stays on the version. The echo was chosen over a preview commitment because V1 has no commitment before its
    review: the review request is the first thing the server sees. Two gate-state guards complete it:
    - V2: with the gate on, the preview commitment also carries `contract_identity: buyer-contract-identity-v2`, so a gate
      flip between preview and order changes the commitment and refuses with `changed`. `TaxCheckoutEvidence::order`
      rebuilds that commitment from whether the frozen declaration is v2. Gate-on tax orders written under step 5 (local
      and testing only, never collected) lack the marker and no longer open.
    - V1: assent refuses (`changed`) when the reviewed declaration's kind (v2 or name-only) differs from the gate now.
      Replaying an existing assent still returns the retained order, as `read()` does; it writes nothing.
  - **Evidence (step 6):** `TaxCheckoutEvidence::order` requires the body's declarations to equal the request's and
    re-verifies a v2 declaration (`verifyRetained`). V1 `OrderEvidence` is hash-frozen, so it keeps only its equality checks
    (which v2 declarations pass) and `ProductionCheckout` re-verifies v2 declarations where it opens a review or an order.
    A retained declaration that no longer verifies refuses as evidence (`changed`), not as buyer input.
  - **Gate off:** request hashes, sealed review and order bodies, the tax preview commitment and every response are
    byte-identical; tests rebuild the original bytes and compare them.

## Validation and rendering

| Field | Rule |
| --- | --- |
| Legal name | Current rules: ≤255 bytes, valid UTF-8, no C0/DEL, trimmed. Also refuses bidi overrides and isolates (U+202A–E, U+2066–9) under the character rule below, and requires `legalNameConfirmed === true` against a versioned statement. |
| Postal address | Structured: `country` is a required ISO 3166-1 alpha-2 code from a pinned list; `lines` holds 1–3 lines (the first required) of ≤120 bytes each; `locality` and `region` are each ≤80 bytes; `postalCode` is ≤20 bytes of `[A-Za-z0-9 -]`; the whole canonical address is ≤512 bytes. Never inferred from Stripe billing or account data. |
| Representative name | ≤255 bytes, same character rules. Required if and only if `licensee_type` is `business`. |
| Artist name | ≤120 bytes, same character rules. Required if and only if a line's license is `exclusive`. |

`{CUSTOMER_ADDRESS}` is one deterministic string, joined with ", ": the lines, then the locality, region and postal code,
then the country's English short name from the pinned `LicenseTerritories::COUNTRIES` list. **Characters the pinned contract
fonts cannot print are refused at checkout** (Sean, 2026-11-07). The buyer is asked to enter supported characters.

The character rule (implemented in `ContractIdentityDeclarationV2`) applies to the legal name, the representative name,
the artist name, the address lines, the locality and the region. A shape error (`invalid`) is always reported before a character problem
(`unsupported_characters`). Byte caps apply to the trimmed value. A postal code must also start and end with a letter or
digit. A value is printable only when all of the following hold:

- It contains none of these:
  - a format, private-use, unassigned or C1 codepoint (`\p{C}`: bidi overrides and isolates, zero-width characters, soft
    hyphen, BOM);
  - a combining mark (`\p{M}`);
  - a space other than U+0020;
  - a right-to-left script (Hebrew, Arabic, Syriac, Thaana, N'Ko, Samaritan, Mandaic);
  - U+2800 BRAILLE PATTERN BLANK, which draws as a visible-width blank;
  - U+FFFC or U+FFFD;
  - one of the 26 covered codepoints that NFC replaces, such as the Kelvin and Ångström signs and the Greek oxia forms.

  The pinned DejaVu faces draw some of these, but the printed text could then differ from what a reader sees or from the
  stored bytes, or be reordered or left unshaped.
- It contains at least one cased or other letter or decimal digit (`\p{Lu}\p{Ll}\p{Lt}\p{Lo}\p{Nd}`), so a field can't be
  only punctuation, symbols, a modifier letter such as U+02BC, or a number such as ½.
- Every codepoint has a positive-width glyph in every pinned face (`ContractGlyphCoverage`).

With the `test-v2` regular and bold faces, 5,562 codepoints have a positive-width glyph in both faces, and the rule accepts
4,899 of them. That count is pinned by a test. The accepted set covers Latin, Greek and Cyrillic and every pinned country
name; CJK is not covered.

**Checkout's set since 2026-11-08** is the `vasey-license-pdf-v2` faces instead (`ContractPrintableSet`): 359 codepoints
common to its four faces after the Latin and Common restriction, 357 accepted, pinned by a test. Latin Extended names such as
"Łukasz" and "Ștefan" print; Greek, Cyrillic and Vietnamese are refused.

Decomposed text is refused, not normalized, so the stored value is exactly what was sent; the checkout UI should send NFC.
The refused list is pinned rather than computed from normalization data. The category and script classes still come from
the runtime's PCRE Unicode tables, so a PHP upgrade that moves the pinned count must be reviewed before stored declarations
are re-verified with it. Before the gate opens, the `vasey-license-pdf-v1` profile must bind the coverage to its own pinned
faces, and its render review must confirm the accepted repertoire. That review should also check address layout, because a
buyer-entered line can itself name a country.

## Corrections

**An issued contract stands as signed** (Sean, 2026-11-07). If a buyer reports an error, support records a correction note
linked to the order. No superseding contract version is issued.

## Privacy, retention and data rights (before any collection)

- Encryption at rest follows the existing pattern: `Crypt`-sealed order evidence and encrypted grant copies. Projections keep
  exposing only `legal_name`.
- Retention: these fields follow the order and license financial-record period, and survive account closure, as the name
  does today.
- The customer data export includes production checkout records and their declarations (export schema 2,
  `ProductionCheckoutExportSection`):
  - It covers V1 reviews and orders and V2 tax checkout orders.
  - They are found through the account's production identity origin and read with plain, non-locking selects.
  - Each record is opened with its hash checked, and its sealed buyer binding must name the same origin. Otherwise the export
    refuses with `production_record_unavailable`.
  - The three tables are on the deletion `RETAINED` list under `financial_records_years`.
- Export schema 3 adds the exemption attestation and the paid-grant copies of the declarations:
  - `production_checkout_exemption_bases` joins `ProductionCheckoutExportSection`. Its buyer binding is sealed inside the
    qualification request. The qualifying staff member is not exported.
  - `PaidGrantExportSection` covers the V1 paid and V2 paid-tax chains. For each chain it exports the order origins, the grant
    origins that copy `buyer_declarations` verbatim, the manifests of the originals rendered from them, and the account's
    download history (authorization kind and times, redemption times). A test pins that a V1 paid copy of a v2 declaration
    (licensee type, legal name, postal address, statement) exports exactly as the grant origin froze it.
  - Paid orders and authorizations are found through `account_id` only; lines, originals and redemptions only through them.
  - Each payload is opened with its hash checked. The sealed original buyer must name the requester's origin and account, and
    every line must repeat it. Originals and authorizations must seal one of the account's exported lines.
  - Provider session, payment and account identifiers are deliberately not exported.
  - The ten paid tables (order origins, grant origins, originals, authorizations, redemptions per chain) and the bases are on
    the `RETAINED` list under `financial_records_years`. Authorizations hold `account_id`, the target and times (tax
    authorizations also `actor_id`); redemptions hold their `authorization_id` and creation time. Their `_retain` triggers keep
    both.
  - Document work and fulfillments hold only states and hashes, so they are neither exported nor listed.

  Deletion approval stays refused until U-13 retention values are approved.
- **Gap: production customers cannot reach data rights yet.** The export and deletion routes resolve only a test-account
  session (`CommerceRequestIdentity`, `_customer_access`). `DataRightsPolicy` is enabled only where test commerce is
  admitted. A production-signed-in customer therefore gets `sign_in_required`. Both account kinds use the same
  `customer_accounts` table, so the export document already covers their records. What is missing is an entry point that
  admits a production principal. That entry point is step 3b, and it must land before collection starts.
- `docs/legal/PRIVACY.md` and `TERMS.md` must disclose the new fields and the new assent text before collection starts.

## Sequence

Each step is a separately reviewed PR. The frozen-V1 guard stays green throughout.

1. This record.
2. Legal draft updates, for Sean's approval.
3. Data rights.
   - **3a:** export and retained-table coverage of production checkout records. Needs independent review and native MySQL
     evidence.
   - **3b:** a data-rights entry point for production-signed-in customers. It belongs with the production identity mount
     (U-07).
   - **Before activation (step 8), done:** the paid-grant copies of the declarations (`paid_*` and `paid_tax_*` origins and
     originals, local and testing only today) are exported and retained (export schema 3, `PaidGrantExportSection`).
   - **Follow-up, done:** `production_checkout_exemption_bases`, which is the buyer's exemption attestation and not a
     declaration, is exported and retained (export schema 3).
   - **Follow-up, out of scope:** test commerce's `RETAINED` entry `delivery_records` names no real table; the rows live in
     `test_delivery_authorizations` and `test_delivery_redemptions`.
4. A pure `ContractIdentityDeclarationV2` validator and formatter.
5. V2 tax checkout wiring behind the gate. Needs independent review and a native MySQL journey. Before activation (step 8):
   - the statement texts and the printable set must be rebound to the `vasey-license-pdf-v1` profile's own faces, and the
     accepted set must stay a subset of that renderer's own input rule, with a test. **Decided 2026-11-08:** rebind to a
     successor profile whose faces add Latin Extended (decision register, 2026-11-08). **Printable set done (2026-11-08):**
     `vasey-license-pdf-v2` is built and pinned, and both checkouts accept and re-verify against its faces
     (`ContractPrintableSet`: 357 accepted codepoints, Latin and Common; Greek, Cyrillic and Vietnamese refused). Tests pin
     the subset of the `test-v2` paid renderers' rule and of each v2 face. **v2 renderer built (2026-11-08, not selected):**
     `VaseyLicenseV2PdfRenderer`; a test pins that every codepoint checkout accepts is in the renderer's own input rule
     (`coverage()`), passes its per-face check in all four roles and renders. Open: the statement texts' review in that
     render, and wiring it into the issuers (separate review);
   - [x] the statement the buyer saw must be bound to the one frozen (done in step 6: the client echoes the displayed
     statement version, checked with `hash_equals`);
   - [x] gate-on tests must cover `initiate`, `reconcile` and the paid-tax grant render with a v2 declaration. (Step 6 added
     the other tests this item listed: a gate flip between preview and order and on replay, and a business order without a
     representative.) `initiate` and `reconcile` are covered (step 8, part); the paid-tax grant render is covered by
     `PaidTaxGrantJourneyTest::test_a_v2_contract_identity_order_pays_and_renders_through_the_paid_tax_grant`.
   - [x] `TaxCheckoutEvidence::order` checks that the body's declarations equal the request's (done in step 6).
6. V1 checkout wiring behind its own gate, with the statement binding and evidence checks above (done). Before activation
   (step 8):
   - [x] gate-on tests must cover `HostedCheckout::initiate` and `reconcile`, the V1 paid source and the paid grant render with a
     v2 declaration (step 8, part: `ProductionCheckoutContractIdentityTest`, `PaidGrantDocumentJourneyTest`);
   - [ ] `HostedCheckout` and the V1 paid source open orders through the frozen `OrderEvidence` without re-verifying v2
     declarations. `initiate` now re-verifies them (step 8); `reconcile` and the V1 paid source still do not. **Decided
     2026-11-08:** reconcile keeps recording the payment; paid-grant finalization re-verifies and holds fulfillment;
   - [x] the V1 HTTP review endpoint passes the `buyer` object through unchanged, and `GET production/checkout/contract-identity`
     (`production.checkout.contract-identity`, `ProductionCheckoutController::contractIdentity`) now serves
     `contractIdentityOffer()` through the same `run()` boundary as the other checkout routes: signed-in production customer,
     private headers, read throttle, and the private 503 while `http_enabled` or the gate is off
     (`ProductionCheckoutContractIdentityTest`, `ProductionCheckoutMountTest`).
7. The binder requirement, inside contract increments 3 and 4.
8. Activation, together with the profile, after steps 2, 3a and 3b merge and U-13 retention values are approved. Also
   required before activation (step 6 review, P3-2 and P3-3):
   - [x] V1 `HostedCheckout::initiate` re-verifies a v2 declaration before any money moves (done: in `prepare`, before the
     intent row and any provider call, through `ProductionCheckout::requirePayableIdentity`, which uses `verifyRetained`;
     a failure refuses with `changed`). The V2 tax checkout's `initiate` already re-verified it through
     `TaxCheckoutEvidence::order`.
   - [ ] For both checkouts, decide whether a re-verification failure at reconcile refuses, or records the paid order and
     escalates it (money captured but unreconciled). Reconcile is unchanged; a test pins that it records a valid v2 order.
     **Decided 2026-11-08:** record the payment, and refuse paid-grant finalization so fulfillment is held for staff.
     Implementation pending. Also decided: `initiate` refuses a v2 order while the gate is off; an already paid name-only
     order answers its status; open name-only sessions are expired before the gate opens (runbook).
   - [x] Name-only orders left unpaid: while the checkout's gate is on, `initiate` refuses an order frozen with the name-only
     declaration (`changed`), in both checkouts; with the gate off nothing changes. Still open: `reconcile` records a
     name-only order's payment with the gate on (a session opened before the flip can still be paid on the provider's
     page), so the activation runbook must still decide whether to expire those open sessions or drain them first. With the
     gate on, `initiate` on an already paid or bound name-only order now answers `changed` instead of its status; `status`
     is unaffected, and the runbook should say so for buyer support.
