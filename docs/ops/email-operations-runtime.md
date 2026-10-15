# B5 email preparation facts

`python3 scripts/ops/email-operations-preflight.py` validates a closed, bounded JSON file of **operator declarations**. It never loads Laravel/effective config, connects to DNS/SMTP/database/providers, submits mail, runs a reference or installs a worker/schedule. Every report keeps `host_verified`, `provider_authenticated`, `activation_ready` and `deployment_ready` false. `DECLARED_FACTS_VALID` means internally coherent declared configuration, not observed installation or delivery.

The blank [template](../../ops/email-operations/facts.example.json) has no credentials or invented sender/provider/retention decisions. Verify its format with:

```sh
python3 scripts/ops/email-operations-preflight.py --template
```

This yields `TEMPLATE_VALID`; copying the same blank template into operator mode yields `BLOCKED`/exit1. Populate only nonsecret facts and opaque references on the intended installation, then inspect its absolute canonical regular file:

```sh
python3 scripts/ops/email-operations-preflight.py --facts /srv/va-studio/config/email-facts.json
```

That path is illustrative and was not accessed here. Files are limited to32KiB, one hard link, no symlink component, owner-readable0400/0600 or trusted-group-readable0440/0640. Changed descriptor/path metadata is refused. Owner identity, parent directories, trusted groups and ACLs remain operator obligations; this is not hostile-parent atomicity or host verification. Output contains fixed checks only, never input values, paths or parser exceptions. JSON rejects duplicate/escaped duplicate keys, arrays, unexpected fields, nonfinite numbers, oversized/deep structures and control/surrogate strings. Do not put passwords, keys, full recipients, raw provider payloads or private evidence into the facts file. Credentials are entered only in the host's protected secret mechanism.

The schema is exactly the template. `candidate_sha` is a40-character lowercase shape; the checker does not resolve or authenticate that release. Planned identity SMTP records provider/account scope, endpoint/port, `implicit_tls` or `starttls`, sender, EHLO domain, UUIDv4 revision and host-secret/sender-review references. Ports1..65535 match the existing capsule; there is no conventional-port-only or sender/public-origin same-domain claim. The planning format additionally requires canonical lowercase HTTPS/domain origin. No reference is dereferenced, no secret commitment or capability is derived, and syntax never proves sender ownership, DNS records, TLS or provider/account authentication. Suppression has a separate planned provider/account/adapter review and regrant/unknown-outcome references; it cannot be inferred from identity SMTP.

The baseline is deliberately literal-disabled/unbound: identity, notifications, SMTP settings, suppression and provider flags false, generic Laravel Mail `log`. These are declared facts; actual shipped config remains unchanged. A populated plan does not bind `production-identity-smtp.settings`, enable any parent or satisfy the reviewed provider conditions. Suppression254's native activation C1, historical-withdrawal/regrant R2 and unknown operator R9 remain open until their own reviewed acceptance.

Identity notices have a one-attempt `DeliverProductionIdentityNotice` ShouldQueue wake-up with noticeId-only payload and afterCommit dispatch. `WorkIdentityNotice` governs retained attempts and submission; queue success never proves SMTP acceptance or delivery. `customers:process-identity-notices --limit=50` scans retained work separately: default50, allowed1..100. Existing durable lease120seconds, challengeTTL600seconds, maximum3 attempts and30×attempt-number retry delay apply only to `definitely_not_submitted`. Accepted, unknown and blocked outcomes are terminal for automatic submission; inspect/reconcile unknown outcomes without resending. SMTP socket timeout5seconds is per socket operation, not a whole-send or interruption guarantee.

Queue facts require planned database connection, one worker try, worker timeout1..900 and retry_after greater than both its timeout and the existing shared media960second lease floor. This planned shared installation baseline is separate from the identity120second durable lease and SMTP socket timing. Observe actual worker limits, leases, stalled work and wake-up recovery on the selected host; the checker does not prove their interaction or bounded wall time.

Only scheduled site-release publication is currently registered in `routes/console.php`. Identity recovery schedule, worker supervision and scheduler-observation references describe the operator's plan; no identity/suppression schedule is installed by this batch. Prove heartbeat, overlap behavior, missed-wake-up recovery and whole-backlog progress rather than repeatedly processing only a first page. Do not use queue retry as a suppression resend policy.

Retained identity and suppression evidence stays append-only. The facts require append-only true and purge false; policy/log-retention references must be explicit in operator mode, but existence or approval is unverified. No deletion schedule, retention period, delivery destination, provider or legal policy is invented. Protected logs, necessary private evidence, restore and unknown-outcome access require reviewed host procedures.

Sean still needs to select the real sender/domain/provider, account scopes, approved retention and operator references. Real credentials stay host-only. Sender SPF/DKIM/DMARC, TLS peer/authentication, real acceptance/delivery, web/worker/cache configuration, native timing, provider compensation/regrant semantics, supervisor/scheduler/retention/restore and activation/release evidence remain outside this checker. Use the report's fixed `unverified` list; no synthetic fixture result substitutes for those observations.
