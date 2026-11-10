# Sean's queue — remaining actions and held authorizations (2026-11-10)

Companion to "Register update — 2026-11-10 (Sean): waiting-on-Sean queue decisions" in
`docs/architecture/decision-register.md`. Part B lists only what Sean must physically do or supply, in unblock order.
Part C lists the authorizations that stay unissued until their gate. Secrets are entered on hosts or in Sean's password
manager only, never in chat, Git or an agent session.

## Part B — What only Sean can do (in unblock order)

1. **SQ-01 staging step 11** (about 30 minutes)
   - Pick a second mailbox you control for the reviewer account (a separate address or alias on vasey.audio).
     The session runs `vasey:create-admin` twice with you on SSH; you enroll each account in your authenticator app
     as a separate entry and store both sets of recovery codes offline.
   - Backblaze: create an account, a private bucket `vasey-staging-backups`, a lifecycle rule (keep prior versions
     90 days), and an application key limited to that bucket **without deleteFiles**. Enter the key on the host with
     `rclone config` during the session — never in chat or Git.
   - On your Mac: `brew install age`, then `age-keygen -o ~/vasey-backup-primary.txt` and
     `age-keygen -o ~/vasey-backup-recovery.txt`. Move both files offline (encrypted USB + password manager). Send only
     the two `age1…` public lines.
   - Say "go" on reprovisioning the staging host.
2. **SQ-02 Stripe test keys** (10 minutes): Stripe Dashboard → Vasey Multimedia account → **Test mode** →
   Developers → API keys: copy the `sk_test_…` secret key (standard, not restricted) and the account ID (`acct_…`).
   Developers → Webhooks → Add endpoint `https://staging.vasey.audio/webhooks/stripe`, events
   `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`,
   `checkout.session.async_payment_failed`; copy its `whsec_…`. Paste all three into Forge → staging site →
   Environment only, then Deploy Now.
3. **SQ-03 BeatStars Studio audit:** only you can sign in. Either do the A-001..A-014 walkthrough yourself or open
   BeatStars Studio in Chrome and have Claude drive the read-only walkthrough with your session. Export orders,
   customers, memberships, payments and buyer contracts into private storage (not the repo). This also answers PayPal
   and legacy members.
4. **SQ-05 prices:** five numbers in USD — Basic, Premium, Trackout, Unlimited, Exclusive. Also say which tracks, if
   any, should never offer an exclusive. (See open question Q-3 on tier names.)
5. **SQ-07 legal placeholders:** postal city and ZIP (stays out of the public repo), your Iowa county for venue,
   and register the DMCA agent at the Copyright Office's DMCA designated-agent directory. Book a counsel review of
   the [For legal review] wording and the no-samples warranty (and, if guest checkout stands, the TERMS §3 amendment:
   open question Q-1).
6. **SQ-06 accountant check:** confirm Iowa registration and the treatment of digital-download licenses before the
   first live charge.
7. **SQ-08 DNS records:** add the Postmark and DMARC records once Postmark gives them (or delegate DNS edits to the
   session, see 9).
8. **SQ-14:** GitHub → Security → Dependabot → the four alerts under
   `docs/verification/member-source-currentness-20261013/` → Dismiss → "Vulnerable code is not actually used".
9. **SQ-17 DNS control:** tell the session where `vasey.audio` DNS is hosted (registrar or Cloudflare) and whether
   the session may prepare record changes for you to apply.
10. **SQ-16 logo:** supply the official VASEY.AUDIO logo SVG and icon source files (not recreated).
11. **Open questions Q-1 to Q-3** in the register update: guest checkout vs TERMS §3, kit license terms, canonical tier
    names.

## Part C — Authorizations held until their gate (SQ-20)

Each stays a separate, explicit "yes" given at the moment its gate is reached, in this order:

1. Media delete path (after P2-1..P2-5 land and review passes).
2. Retention purge (after a purge is built and reviewed).
3. Production deploy.
4. Live Stripe keys and the first live charge.
5. Expiring open provider sessions at D-30 activation.
6. Import into active entitlements.
7. DNS cutover.
8. BeatStars retirement (only after the delta is captured and rollback window has passed).

Nothing in the register update grants any of these.
