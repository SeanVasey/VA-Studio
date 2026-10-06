# Persistent private content onboarding

This bounded installation runs the real application at `http://127.0.0.1:8175` and retains authored content when stopped. It complements the disposable [private alpha](../private-alpha.md), whose synthetic practice database is deleted at shutdown. This path starts with an **empty catalog and no account, license, price, rights or purchase fixtures**.

It supports existing private track metadata, license/offer drafts, site content and private upload intake. Media processing, background workers, the scheduler, mail, customer enrollment and commerce remain disabled. An uploaded source is pending intake, not a processed or publishable asset. This local SQLite installation is not the selected production server, a MySQL concurrency result, a real-media acceptance result or a live store.

## Create and use a durable workspace

Use the supported Mac/Linux development environment, PHP 8.4+ with the repository's required extensions, Node 24.15+ and the locked Composer/npm dependencies. Build the storefront first. Pick an existing private parent directory that you own, outside the checkout. Its path must be absolute and canonical, with no symlinked components; its mode cannot allow group/world writes. The new workspace must not already exist. Do not choose the operating system's disposable temporary directory for your actual content.

```sh
composer install --no-interaction --prefer-dist --no-scripts
npm ci --ignore-scripts
npm run build
# Substitute your own absolute durable directory; do not put real files in Git.
node scripts/dev/persistent-content.mjs init --directory /your/private-parent/catalog
node scripts/dev/persistent-content.mjs operator --directory /your/private-parent/catalog
node scripts/dev/persistent-content.mjs start --directory /your/private-parent/catalog
```

The operator command invokes the actual audited `vasey:create-admin` through the trusted console. It asks for the operator's name, email and a hidden password of at least 16 characters. No default credential is created, printed or retained in the installation manifest. Sign in at `/admin` with that separately provisioned operator. More trusted operators can be created through the same command while the server is stopped; no public registration endpoint is added.

Keep the terminal open. **Ctrl+C stops the owned server and preserves the database, encryption key, sessions and private files.** Restart with the same `start` command. A running workspace holds an OS file lease; a second server or operator command refuses without changing it. Port 8175 must be free. The launcher never reuses or stops an unrelated listener.

Create and save your private drafts using existing admin actions. Real metadata values, original files, rights/provenance and reviewed terms come from you. Keep originals separately and make a protected backup of the stopped workspace, including `identity.json`, `database.sqlite` and `app/private`; the identity contains its retained encryption key. A lost key can make encrypted records and sessions unreadable. Backups contain private material and must not enter Git, CI artifacts or public storage. Automated encrypted backup/restore and production storage/worker setup remain separate operations work.

## Refusal and recovery boundaries

The launcher isolates all application/provider/PHP/proxy settings from the caller and ignores the checkout's `.env` and configuration caches. It explicitly verifies effective database, disk, session, cache, URL and disabled service configuration before use. Workspace directories have mode 0700; identity, database and lease have mode 0600. Owner-only framework cache files may also have owner-executable mode. Init, resume and the PHP guard recheck every ancestor: group/world-writable ancestors are refused except root-owned sticky system directories; the immediate parent must still be owned and protected. Linked paths, private child symlinks, exposed files, hard links, replacement directory identities and unexpected caches are refused.

Initialization is explicit and creates a fresh durable directory. If it is interrupted or fails, that directory is retained in `initializing` state and cannot serve. Re-running `init` never overwrites it. Inspect and preserve the failed directory and any desired files before selecting a different new directory; no cleanup, reset or repair is automatic. Do not turn an incomplete installation into a ready one by editing its manifest.

Starting is read-only setup: it does not run migrations, seed data, create/reset accounts, republish assets or generate another key. The retained identity binds this exact checkout, directory device/inode, migration source, Composer lock and storefront manifest. A moved directory, changed schema/dependency/build or incompatible installation refuses and requires a separately reviewed upgrade/restore path. This increment does not implement such an upgrade or restore by silently rewriting the identity.

The PHP router accepts only the fixed loopback host and denies direct PHP, dot files, storage paths and files escaping the two public asset roots. Every request proves that the owning OS lease is still active; an orphan server refuses even when its old token remains on disk. Do not expose this local development server through a tunnel or reverse proxy. Production HTTPS, required MFA, actual scanner/tag/media worker isolation, transactional mail, backup restore and the selected commerce/provider policies are still required for the applicable later milestones.

## Focused verification

```sh
PERSISTENT_CONTENT_REQUIRE_PHP=1 node --test scripts/dev/persistent-content.test.mjs
php vendor/bin/pint --test scripts/dev/persistent-content-bootstrap.php
```

The 17-case subprocess suite executes actual PHP/SQLite migrations, the audited hidden-input operator command and Laravel HTTP. It creates a private draft through the actual signed Livewire transport, stops/restarts the server three times, continues the authenticated session, verifies original full track/audit rows, and compares database/key/private-byte hashes before editing again. It also proves an empty initial catalog, disabled checkout, guest/CSRF/foreign-host and private-file boundaries, generated admin asset delivery, simultaneous-operation refusal, genuine lease-process death, unsafe ancestor/configuration/path/cache/symlink/hard-link refusal and retained failed initialization.

The first native suite passed 12 of 14 cases; its failures were incorrect test expectations for the POST-only checkout route and the intentionally readable CSRF cookie. The corrected predecessor suite passed all 15 without skips. Earlier manual probes exposed owner-executable framework asset/cache/view permissions; initialization now tightens fresh published assets while resume permits private owner-only compiled views. Independent review identified the original build-root symlink issue and a missing ancestor permission check; the added refusals cover the original build root/manifest and unsafe parents/ancestors before reads or writes. The local runtime's Composer metadata was then isolated to this worktree so application classes resolve to the frozen tested source. All earlier results and the predecessor source-binding limitation remain in the external evidence packet. Final results belong to the integrating PR's actual tested commit.

Without a supported native runtime, the suite explicitly skips native cases unless `PERSISTENT_CONTENT_REQUIRE_PHP=1`; skipped cases are not runtime evidence. These checks exercise HTTP protocols, not a rendered browser. Independent tested-source review, integrated focused checks and final consolidated verification belong to the integrating candidate. No hosted or full database/browser matrix was dispatched for this increment.
