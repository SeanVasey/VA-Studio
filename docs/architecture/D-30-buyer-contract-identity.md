# D-30 — Buyer contract identity: legal name, postal address and Exclusive artist name

Status: decided design, 2026-11-07. Nothing is implemented or collected yet. It implements increment 2 of the
[production contract profile](../production-contract-profile.md). It records Sean's decisions C-1, C-2 and D-1 from
2026-11-07, plus his follow-up decisions below: corrections, unsupported characters, and business licensees.

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
- **Paths.** V2 `ProductionTaxCheckout` is the primary target, then V1 `ProductionCheckout::review`. Both are unmounted
  today. Exclusive has no production path yet (`ExclusiveInventoryGate`), so the artist name is reachable only on the test
  path until a production Exclusive consumer exists.
- **Gate.** Capture sits behind a literal-false `contract_identity_v2` gate. The gate opens together with the
  `vasey-license-pdf-v1` profile, because the current paid renderers print every input field.

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
- The customer data export must include production checkout orders and their declarations, which it does not today.
  Deletion approval stays refused until U-13 retention values are approved.
- `docs/legal/PRIVACY.md` and `TERMS.md` must disclose the new fields and the new assent text before collection starts.

## Sequence

Each step is a separately reviewed PR. The frozen-V1 guard stays green throughout.

1. This record.
2. Legal draft updates, for Sean's approval.
3. Data-rights export and retained-table coverage. Needs independent review and native MySQL evidence.
4. A pure `ContractIdentityDeclarationV2` validator and formatter.
5. V2 tax checkout wiring behind the gate. Needs independent review and a native MySQL journey.
6. V1 checkout wiring.
7. The binder requirement, inside contract increments 3 and 4.
8. Activation, together with the profile, after steps 2–3 merge and U-13 retention values are approved.
