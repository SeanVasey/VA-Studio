# Security and data handling

This repository contains an initial development foundation. It is not approved for live commerce or customer-data migration.

Report security findings privately to the repository owner. Do not put exploit credentials, customer records, payment data, private audio, or production logs in public issues. GitHub private vulnerability reporting should be enabled once the repository exists.

No credentials are committed or seeded. Copy `.env.example` locally, generate the application key, and create an operator using the documented command. Production must use HTTPS, secure cookies, real secret storage, an audited staff-role workflow and mandatory admin MFA before exposure.

The foundation deliberately refuses checkout. The later commerce implementation must verify signatures against raw provider bodies, durably deduplicate events, reconcile captured state, lock exclusive inventory, snapshot license/asset evidence, issue entitlements exactly once, and authorize every download.

Keep uploads quarantined until decoded, scanned and validated. Never use browser-supplied paths or MIME types as authority. Separate public tagged previews and artwork from private masters, stems and contracts.

Run dependency audits with lockfiles installed. Record advisory remediation and rollout impacts; do not disable audit gates without an explicit, evidence-backed decision.
