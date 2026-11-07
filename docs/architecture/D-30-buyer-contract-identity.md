# D-30 — Buyer contract identity: legal name, postal address and Exclusive artist name

Status: decided design, 2026-11-07. Nothing is implemented or collected yet. It implements increment 2 of the
[production contract profile](../production-contract-profile.md). It records Sean's decisions C-1, C-2 and D-1 from
2026-11-07, plus his two follow-up decisions below.

## Decision

Production checkout freezes a versioned **contract identity declaration** inside the existing `buyer_declarations`. It does
not use a separate record.

```
{schema: 'buyer-contract-identity-v2', legal_name, legal_name_confirmation: {confirmed: true, statement_version, statement_hash},
 postal_address: {country, lines[1..3], locality?, region?, postalCode?}, artist_name | null,
 identity_meaning: 'buyer_declared_contract_identity'}
```

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
| Legal name | Current rules: ≤255 bytes, valid UTF-8, no C0/DEL, trimmed. Also refuses bidi overrides (U+202A–E, U+2066–9), and requires `legalNameConfirmed === true` against a versioned statement. |
| Postal address | Structured: `country` is a required ISO 3166-1 alpha-2 code from a pinned list; `lines` holds 1–3 lines (the first required) of ≤120 bytes each; `locality` and `region` are each ≤80 bytes; `postalCode` is ≤20 bytes of `[A-Za-z0-9 -]`; the whole canonical address is ≤512 bytes. Never inferred from Stripe billing or account data. |
| Artist name | ≤120 bytes, same character rules. Required if and only if a line's license is `exclusive`. |

`{CUSTOMER_ADDRESS}` is one deterministic string, joined with ", ": the lines, then the locality, region and postal code,
then the country's English short name. **Characters the pinned contract fonts cannot print are refused at checkout** (Sean,
2026-11-07). The buyer is asked to enter supported characters.

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
