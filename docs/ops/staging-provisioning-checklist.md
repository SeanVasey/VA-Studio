# Checklist for Sean: unblock the hosted Stripe-test staging rehearsal

What only Sean can do to get the private staging site (`ops/staging/`) running on Laravel Forge and DigitalOcean. Tick the boxes
in order; the full steps are in [`ops/staging/README.md`](../../ops/staging/README.md#provisioning-step-by-step) and day-to-day
operation is in [`staging-runbook.md`](staging-runbook.md). The IDs are those of
[`MONDAY-PLAN.md` section 4](../handoff/2026-10-08/MONDAY-PLAN.md#4-inputs-only-sean-can-supply-in-the-order-needed).

**What this involves, and what it does not.** Stripe **test** keys only. No live key, no live payment, no production host, and
**no change to the apex or `www` DNS records**: the only record is one for the staging subdomain. Type no secret into chat,
an issue or the repository; enter each one only in Forge or on the server, as noted.

## 1. Forge and DigitalOcean (S-2)

- [ ] Create a Forge account.
- [ ] In Forge, link your DigitalOcean account. Create the DigitalOcean API token in DigitalOcean and **enter it only in
  Forge**.
- [ ] Create the server **explicitly**; do not accept Forge's defaults, which may now be newer than the kit targets (reported:
  Ubuntu 26.04 and PHP 8.5):
  - type **App server**, provider DigitalOcean;
  - **Ubuntu 24.04 LTS**, **PHP 8.4**, database **MySQL 8.4**;
  - size **4 vCPU / 8 GB RAM / 160 GB disk** (4 GB is the absolute minimum; the 160 GB is for masters and stems);
  - save the sudo and database passwords Forge shows once, in your password manager;
  - leave Forge's firewall at ports 22, 80 and 443.
- [ ] Give the deploy operator SSH access (add their public key in Forge).

## 2. The staging hostname (S-3)

- [ ] Choose the hostname, for example `staging.<your-domain>`. It must be a subdomain.
- [ ] In your DNS provider add an `A` record, and an `AAAA` record if the server has an IPv6 address, for **that subdomain only**,
  pointing at the server's IP. Do not touch the apex or `www`.

## 3. Site and deploy settings (follow the README steps 3 to 9)

- [ ] Create the Forge site for the hostname: Laravel, PHP 8.4, **no** Forge-created database.
- [ ] Install the repository `SeanVasey/VA-Studio` on the branch that carries the SHA to stage. **Untick "Install Composer
  dependencies".** Leave **Quick Deploy off**: deploys are deliberate.
- [ ] Get the Let's Encrypt certificate in Forge for the hostname, before the operator pastes the nginx template.
- [ ] Choose the basic-auth user and password (16+ characters, no spaces) when the operator runs `provision.sh`. They protect
  everything except the Stripe webhook.

## 4. Secrets: Forge or the server only (S-4)

- [ ] `APP_KEY`: generated **on the server** once, with the command in README step 8. Keep a copy in your password manager;
  encrypted data and every backup depend on this exact key.
- [ ] `DB_PASSWORD`: the operator reads it from `/root/vasey-staging-secrets/db-app.env` on the server and pastes it into Forge's
  Environment. It is not in the repository.
- [ ] Stripe **test** values, taken from the Stripe Dashboard in test mode and entered in Forge's Environment only: the account ID
  (`acct_`), the secret key (`sk_test_`) and the webhook signing secret (`whsec_`), or a Stripe CLI login done on the server.
  A live-mode key (`sk_live_`, `rk_live_` or `pk_live_`) is refused by the deploy and must never be used here. A restricted key
  (`rk_test_`) is not refused by the deploy but the test checkout does not accept it: it needs a standard `sk_test_` key, and
  `scripts/ops/validate-test-commerce-profile.php` fails a restricted one, so use the standard test key.

## 5. Off-host backup destination (S-10)

- [ ] Choose a destination that **accepts rsync over SSH** (another server or a storage service with SSH and rsync) and that
  you control. Create an account there for backups and give the operator its `user@host:/path`; the operator generates the SSH
  key on the staging server and sends you only the public half to authorize.
- [ ] Decide how it is encrypted: encrypted at rest by the destination, or client-side with age before the copy leaves
  (`docs/ops/backup-offhost-hardening.md`). For age, generate a key pair on **your own machine**, keep the private key offline,
  and give the operator only the public key (`age1...`).
- [ ] Decide retention there; the kit does not choose it for you.

## 6. Content and approvals for the rehearsal (S-5 to S-8)

- [ ] **S-5:** a second staff person or account. License approval must come from a different account than the contributor.
- [ ] **S-6:** the seller preview tag WAV (nothing can publish without it). It stays in private storage and out of Git; its
  SHA-256 goes in the Environment.
- [ ] **S-7:** 3 to 10 tracks: WAV masters (up to 200 MiB each), artwork (up to 20 MiB each), optional stems as one ZIP per
  track (up to 200 MiB), plus metadata and rights references.
- [ ] **S-8:** license tiers, terms text and USD prices, the seller's legal name, and the buyer assent text for the test order
  policy.

## Done when

The operator reports the first deploy finished with `vasey:doctor` failing at most on `operator`, the backup log says
`shipped`, and you can sign in behind basic auth. The first Stripe test purchase is the next step in the runbook, and it is
still test mode.
