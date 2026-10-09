# Independent public-link and backup-credential review

**Decision: APPROVE exact functional source
`b72c8efcb48ea433c58659b824ad32d4c174bf6d` for the bounded development change.**
Codex findings 4226801376 and 4226801382 are resolved. No unresolved material
finding remains in this assessed delta; actual Forge acceptance remains open.

The sealer and read-only verifier both reject any link resolving to the protected
release `.env`. Links in the public tree, including the top-level `public` directory,
must resolve within that tree. Resolved chains cannot hide the final target.
Ordinary public asset/directory aliases and nonpublic internal code aliases remain
admitted. This prevents static-friendly aliases to private release files from being
accepted; it does not depend on nginx's dotfile-name rule.

Backup provisioning now requires a finite regular file owned by root, mode 0600,
with one link, the exact five generated literal lines and an alphanumeric password. It neither
sources the file nor repairs unsafe custody by changing its permissions. Missing or
malformed custody refuses before backup grants. An absent account cannot overwrite an
orphan file or follow its dangling link; generation is checked before account mutation
and persistence is no-clobber. Authentication uses a scrubbed environment, one admitted
defaults file, disabled MySQL login paths, TCP and a bounded connection timeout. An
exact `CURRENT_USER()` identity is required before grants. Stale credentials require
private recovery or explicit account rotation, not automatic replacement.

## Independent evidence

```sh
python3 docs/verification/staging-ops-20261009/independent-review/public-backup-probe.py --source-sha b72c8efcb48ea433c58659b824ad32d4c174bf6d
```

[Final receipt](public-backup-final.txt): **5 methods PASS**, exit 0. Actual sealer
functions reject 12 link cases across seal and verify, including `.env` chains,
private files/directories and top-level public aliasing; positive public aliases
remain readable. Actual provision-block probes cover private file bytes/modes/links,
bad authentication and identity, missing-account orphan custody and invalid generation.
Root ownership and database/client outcomes in this selection are modeled, with
real owned filesystem behavior retained.

[Old-source red](public-backup-red.txt), at exact `9e6b7fd5`: **5 methods, 32 failing
states**, exit 1. These comprise 12 admitted unsafe links and 20 custody/authentication/
creation failures, including grants before any authentication even for a valid file.
[First current harness attempt](public-backup-harness-attempt1.txt) retains one failure
and three errors caused by my fixture expecting `/usr/bin:/bin` instead of the source's
explicit `/usr/local/bin:/usr/bin:/bin` client PATH. Correcting that fixture produced
the final green; it is not a product regression. Only trailing progress whitespace
and empty assertion-message suffix spaces in these new receipts are normalized.

The requested genuine client proof used:

```sh
source /workspace/.va-studio-toolchain/activate.sh
MYSQL_TEST_BASEDIR=/workspace/.va-studio-toolchain/mysql84 MYSQL_TEST_PORT=3306 MYSQL_TEST_LIBRARY_PATH=/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64:/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64/mysql/private bash scripts/dev/with-mysql-test-server.sh /workspace/.va-studio-toolchain/standalone/bin/php8.4 docs/verification/staging-ops-20261009/independent-review/public-backup-native.php b72c8efcb48ea433c58659b824ad32d4c174bf6d
```

[Native receipt](public-backup-native.txt): exit 0, genuine **MySQL 8.4.11** server/client
on loopback port 3306. Before any fixture account mutation, the driver requires testing
environment, disposable schema/root/loopback settings, a unique helper datadir,
empty socket and MySQL8.4 facts. The exact source custody/authentication functions
accept the correct privately generated credential and reject the wrong password.
A native raw client is first proved to authenticate as `vasey_backup@localhost`;
the source then rejects that unexpected identity. Root file ownership is modeled;
the password authentication and client/server are genuine. A fixed toolchain adapter
supplies only native MySQL libraries after `env -i`. Generated credentials, client
files and the disposable server are cleaned up; no credential enters a receipt.
[Initial native success](public-backup-native-initial.txt) is preserved; the final
driver additionally confirms the wrong-host case authenticates before its rejection.

I inspected the owner's separate exact-source receipts: **67 ops PASS**, genuine
PHP 8.4.26 runtime **13 tests /63 assertions PASS**. Independent provision Bash syntax,
Shellcheck `-x` and native-driver Pint checks pass. All product source outside the
two changed implementation files and canonical tests has an empty diff from the
prior approved publication, excluding inspected docs/CHANGELOG. Prior bounded
approvals carry on unchanged paths.

No nginx or Forge host was configured in this delta review. The native proof validates
fixture credentials, not Sean's persisted host credential, actual host grants, restore
shipping or end-to-end backup acceptance. Historical HTTP/migration receipts retain
their source identities. Stripe TEST delivery, real host/key custody and the previous
builder/runtime trust limits remain open. A publication successor requires explicit
source-equivalence carry and the final exact-head gates.
