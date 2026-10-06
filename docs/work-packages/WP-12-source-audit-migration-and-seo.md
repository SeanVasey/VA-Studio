# [WP-12] BeatStars source audit, restartable migration and SEO continuity

Status: **Implemented private SQLite draft-import child; actual source reconciliation and SEO acceptance remains open**. This issue is complete only when the acceptance evidence below exists.

- Suggested issue title: `[WP-12] BeatStars source audit, restartable migration and SEO continuity`
- Phase: Begin in 0; complete before replacement cutover
- Dependencies: Audit can start with WP-01. Import needs target records from WP-02/03/04/08/10/11 as applicable.
- Suggested branch: `work/wp-12-source-audit-migration-and-seo`
- Implementation paths: docs/research, docs/migration, app/Domain/Migration, import commands, redirect mappings and migration fixtures/tests.

## Problem

Replacing the public storefront must not lose catalog, agreements, customer access, memberships or established URLs.

## First reviewable increment

Authorized read-only source inventory and immutable manifest first; dry-run catalog import before any private historic-data import.

## Scope

- Account-specific feature/content/integration audit with evidence and confidence; inspect only authorized exports/UI, never undocumented private API dependencies.
- Hash/provenance manifests for media, metadata, license versions, original contracts, orders/grants, customers/consent, memberships and active obligations.
- Restartable dry-run/import batches with unique source mappings, conflicts, source/target counts and exact historical evidence.
- URL inventory, redirects, canonical metadata, sitemap, www/apex behavior and final source delta/freeze strategy.

## Acceptance criteria

- [ ] Parity ledger covers every verified source capability with target workflow, acceptance evidence and no unexplained omission.
- [ ] Media hashes, counts, currency totals, original contract bytes and entitlement/member balances reconcile or have explicit blocked exceptions.
- [ ] Rerunning an import is idempotent; conflicts never silently overwrite target or historical records.
- [ ] Unknown consent stays unknown; private exports/contracts/PII stay out of GitHub.
- [ ] Every old indexed/share URL has a tested target/redirect or an explicit retirement disposition; no redirect loops or draft exposure.

## Verification

Synthetic duplicate/restart/conflict fixtures, source-to-target reconciliation reports and URL crawl. Real private evidence stays protected and is summarized by counts/hashes only. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Retain source artifacts and import run IDs. Remove only unreferenced draft imports under a documented plan; never delete imported paid history blindly. Reconcile post-import writes.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-12 using the migration skill and evidence ledger. Begin with source truth and a read-only audit, keep private data out of commits, and prove dry-run/idempotency before import. Pull active obligations forward in the roadmap and document exactly what remains unverified.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.

## Protected bounded draft import — October 6, 2026

Reviewed runtime `546475654af4d9de33498e59b4f2fe10b40a6853` admits a closed
normalized source, captures a private signed review, applies bounded private
SQLite draft-metadata segments and preserves immutable source mappings/replay.
Primary-PDO schema/guard proof runs at entry and after callbacks; conflicting
SQLite replacement is refused. Author and independent PHP checks passed
86 / 314; native CLI checks passed 10/10 and independent schema/replacement
canaries passed 4 / 17. [Import evidence](../verification/persistent-catalog-draft-import.md)
and the [composition record](../verification/store-foundations-integration-20261006.md)
retain blocked predecessor and exact source limits.

This is a reviewed private staging importer with an explicit isolated SQLite
fixture in either full database matrix. It does not acquire actual account exports,
import paid history, publish tracks, issue licenses/entitlements or prove MySQL DDL.
T09/T34–T36 remain open for authorized actual source/count approval, media/history,
all product/obligation adapters, URL redirects and rehearsal on the chosen host.
