"""Opt-in off-host backup hardening in ops/staging/backup.sh: age encryption, an rclone remote, tiered retention.

The functions under test are sliced out of the shipped script (the style of the other tests/ops files) and run in
bash with real `age`, `rsync` and `rclone` against throwaway local directories and a generated test key pair that is
never kept. Nothing here touches a network, a database or a real host."""
import datetime
import os
import random
import shutil
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
BACKUP = REPO / "ops/staging/backup.sh"
SOURCE = BACKUP.read_text()
DEFAULTS = SOURCE[SOURCE.index(': "${VASEY_BACKUP_AGE_RECIPIENTS_FILE:=}"'):SOURCE.index('[ "$(id -u "$VASEY_APP_USER")" -gt 0 ]')]
DEFINITIONS = SOURCE[SOURCE.index("SET_NAME_RE="):SOURCE.index('\ncase "${1:-}" in')]
CONF_LOADER = SOURCE[SOURCE.index(': "${VASEY_BACKUP_CONF:='):SOURCE.index(': "${VASEY_BACKUP_DIR:=')]
HAVE = {tool: shutil.which(tool) for tool in ("age", "age-keygen", "rsync", "rclone")}

# The script requires root ownership of its configuration files; a test runs as whatever user it has, so the shim
# reports the current user's files as root-owned. The ownership refusal itself is exercised only when really root.
PRELUDE = r"""set -euo pipefail
umask 077
die() { echo "DIE: $*" >&2; exit 1; }
log() { echo "LOG: $*"; }
stat() {
  local fmt=""; [ "${1:-}" = -c ] && fmt=${2:-}
  local out; out=$(command stat "$@") || return $?
  if [[ "$fmt" == *%u* ]] && [ "$(id -u)" != 0 ]; then out=${out/#$(id -u)/0}; fi
  printf '%s\n' "$out"
}
: "${VASEY_BACKUP_KEEP:=7}" "${VASEY_BACKUP_DEST:=}" "${VASEY_BACKUP_SSH_KEY:=/nonexistent}"
"""


def set_name(day, hour="030000", sha="a" * 12):
    return f"{day}T{hour}Z-{sha}"


class OffhostBase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-offhost-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.backups = self.root / "backups"
        self.backups.mkdir(mode=0o700)
        self.env = {"PATH": "/usr/local/bin:/usr/bin:/bin", "VASEY_BACKUP_DIR": str(self.backups),
                    "HOME": str(self.root), "LC_ALL": "C"}

    def bash(self, script, **extra):
        env = dict(self.env, **{k: str(v) for k, v in extra.items()})
        return subprocess.run(["bash", "-c", PRELUDE + DEFAULTS + DEFINITIONS + "\n" + script],
                              env=env, capture_output=True, text=True, timeout=120)

    def make_set(self, name=None, proof=True, payload=b"p" * 4096):
        name = name or set_name("20261015")
        bk = self.backups / name
        bk.mkdir(mode=0o700)
        (bk / "database.sql").write_text("-- synthetic dump SYNTHETIC_DATABASE_MARKER\n")
        (bk / "private.tar").write_bytes(payload)
        (bk / "env.backup").write_text("APP_KEY=base64:SYNTHETIC_KEY_MARKER\n")
        (bk / "MANIFEST").write_text("release_sha=none\n")
        if proof:
            (bk / "RESTORE_CHECK").write_text("result=RESTORE_VERIFIED\n")
        return bk

    def recipients(self, content=None, mode=0o600):
        path = self.root / "recipients.txt"
        if content is None:
            keyfile = self.root / "test-identity.txt"
            subprocess.run(["age-keygen", "-o", str(keyfile)], check=True, capture_output=True)
            content = subprocess.run(["age-keygen", "-y", str(keyfile)], check=True, capture_output=True, text=True).stdout
        path.write_text(content)
        path.chmod(mode)
        return path


@unittest.skipUnless(HAVE["age"] and HAVE["age-keygen"], "age is not installed")
class ConfigurationValidation(OffhostBase):
    def refused(self, run, reason):
        self.assertNotEqual(run.returncode, 0, run.stdout)
        self.assertIn(reason, run.stdout + run.stderr)

    def test_defaults_request_nothing(self):
        run = self.bash("hardening_requested && echo REQUESTED || echo INERT; offhost_config_ok && echo CONFIG_OK")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("INERT", run.stdout)
        self.assertIn("CONFIG_OK", run.stdout)

    def test_public_recipients_are_accepted(self):
        run = self.bash("offhost_config_ok && echo OK", VASEY_BACKUP_AGE_RECIPIENTS_FILE=self.recipients(),
                        VASEY_BACKUP_AGE_BIN=HAVE["age"])
        self.assertIn("OK", run.stdout, run.stdout + run.stderr)

    def test_private_key_material_on_the_host_is_refused(self):
        identity = self.root / "test-identity.txt"
        subprocess.run(["age-keygen", "-o", str(identity)], check=True, capture_output=True)
        run = self.bash("offhost_config_ok", VASEY_BACKUP_AGE_RECIPIENTS_FILE=identity, VASEY_BACKUP_AGE_BIN=HAVE["age"])
        self.refused(run, "private key material")
        secret = next(line for line in identity.read_text().splitlines() if line.startswith("AGE-SECRET-KEY-"))
        self.assertNotIn(secret, run.stdout + run.stderr)

    def test_malformed_missing_and_empty_recipients_are_refused(self):
        for content in ("not-a-key\n", "ssh-ed25519 AAAA\n", "age1tooshort\n", "# only a comment\n\n", ""):
            with self.subTest(content=content[:20]):
                run = self.bash("offhost_config_ok", VASEY_BACKUP_AGE_RECIPIENTS_FILE=self.recipients(content),
                                VASEY_BACKUP_AGE_BIN=HAVE["age"])
                self.assertNotEqual(run.returncode, 0)

    def test_writable_or_linked_recipients_file_is_refused(self):
        good = self.recipients()
        good.chmod(0o666)
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_AGE_RECIPIENTS_FILE=good, VASEY_BACKUP_AGE_BIN=HAVE["age"]),
                     "not group/world writable")
        good.chmod(0o600)
        link = self.root / "link.txt"
        link.symlink_to(good)
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_AGE_RECIPIENTS_FILE=link, VASEY_BACKUP_AGE_BIN=HAVE["age"]),
                     "root-owned regular file")

    @unittest.skipUnless(os.geteuid() == 0, "needs root to create a file owned by someone else")
    def test_recipients_file_owned_by_another_user_is_refused(self):
        good = self.recipients()
        os.chown(good, 65534, 65534)
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_AGE_RECIPIENTS_FILE=good, VASEY_BACKUP_AGE_BIN=HAVE["age"]),
                     "root-owned regular file")

    def test_missing_age_binary_is_refused(self):
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_AGE_RECIPIENTS_FILE=self.recipients(),
                               VASEY_BACKUP_AGE_BIN=self.root / "no-age"), "age is not installed")

    def test_require_encryption_without_recipients_is_refused(self):
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_REQUIRE_ENCRYPTION=1), "VASEY_BACKUP_AGE_RECIPIENTS_FILE is not set")
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_REQUIRE_ENCRYPTION="yes"), "must be 0 or 1")

    def test_a_remote_without_recipients_is_refused_unless_explicitly_allowed(self):
        config = self.root / "rclone.conf"
        config.write_text("[r]\ntype = local\n")
        config.chmod(0o600)
        remote = dict(VASEY_BACKUP_REMOTE="r:p", VASEY_BACKUP_RCLONE_BIN="/bin/true", VASEY_BACKUP_RCLONE_CONFIG=config)
        run = self.bash("offhost_config_ok", **remote)
        self.refused(run, "would leave this host unencrypted")
        self.assertIn("VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1", run.stdout + run.stderr)
        self.assertNotIn("r:p", run.stdout + run.stderr)
        # The explicit opt-in, and only the literal 1, lifts it; recipients make it unnecessary.
        self.assertIn("OK", self.bash("offhost_config_ok && echo OK", VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1, **remote).stdout)
        for value in ("0", "", "true", "yes", "2"):
            with self.subTest(value=value):
                self.assertNotEqual(self.bash("offhost_config_ok", VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=value, **remote).returncode, 0)
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE="true", **remote), "must be 0 or 1")
        self.assertIn("OK", self.bash("offhost_config_ok && echo OK", VASEY_BACKUP_AGE_RECIPIENTS_FILE=self.recipients(),
                                      VASEY_BACKUP_AGE_BIN=HAVE["age"], **remote).stdout)

    def test_the_opt_in_does_not_apply_without_a_remote(self):
        # Nothing to allow: the default path and rsync with recipients are unaffected, and the opt-in cannot weaken REQUIRE_ENCRYPTION.
        self.assertIn("OK", self.bash("offhost_config_ok && echo OK", VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1).stdout)
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1, VASEY_BACKUP_REQUIRE_ENCRYPTION=1),
                     "VASEY_BACKUP_AGE_RECIPIENTS_FILE is not set")

    def test_rsync_and_rclone_are_alternatives(self):
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_DEST="u@h:/p", VASEY_BACKUP_REMOTE="r:p"), "alternatives")

    def test_remote_must_be_a_named_remote_with_a_private_config(self):
        for remote in (":s3,access_key_id=AKIA:bucket", "has space:p", "r", "r:p;rm", "-flag:p"):
            with self.subTest(remote=remote):
                self.assertNotEqual(self.bash("offhost_config_ok", VASEY_BACKUP_REMOTE=remote).returncode, 0)
        config = self.root / "rclone.conf"
        config.write_text("[r]\ntype = local\n")
        config.chmod(0o644)
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_REMOTE="r:p", VASEY_BACKUP_RCLONE_BIN="/bin/true",
                               VASEY_BACKUP_RCLONE_CONFIG=config), "0600")

    def test_retention_values_are_validated(self):
        for retention, extra in (("weekly", {}), ("tiered", {"VASEY_BACKUP_KEEP_DAILY": "x"}),
                                 ("tiered", {"VASEY_BACKUP_KEEP": "0"}), ("tiered", {"VASEY_BACKUP_KEEP_MONTHLY": "-1"})):
            with self.subTest(retention=retention, extra=extra):
                self.assertNotEqual(self.bash("offhost_config_ok", VASEY_BACKUP_RETENTION=retention, **extra).returncode, 0)
        self.assertEqual(self.bash("offhost_config_ok", VASEY_BACKUP_RETENTION="tiered").returncode, 0)

    def test_remote_pruning_needs_a_remote_and_tiered_retention(self):
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_REMOTE_PRUNE=1), "VASEY_BACKUP_REMOTE_PRUNE=1 needs")
        self.refused(self.bash("offhost_config_ok", VASEY_BACKUP_REMOTE_PRUNE=1, VASEY_BACKUP_DEST="u@h:/p",
                               VASEY_BACKUP_RETENTION="tiered"), "never pruned from this host")


@unittest.skipUnless(HAVE["age"] and HAVE["age-keygen"] and HAVE["rsync"], "age or rsync is not installed")
class DefaultsAndEncryptedRsync(OffhostBase):
    def setUp(self):
        super().setUp()
        self.dest = self.root / "dest"
        self.dest.mkdir(mode=0o700)
        self.key = self.root / "ssh-key"
        self.key.write_text("synthetic")

    def test_default_ship_is_the_unchanged_plain_rsync_of_the_whole_set(self):
        bk = self.make_set()
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_DEST=self.dest, VASEY_BACKUP_SSH_KEY=self.key)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn(f"shipped {bk.name}", run.stdout)
        self.assertEqual({p.name for p in (self.dest / bk.name).iterdir()},
                         {"database.sql", "private.tar", "env.backup", "MANIFEST", "RESTORE_CHECK"})
        self.assertFalse(list(self.dest.glob("*.age")))

    def test_default_ship_without_a_destination_only_warns(self):
        bk = self.make_set()
        run = self.bash(f'ship "{bk}"')
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("NOT an off-host backup", run.stdout)

    def test_default_ship_does_not_require_a_restore_proof_as_before(self):
        bk = self.make_set(proof=False)
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_DEST=self.dest, VASEY_BACKUP_SSH_KEY=self.key)
        self.assertEqual(run.returncode, 0, run.stderr)

    def test_encrypted_ship_round_trips_and_leaves_no_plaintext_off_host(self):
        recipients = self.recipients()
        identity = self.root / "test-identity.txt"
        payload = os.urandom(300_000)
        bk = self.make_set(payload=payload)
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_DEST=self.dest, VASEY_BACKUP_SSH_KEY=self.key,
                        VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, VASEY_BACKUP_AGE_BIN=HAVE["age"])
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("age-encrypted via rsync", run.stdout)
        shipped = sorted(p.name for p in self.dest.iterdir())
        self.assertEqual(shipped, [f"{bk.name}.tar.age", f"{bk.name}.tar.age.sha256"])
        ciphertext = (self.dest / f"{bk.name}.tar.age").read_bytes()
        self.assertTrue(ciphertext.startswith(b"age-encryption.org/v1"))
        for marker in (b"SYNTHETIC_DATABASE_MARKER", b"SYNTHETIC_KEY_MARKER", payload[:64]):
            self.assertNotIn(marker, ciphertext)
        # The recorded digest matches the shipped bytes.
        check = subprocess.run(["sha256sum", "--check", "--status", f"{bk.name}.tar.age.sha256"], cwd=self.dest)
        self.assertEqual(check.returncode, 0)
        # No staging directory is left behind, and the local set is untouched.
        self.assertEqual([p.name for p in self.backups.iterdir() if p.name.startswith(".ship")], [])
        self.assertEqual((bk / "private.tar").read_bytes(), payload)
        # Decrypting needs the private key, which only this test (standing in for the operator's machine) holds.
        restore = self.root / "restore"
        restore.mkdir()
        decrypt = subprocess.run(f'age -d -i "{identity}" "{self.dest}/{bk.name}.tar.age" | tar -x -C "{restore}"',
                                 shell=True, capture_output=True, text=True, env={"PATH": "/usr/bin:/bin"})
        self.assertEqual(decrypt.returncode, 0, decrypt.stderr)
        self.assertEqual((restore / bk.name / "private.tar").read_bytes(), payload)
        self.assertIn("SYNTHETIC_KEY_MARKER", (restore / bk.name / "env.backup").read_text())
        self.assertEqual((restore / bk.name / "RESTORE_CHECK").read_text(), "result=RESTORE_VERIFIED\n")

    def test_a_set_without_a_verified_restore_proof_is_never_shipped_when_hardened(self):
        recipients = self.recipients()
        for proof_text in (None, "result=FAILED\n"):
            with self.subTest(proof=proof_text):
                bk = self.make_set(name=set_name("20261016" if proof_text else "20261017"), proof=False)
                if proof_text:
                    (bk / "RESTORE_CHECK").write_text(proof_text)
                run = self.bash(f'ship "{bk}"', VASEY_BACKUP_DEST=self.dest, VASEY_BACKUP_SSH_KEY=self.key,
                                VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, VASEY_BACKUP_AGE_BIN=HAVE["age"])
                self.assertNotEqual(run.returncode, 0)
                self.assertIn("no verified restore proof", run.stderr)
                self.assertEqual(list(self.dest.iterdir()), [])

    def test_a_bad_configuration_ships_nothing_even_as_plaintext(self):
        bk = self.make_set()
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_DEST=self.dest, VASEY_BACKUP_SSH_KEY=self.key,
                        VASEY_BACKUP_REQUIRE_ENCRYPTION=1)
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(list(self.dest.iterdir()), [])

    def test_encryption_failure_cleans_up_and_ships_nothing(self):
        recipients = self.recipients()
        broken = self.root / "age"
        broken.write_text("#!/bin/sh\ncat >/dev/null\nexit 1\n")
        broken.chmod(0o755)
        bk = self.make_set()
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_DEST=self.dest, VASEY_BACKUP_SSH_KEY=self.key,
                        VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, VASEY_BACKUP_AGE_BIN=broken)
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(list(self.dest.iterdir()), [])
        self.assertEqual([p.name for p in self.backups.iterdir() if p.name.startswith(".ship")], [])
        self.assertTrue((bk / "database.sql").exists())

    def test_an_empty_or_foreign_output_is_not_accepted_as_ciphertext(self):
        recipients = self.recipients()
        fake = self.root / "age"
        fake.write_text("#!/bin/sh\ncat >/dev/null\nwhile [ $# -gt 0 ]; do [ \"$1\" = -o ] && out=$2; shift; done\necho plaintext > \"$out\"\n")
        fake.chmod(0o755)
        bk = self.make_set()
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_DEST=self.dest, VASEY_BACKUP_SSH_KEY=self.key,
                        VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, VASEY_BACKUP_AGE_BIN=fake)
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(list(self.dest.iterdir()), [])

    def test_insufficient_free_space_refuses_before_encrypting(self):
        recipients = self.recipients()
        bk = self.make_set()
        stubs = self.root / "stubs"
        stubs.mkdir()
        (stubs / "df").write_text("#!/bin/sh\nprintf 'Avail\\n1024\\n'\n")
        (stubs / "df").chmod(0o755)
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_DEST=self.dest, VASEY_BACKUP_SSH_KEY=self.key,
                        VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, VASEY_BACKUP_AGE_BIN=HAVE["age"],
                        PATH=f"{stubs}:/usr/local/bin:/usr/bin:/bin")
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("not enough free space", run.stdout)
        self.assertEqual(list(self.dest.iterdir()), [])

    def test_only_well_formed_set_directories_are_shipped_when_hardened(self):
        recipients = self.recipients()
        odd = self.backups / "not-a-set"
        odd.mkdir()
        run = self.bash(f'ship "{odd}"', VASEY_BACKUP_DEST=self.dest, VASEY_BACKUP_SSH_KEY=self.key,
                        VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, VASEY_BACKUP_AGE_BIN=HAVE["age"])
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(list(self.dest.iterdir()), [])

    def test_hardened_without_a_destination_only_warns_like_the_default(self):
        recipients = self.recipients()
        bk = self.make_set()
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, VASEY_BACKUP_AGE_BIN=HAVE["age"])
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("NOT an off-host backup", run.stdout)


@unittest.skipUnless(HAVE["age"] and HAVE["age-keygen"] and HAVE["rclone"], "age or rclone is not installed")
class RcloneRemote(OffhostBase):
    def setUp(self):
        super().setUp()
        self.remote_dir = self.root / "remote"
        self.remote_dir.mkdir()
        self.config = self.root / "rclone.conf"
        self.config.write_text("[vasey-backup]\ntype = local\n")
        self.config.chmod(0o600)
        self.settings = {"VASEY_BACKUP_REMOTE": f"vasey-backup:{self.remote_dir}", "VASEY_BACKUP_RCLONE_BIN": HAVE["rclone"],
                         "VASEY_BACKUP_RCLONE_CONFIG": self.config, "VASEY_BACKUP_AGE_BIN": HAVE["age"]}

    def test_encrypted_set_goes_to_the_remote_and_decrypts(self):
        recipients = self.recipients()
        identity = self.root / "test-identity.txt"
        bk = self.make_set(payload=os.urandom(100_000))
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, **self.settings)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("age-encrypted via rclone", run.stdout)
        self.assertEqual(sorted(p.name for p in self.remote_dir.iterdir()), [f"{bk.name}.tar.age", f"{bk.name}.tar.age.sha256"])
        restore = self.root / "restore"
        restore.mkdir()
        decrypt = subprocess.run(f'age -d -i "{identity}" "{self.remote_dir}/{bk.name}.tar.age" | tar -x -C "{restore}"',
                                 shell=True, capture_output=True, env={"PATH": "/usr/bin:/bin"})
        self.assertEqual(decrypt.returncode, 0)
        self.assertEqual((restore / bk.name / "private.tar").read_bytes(), (bk / "private.tar").read_bytes())
        # A second run of the same set is idempotent (--immutable only objects to changed bytes).
        again = self.bash(f'ship "{bk}"', VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, **self.settings)
        self.assertEqual(again.returncode, 0, again.stdout + again.stderr)

    def test_rclone_without_age_refuses_to_ship_and_sends_nothing(self):
        bk = self.make_set()
        run = self.bash(f'ship "{bk}"', **self.settings)
        self.assertNotEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("would leave this host unencrypted", run.stdout + run.stderr)
        self.assertEqual(list(self.remote_dir.iterdir()), [])
        self.assertEqual([p.name for p in self.backups.iterdir() if p.name.startswith(".ship")], [])
        self.assertTrue((bk / "env.backup").exists(), "the local set must stay intact")
        for marker in ("SYNTHETIC_KEY_MARKER", "SYNTHETIC_DATABASE_MARKER"):
            self.assertNotIn(marker, run.stdout + run.stderr)

    def test_rclone_without_age_ships_only_with_the_explicit_opt_in_and_says_so(self):
        bk = self.make_set()
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1, **self.settings)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1 and no age recipients", run.stdout)
        self.assertIn("unencrypted by this script", run.stdout)
        self.assertTrue((self.remote_dir / bk.name / "database.sql").exists())

    def test_the_opt_in_does_not_change_encrypted_shipping(self):
        recipients = self.recipients()
        for allow in (0, 1):
            with self.subTest(allow=allow):
                shutil.rmtree(self.remote_dir)
                self.remote_dir.mkdir()
                bk = self.backups / set_name("20261015")
                if not bk.exists():
                    bk = self.make_set()
                run = self.bash(f'ship "{bk}"', VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients,
                                VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=allow, **self.settings)
                self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
                self.assertIn("age-encrypted via rclone", run.stdout)
                self.assertNotIn("sent as stored", run.stdout)
                self.assertEqual(sorted(p.name for p in self.remote_dir.iterdir()), [f"{bk.name}.tar.age", f"{bk.name}.tar.age.sha256"])
                for artefact in self.remote_dir.iterdir():
                    self.assertNotIn(b"SYNTHETIC_KEY_MARKER", artefact.read_bytes())

    def test_stale_shipping_directories_are_cleared_and_fresh_ones_are_not(self):
        recipients = self.recipients()
        stale, fresh = self.backups / ".ship.OLD123", self.backups / ".ship.NEW456"
        decoy, link_target = self.backups / ".ship-not-ours", self.root / "outside"
        for directory in (stale, fresh, decoy, link_target):
            directory.mkdir()
            (directory / "leftover.tar.age").write_bytes(b"x")
        (self.backups / ".ship.LINK00").symlink_to(link_target, target_is_directory=True)
        old = time.time() - 3 * 86400
        for directory in (stale, decoy, self.backups / ".ship.LINK00"):
            os.utime(directory, (old, old), follow_symlinks=False)
        bk = self.make_set()
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, **self.settings)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertFalse(stale.exists(), "a staging directory untouched for days is removed")
        self.assertTrue(fresh.exists(), "a recent one could be a transfer in progress")
        self.assertTrue(decoy.exists(), "only the exact .ship.XXXXXX name shape is touched")
        self.assertTrue((self.backups / ".ship.LINK00").is_symlink() and (link_target / "leftover.tar.age").exists(), "a link is never followed")

    def test_require_encryption_blocks_the_plain_rclone_path(self):
        bk = self.make_set()
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_REQUIRE_ENCRYPTION=1, **self.settings)
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(list(self.remote_dir.iterdir()), [])

    def test_a_failing_remote_fails_the_ship_and_cleans_the_staging_copy(self):
        recipients = self.recipients()
        bk = self.make_set()
        settings = dict(self.settings, VASEY_BACKUP_REMOTE="missing-remote:path")
        run = self.bash(f'ship "{bk}"', VASEY_BACKUP_AGE_RECIPIENTS_FILE=recipients, **settings)
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual([p.name for p in self.backups.iterdir() if p.name.startswith(".ship")], [])
        self.assertTrue((bk / "env.backup").exists())

    def test_remote_pruning_follows_the_tiered_policy_and_spares_everything_else(self):
        names = [set_name(f"202610{day:02d}") for day in range(1, 16)]
        for name in names:
            (self.remote_dir / f"{name}.tar.age").write_bytes(b"x")
            (self.remote_dir / f"{name}.tar.age.sha256").write_bytes(b"x")
        (self.remote_dir / "README-keep-me.txt").write_text("not a backup set")
        (self.remote_dir / "20261001T000000Z-ffffffffffff.FAILED").mkdir()
        run = self.bash("prune_remote", VASEY_BACKUP_RETENTION="tiered", VASEY_BACKUP_REMOTE_PRUNE=1, VASEY_BACKUP_KEEP=3,
                        VASEY_BACKUP_KEEP_DAILY=3, VASEY_BACKUP_KEEP_WEEKLY=0, VASEY_BACKUP_KEEP_MONTHLY=0,
                        VASEY_BACKUP_AGE_RECIPIENTS_FILE=self.recipients(), **self.settings)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        remaining = sorted(p.name for p in self.remote_dir.iterdir())
        self.assertEqual(remaining, sorted([f"{n}.tar.age{s}" for n in names[-3:] for s in ("", ".sha256")]
                                           + ["README-keep-me.txt", "20261001T000000Z-ffffffffffff.FAILED"]))
        self.assertIn("pruned 12 set(s)", run.stdout)

    def test_remote_pruning_is_off_by_default(self):
        (self.remote_dir / f"{set_name('20261001')}.tar.age").write_bytes(b"x")
        run = self.bash("prune_remote", VASEY_BACKUP_RETENTION="tiered", VASEY_BACKUP_KEEP=1, **self.settings)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(len(list(self.remote_dir.iterdir())), 1)


class RetentionPlanner(OffhostBase):
    def plan(self, names, keep, daily, weekly, monthly, protect=""):
        run = subprocess.run(["bash", "-c", PRELUDE + DEFAULTS + DEFINITIONS + f"\nretention_plan {keep} {daily} {weekly} {monthly} {protect}"],
                             input="\n".join(names) + "\n", env=self.env, capture_output=True, text=True, timeout=60)
        self.assertEqual(run.returncode, 0, run.stderr)
        return set(run.stdout.split())

    def test_flat_floor_is_always_kept(self):
        names = [set_name(f"202610{day:02d}") for day in range(1, 21)]
        pruned = self.plan(names, 7, 0, 0, 0)
        self.assertEqual(pruned, set(names[:13]))

    def test_daily_weekly_and_monthly_representatives_are_added(self):
        names = [set_name("20260915"), set_name("20260920"), set_name("20260930"), set_name("20261001"),
                 set_name("20261006"), set_name("20261007"), set_name("20261008"), set_name("20261014"),
                 set_name("20261015", "030000"), set_name("20261015", "150000"), set_name("20261016")]
        kept = set(names) - self.plan(names, 2, 3, 2, 2)
        self.assertIn(set_name("20261016"), kept)             # newest two
        self.assertIn(set_name("20261015", "150000"), kept)
        self.assertNotIn(set_name("20261015", "030000"), kept)  # a second set on a kept day is not a daily representative
        self.assertIn(set_name("20260930"), kept)             # newest of September (monthly)
        self.assertNotIn(set_name("20260915"), kept)

    def test_the_protected_set_survives(self):
        names = [set_name(f"202610{day:02d}") for day in range(1, 21)]
        self.assertNotIn(names[0], self.plan(names, 1, 0, 0, 0, protect=names[0]))

    def test_malformed_names_are_never_pruned(self):
        names = [set_name("20261001"), "README", "20261001T000000Z-zzzzzzzzzzzz", "20261002T000000Z-ffffffffffff.FAILED",
                 "../../etc", "20261003T000000Z-abcdefabcdef/x", "", " "]
        self.assertEqual(self.plan(names, 1, 0, 0, 0), set())

    def test_it_never_keeps_less_than_the_flat_rule_and_is_deterministic(self):
        rng = random.Random(1247)
        base = datetime.date(2025, 9, 1)
        for _ in range(40):
            days = sorted(rng.sample(range(0, 400), rng.randint(1, 60)))
            names = [set_name((base + datetime.timedelta(days=d)).strftime("%Y%m%d"), f"{rng.randint(0, 23):02d}0000",
                              "%012x" % rng.getrandbits(48)) for d in days]
            keep, daily, weekly, monthly = rng.randint(1, 10), rng.randint(0, 10), rng.randint(0, 6), rng.randint(0, 12)
            pruned = self.plan(names, keep, daily, weekly, monthly)
            newest = sorted(names, reverse=True)[:keep]
            self.assertTrue(pruned.isdisjoint(newest), (names, keep))
            self.assertLessEqual(len(pruned), max(len(names) - keep, 0))
            self.assertEqual(pruned, self.plan(list(reversed(names)), keep, daily, weekly, monthly))
            self.assertTrue(pruned <= set(names))


class LocalPruning(OffhostBase):
    def test_default_pruning_is_the_flat_newest_n_rule_and_ignores_failed_sets(self):
        names = [set_name(f"202610{day:02d}") for day in range(1, 11)]
        for name in names:
            self.make_set(name)
        failed = self.backups / "20261001T000000Z-ffffffffffff.FAILED"
        failed.mkdir()
        run = self.bash("prune_local")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(sorted(p.name for p in self.backups.iterdir()), sorted(names[3:] + [failed.name]))

    def test_tiered_pruning_keeps_the_newest_proven_set(self):
        names = [set_name(f"202610{day:02d}") for day in range(1, 11)]
        for index, name in enumerate(names):
            self.make_set(name, proof=index == 0)  # only the oldest carries a restore proof
        run = self.bash("prune_local", VASEY_BACKUP_RETENTION="tiered", VASEY_BACKUP_KEEP=2, VASEY_BACKUP_KEEP_DAILY=0,
                        VASEY_BACKUP_KEEP_WEEKLY=0, VASEY_BACKUP_KEEP_MONTHLY=0)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(sorted(p.name for p in self.backups.iterdir()), sorted([names[0], names[-2], names[-1]]))

    def test_invalid_tiered_configuration_prunes_nothing(self):
        for name in (set_name(f"202610{day:02d}") for day in range(1, 6)):
            self.make_set(name)
        run = self.bash("prune_local", VASEY_BACKUP_RETENTION="tiered", VASEY_BACKUP_KEEP_DAILY="lots")
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(len(list(self.backups.iterdir())), 5)


@unittest.skipUnless(os.geteuid() == 0, "the script's own preamble refuses to run as another user")
class ConfigFileCustody(OffhostBase):
    """The optional backup.conf is sourced as root, so it must be as trusted as staging.conf."""

    def loader(self, conf_path):
        return subprocess.run(["bash", "-c", PRELUDE + f'VASEY_BACKUP_CONF="{conf_path}"\n' + CONF_LOADER + '\necho "REMOTE=${VASEY_BACKUP_REMOTE:-unset}"'],
                              env=self.env, capture_output=True, text=True)

    def test_absent_file_changes_nothing(self):
        run = self.loader(self.root / "absent.conf")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("REMOTE=unset", run.stdout)

    def test_a_trusted_file_is_sourced(self):
        conf = self.root / "backup.conf"
        conf.write_text("VASEY_BACKUP_REMOTE=vasey-backup:bucket\n")
        conf.chmod(0o600)
        self.assertIn("REMOTE=vasey-backup:bucket", self.loader(conf).stdout)

    def test_writable_linked_or_foreign_files_are_refused_and_never_sourced(self):
        conf = self.root / "backup.conf"
        conf.write_text("VASEY_BACKUP_REMOTE=evil:x\n")
        conf.chmod(0o666)
        run = self.loader(conf)
        self.assertNotEqual(run.returncode, 0)
        self.assertNotIn("evil", run.stdout)
        conf.chmod(0o600)
        link = self.root / "link.conf"
        link.symlink_to(conf)
        self.assertNotEqual(self.loader(link).returncode, 0)
        os.chown(conf, 65534, 65534)
        self.assertNotEqual(self.loader(conf).returncode, 0)


if __name__ == "__main__":
    unittest.main()
