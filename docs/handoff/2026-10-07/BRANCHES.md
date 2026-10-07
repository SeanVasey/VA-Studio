# Frozen branches and next integration

All six worker lanes are idle. Root independently read back all42 published worker
refs; [remote-branches.json](remote-branches.json) records their exact heads. Earlier
publication receipts remain unchanged point-in-time evidence. The final integration
branch is `codex/cloud-primary-development-20261007`; its checkpoint PR/main merge
contains this document, reviewed suppression251 and the corrected identity cleanup.
Read current `origin/main` and the final publication entry in
`docs/development-order.md` before continuing.

| Work | Exact source / frozen remote branch | Decision and next step |
| --- | --- | --- |
| Reviewed current integration | Support37/main899df195; identity/SMTP/history38/mainaff98741; suppression983b497; cleanup5053af53 approved09c57697 | Current checkpoint publishes the finished reviewed components and all handoff packets. Full final acceptance remains outstanding. |
| Fresh checkout writes | Sourcec6d8234533b00bc741925c2028befdb7b455ea6d; `codex/checkout-original-write-commit-admission-20261007` | Author SQLite17/129 and native7/50 pass. Independently review and compose with the approved receipt branch. Direct privileged commit/reopen durable-order limitation is retained. |
| Paid source receipts | Source2ec1854c2b3606250e59b157f06703151318af0a; `codex/checkout-receipt-plain-parents-20261007`; review `codex/checkout-receipt-independent-20261007` | Whole contract approvedc0cc. Preserve its Records/CommandTransaction changes when combining checkoutc6, original identity/deadline and consumer admission. This does not approve consumers or fresh writes. |
| Paid252 | Runtimee8a2b93/813a8f7; portable checkpoint972a76c5e04da0f61d409d6caf9eb84281fef2a3 on `codex/paid-grant-fixture-portability-20261007` | Portable69-file source dependency is Git-tracked. Recovery passesSQLite1/35/native1/36; native delivery remains409 at OriginalCommitDispatcher95 during second redeem receipt mint. Diagnose the phase/deadline/dispatcher predicate; preserve lifetimes. |
| Account features253 | Source3cecea697ccaaff74664acf9d48bcaecfa8bf288/runtime9d0a5a27; `customer-production-account-features-t32` | Author SQLite83passes/766assertions+3native-onlyskips; final native1/6. Earlierb6 native9/109 is separately attributed. Independent review and canonical config/registration remain missing.254/email operations are queued. |
| Membership257 | Source3cd105667a76bb15443a9fd0119065effda8bb34; `codex/production-membership-preparation-20261007` | Earlier16e preparation approved8fd; newerRows default-statement guard author-tested21/58+native1/3 is unreviewed. Operative invoice/period/award/reserve flows and races remain missing. |
| Member258 | Sourcec4cd6d9355257a39d2740b0a4fe55c3e376ef5b3; `codex/production-member-original-preparation-20261007` | Author preparationSQLite21/52+native5/17. Independent review and operative original artifact/activation/HTTP fulfilment remain missing. |
| Billing259 and host operations | `codex/membership-billing-operations-handoff-20261007` atdafdc6f0dade503f718ace45ecf56d915d43a569 | Plans only. Build authoritative subscription/invoice/payment observations and private server/storage/scanner/queue/scheduler/backup/restore/rollback preparation. |
| Free256 and content | `codex/production-free-family-20261007` at5bfdd4f00dd4e1c2d989625a810a81ddf4bad152; free identityff0 approvedfbd | No operative256 migration/runtime/grant/admin/HTTP/PDF/assets exists. Metadata observations and identity component proofs are preparation. Implement the distinct family, then content/license migration dry runs. |
| Tax255/live payment | Checkout owner packet and preparation queue | Queued only. Authoritative tax/provider configuration and activation packets remain implementation work with real facts unbound. |

For an exact remote branch checkout in a new worktree:

```bash
git status --short
git fetch origin '+refs/heads/*:refs/remotes/origin/*'
git worktree add -b harness/claude-continuation ../VA-Studio-next origin/main
git show EXACT_COMMIT:PATH_TO_EVIDENCE
```

Choose your own writable worktree path and task branch. Never prune or overwrite
old worker source. Some author branches deliberately retain older borrowed files;
select their owned source commits and explicitly resolve shared producer/identity
changes. Archived original canaries and source maps must stay byte-identical.
