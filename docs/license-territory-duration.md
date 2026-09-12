# License territory and duration

WP-04 schema v3 adds two explicit scope decisions to the existing usage, permission, credit and deliverable model. No territory or duration mode is preselected. This is a bounded authoring/review increment; actual production policy remains U-05, and grant-time calculation and enforcement remain WP-08.

## Author and review

1. New license drafts use schema v3. Complete the existing usage fields, choose **Worldwide** or **Selected countries**, then choose **Perpetual from grant** or **Calendar months from grant**. A fixed duration requires 1–1,200 whole months. These limits describe software capability, not recommended production terms.
2. Include `{{territory}}` and `{{duration}}` in the source alongside the thirteen [v2 variables](typed-license-terms.md). Each substitutes the complete generated statement, including its label. All fifteen source values and license-card statements derive from the same model.
3. Preview and compare the original source, substitutions, card statements and variable table. A separate reviewer must assess the surrounding source and actual rights. Submit, approve and publish through the existing immutable lifecycle, then publish the commercial offer separately.

For a v2 version, **Define license scope** creates a linked v3 draft carrying its source, existing typed values and availability dates. Choose both new modes and insert their variables deliberately. The original remains unchanged. For v1, use **Define usage rights** first to map its prose into v2 choices, then define scope. **New revision** always retains its predecessor's schema.

## Exact contract

Schema v3 contains exactly the five v2 roots plus `territory` and `duration`. Existing usage/credit/deliverable validation and statement order remain pinned to v2. Country lists retain supplied order in the stored model; rendering sorts their codes without modifying the original.

| Field | Exact supported shape | Generated statement |
| --- | --- | --- |
| `territory` | `{mode: "worldwide"}` | `Territory: worldwide.` |
| `territory` | `{mode: "countries", country_codes: ["US", "CA"]}` | `Territory: CA, US (ISO 3166-1 alpha-2).` |
| `duration` | `{mode: "perpetual", starts_at: "grant"}` | `License duration: perpetual from the grant timestamp.` |
| `duration` | `{mode: "fixed_months", starts_at: "grant", months: 120}` | `License duration: 120 calendar months from the grant timestamp (UTC; end-of-month clamping).` |

Country codes must be nonempty, distinct uppercase members of the pinned vocabulary. Unknown, reserved, lower-case, subdivision, regional group and alias codes are unsupported. Worldwide cannot carry an unused list. Fixed months must be integers; numeric strings, booleans, fractions, zero and values above 1,200 fail domain validation. The form validates and converts whole-number text. Perpetual cannot carry unused months. Only the grant anchor is supported; publication, availability and quote timestamps cannot substitute for it.

Calendar months mean an offset from the **original grant timestamp in UTC**, with the day clamped to the last day of the target month when absent. Thus a January 31 anchor plus one month ends on February's final day; plus two months ends on March 31, not a date calculated by repeatedly adding one month. WP-08 must implement and verify end-exclusive timestamp calculation, leap years and end-of-month behavior against this frozen input. This increment creates no grant, calculated expiry or entitlement transition.

Territory describes permitted use, not billing country, residency or a buyer's network location. No checkout geolocation restriction or monitoring is inferred. Exclusions, subdivisions, durations anchored to release/signature, renewal and termination effects need separately modeled and reviewed semantics. Perpetual duration does not silently decide breach or termination policy.

## Pinned vocabulary and evidence

`LicenseTerritories::COUNTRIES` captures 249 alpha-2/name pairs from the [pycountry dataset at commit 4c6a699](https://github.com/pycountry/pycountry/blob/4c6a69927a25326a69bf753671fb571bb437cd7d/src/pycountry/databases/iso3166-1.json), inspected 2026-09-11. Names are editor aids; review/card statements use codes. The [ISO overview](https://www.iso.org/iso-3166-country-codes.html) explains alpha-2 versus subdivisions and reserved/user-assigned codes. This captured vocabulary is not a live policy or location service. Changes require new schema vocabulary while retaining old validation and rendering; runtime locale/data updates must not alter historical hashes.

`vasey-license-review-html-v3` pins the new review output. V1 and v2 validators/renderers remain supported, including their original wording and bytes. A static v2 golden fixture is added alongside the existing v1 fixture. Published source, model, review evidence, offers and provisional quotes remain immutable.

## Upgrade and recovery

Apply `2026_09_11_000011_license_scope_terms.php` with compatible code. It installs `license_review_state_guard_v3` before removing v2, accepts exact schema/renderer pairs 1/1, 2/2 and 3/3, and rewrites no stored rows. Content, approval and deletion guards remain installed. A partially applied upgrade retains enforcement; inspect migration state before repairing, without removing the sole active guard.

Retained v3 evidence requires v3-aware validators and renderer. Migration down restores the v2 guard without rewriting/deleting records and is suitable only for disposable or verified-compatible environments. For recovery with retained v3 versions, keep compatible code and evidence; deactivate affected offers if needed. Never coerce published terms into an older schema.

See [verification](verification/license-scope.md) and the [ordered development plan](development-order.md). Next are ownership/publishing/royalty concepts and versioned policy references, followed by WP-05 detail/device work. Buyer-specific contracts and fulfillment remain WP-08.

## Later authoring schema

New drafts use [schema v4 economic policies](license-economic-policies.md), which retains these exact territory/duration rules. This page documents pinned v3 behavior and deliberate v2-to-v3 mapping. Existing v3 versions, their previews and ordinary successors remain v3; use **Define economic policies** for an explicit v4 successor.
