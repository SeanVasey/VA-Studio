"""The first staging activation's two failures after the release switch, each proved here.

1. The controller's loopback probe verified the site certificate against the system store only. A Forge on-forge.com site
   (and any origin behind Cloudflare in Full (strict) mode) serves a Cloudflare Origin CA certificate, so every probe
   answered 000 and resume refused. The probe now also trusts the two pinned Origin CA roots in ops/staging/tls/.
2. Forge stops reading a deployment's output at its 10-minute limit but leaves the processes running. The first install's
   migrations took about 13 minutes, and the controller's next write to the closed pipe was SIGPIPE, which killed it
   between the release switch and the resume. forge-deploy.sh now sends everything through `tee -p` into an evidence log.
"""
import hashlib
import os
import re
import shutil
import socket
import ssl
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
KIT = REPO / "ops/staging"
CTL = (KIT / "bin/vasey-staging-ctl").read_text()
DEPLOY = (KIT / "forge-deploy.sh").read_text()
PROVISION = (KIT / "provision.sh").read_text()
BUNDLE = KIT / "tls/origin-ca.pem"
INSTALLED = "/usr/local/libexec/vasey-staging/origin-ca.pem"

# SHA-256 of the DER certificates Cloudflare publishes at developers.cloudflare.com/ssl/static/origin_ca_{rsa,ecc}_root.pem.
PINNED = {
    "d3c7e85c91707fc0a12abc5d88266747aa4fa8e7b162f633ffb3c9d989947620",  # CloudFlare Origin SSL Certificate Authority (RSA)
    "aa63697a22764b67b2134ce14cb5690ea336940b93986113f49545917832d80d",  # CloudFlare Origin SSL ECC Certificate Authority
}
TEE = 'exec > >(exec 9>&-; exec tee -p -a "$EVIDENCE/forge-deploy.log") 2>&1'


def certificates(text):
    return re.findall(r"-----BEGIN CERTIFICATE-----\n.*?-----END CERTIFICATE-----\n", text, re.S)


class OriginCaBundle(unittest.TestCase):
    def test_the_bundle_is_exactly_the_two_pinned_cloudflare_roots(self):
        blocks = certificates(BUNDLE.read_text())
        self.assertEqual(len(blocks), 2)
        found = {hashlib.sha256(ssl.PEM_cert_to_DER_cert(block)).hexdigest() for block in blocks}
        self.assertEqual(found, PINNED)

    @unittest.skipUnless(shutil.which("openssl"), "needs openssl")
    def test_neither_root_expires_within_180_days(self):
        # An expired root makes every probe answer 000 and every resume refuse. Replace the bundle well before then.
        for block in certificates(BUNDLE.read_text()):
            run = subprocess.run(["openssl", "x509", "-noout", "-checkend", str(180 * 86400)], input=block,
                                 capture_output=True, text=True)
            self.assertEqual(run.returncode, 0, "a pinned Cloudflare Origin CA root expires within 180 days: " + run.stdout)

    def test_the_probe_trusts_the_installed_bundle_and_the_system_store(self):
        self.assertIn(f"ORIGIN_CA={INSTALLED}\n", CTL)
        probe = CTL[CTL.index("probe() {"):CTL.index("as_app()")]
        self.assertIn('--cacert "$ORIGIN_CA" --capath /etc/ssl/certs', probe)
        self.assertNotIn("--insecure", probe)
        self.assertIsNone(re.search(r"(^|\s)-k(\s|$)", probe))

    def test_provision_installs_the_bundle_root_owned_and_deploy_checks_it_for_drift(self):
        self.assertIn(f'install -m 0644 -o root -g root "$KIT/tls/origin-ca.pem" {INSTALLED}\n', PROVISION)
        self.assertIn('[ -f "$KIT/tls/origin-ca.pem" ] || die "kit file missing: ops/staging/tls/origin-ca.pem"', PROVISION)
        self.assertIn(f"required tls/origin-ca.pem {INSTALLED}\n", DEPLOY)


@unittest.skipUnless(shutil.which("openssl") and shutil.which("curl"), "needs openssl and curl")
class ProbeTrustArguments(unittest.TestCase):
    """The probe's own trust arguments accept a certificate from the extra CA file and still accept one from the CA path."""

    def run_openssl(self, *args, cwd):
        subprocess.run(["openssl", *args], cwd=cwd, check=True, capture_output=True)

    def issue(self, directory, ca, leaf):
        self.run_openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", f"{ca}.key", "-out", f"{ca}.pem",
                         "-days", "2", "-subj", f"/CN={ca}", cwd=directory)
        self.run_openssl("req", "-newkey", "rsa:2048", "-nodes", "-keyout", f"{leaf}.key", "-out", f"{leaf}.csr",
                         "-subj", "/CN=probe.test", cwd=directory)
        (directory / "ext").write_text("subjectAltName=DNS:probe.test\n")
        self.run_openssl("x509", "-req", "-in", f"{leaf}.csr", "-CA", f"{ca}.pem", "-CAkey", f"{ca}.key",
                         "-CAcreateserial", "-out", f"{leaf}.pem", "-days", "2", "-extfile", "ext", cwd=directory)

    def serve(self, directory, leaf):
        with socket.socket() as probe_socket:
            probe_socket.bind(("127.0.0.1", 0))
            port = probe_socket.getsockname()[1]
        server = subprocess.Popen(["openssl", "s_server", "-quiet", "-accept", str(port), "-cert", f"{leaf}.pem",
                                   "-key", f"{leaf}.key", "-www"], cwd=directory,
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(server.wait)
        self.addCleanup(server.terminate)
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            try:
                socket.create_connection(("127.0.0.1", port), timeout=1).close()
                return port
            except OSError:
                time.sleep(0.1)
        self.fail("test TLS server did not start")

    def code(self, port, cacert, capath):
        trust = re.search(r'--cacert "\$ORIGIN_CA" --capath (\S+)', CTL)
        self.assertEqual(trust.group(1), "/etc/ssl/certs")
        run = subprocess.run(["curl", "-sS", "--noproxy", "*", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "10",
                              "--cacert", str(cacert), "--capath", str(capath),
                              "--resolve", f"probe.test:{port}:127.0.0.1", f"https://probe.test:{port}/"],
                             capture_output=True, text=True)
        return run.stdout

    def test_the_extra_ca_file_and_the_ca_path_are_both_trusted(self):
        with tempfile.TemporaryDirectory() as name:
            directory = Path(name)
            self.issue(directory, "origin", "served-by-origin")
            self.issue(directory, "public", "served-by-public")
            capath = directory / "capath"
            capath.mkdir()
            shutil.copy(directory / "public.pem", capath / "public.pem")
            self.run_openssl("rehash", str(capath), cwd=directory)
            origin_port = self.serve(directory, "served-by-origin")
            public_port = self.serve(directory, "served-by-public")
            self.assertEqual(self.code(origin_port, directory / "origin.pem", capath), "200")
            self.assertEqual(self.code(public_port, directory / "origin.pem", capath), "200")
            # Without the extra file the origin certificate is refused: the fix is what makes the probe work.
            self.assertEqual(self.code(origin_port, directory / "public.pem", capath), "000")


class ForgeCutoff(unittest.TestCase):
    def test_every_controller_call_runs_after_the_log_redirect(self):
        self.assertEqual(DEPLOY.count(TEE), 1)
        self.assertTrue(DEPLOY.startswith('EVIDENCE=$ROOT/evidence/$STAMP-${SHA:0:12}', DEPLOY.index("EVIDENCE=")))
        redirect = DEPLOY.index(TEE)
        self.assertLess(DEPLOY.index("trap on_exit EXIT"), redirect)
        self.assertLess(DEPLOY.index('install -d -m 0750 "$EVIDENCE"\n'), redirect)
        calls = [m.start() for m in re.finditer(r'"\$\{CTL\[@\]\}" (healthy|refresh|prepare|activate|prune)', DEPLOY)]
        self.assertEqual(len(calls), 5)
        self.assertTrue(all(redirect < call for call in calls))

    def lock_free_after_exit(self, redirect):
        with tempfile.TemporaryDirectory() as name:
            lock = Path(name) / "lock"
            # A surviving child that holds stdout but not the lock keeps the log writer alive after the deploy exits.
            script = (f"exec 9>>{lock}\nflock -n 9\nEVIDENCE={name}\n{redirect}\n"
                      "( exec 9>&-; sleep 3 ) &\nexit 0\n")
            subprocess.run(["bash", "-c", script], timeout=30)
            return subprocess.run(["flock", "-n", str(lock), "true"]).returncode == 0

    def test_the_log_writer_does_not_hold_the_deploy_lock(self):
        self.assertTrue(self.lock_free_after_exit(TEE))
        # Sensitivity: a writer that keeps fd 9 holds the lock while it lives.
        self.assertFalse(self.lock_free_after_exit('exec > >(exec tee -p -a "$EVIDENCE/forge-deploy.log") 2>&1'))

    def run_cutoff(self, redirect):
        """A writer that keeps writing after its reader has gone, as a deploy does after Forge's time limit."""
        with tempfile.TemporaryDirectory() as name:
            evidence = Path(name)
            script = (f"set -euo pipefail\nEVIDENCE={evidence}\n{redirect}\necho start\nsleep 1\n"
                      "for i in 1 2 3; do echo \"after the cutoff $i\"; done\n"
                      f"echo finished > {evidence}/finished\n")
            # `head -n 1` stands in for Forge: it reads one line and closes the pipe.
            subprocess.run(["bash", "-c", 'bash -c "$1" | head -n 1', "_", script], capture_output=True, text=True, timeout=30)
            deadline = time.monotonic() + 10
            while time.monotonic() < deadline and not (evidence / "finished").exists():
                time.sleep(0.1)
            time.sleep(0.3)  # let tee flush the last lines
            log = (evidence / "forge-deploy.log").read_text() if (evidence / "forge-deploy.log").exists() else ""
            return (evidence / "finished").exists(), log

    def test_the_deploy_keeps_running_and_logging_after_the_reader_closes(self):
        finished, log = self.run_cutoff(TEE)
        self.assertTrue(finished)
        self.assertEqual(log.splitlines(), ["start", "after the cutoff 1", "after the cutoff 2", "after the cutoff 3"])

    def test_without_tee_the_same_writer_dies_of_sigpipe(self):
        finished, _ = self.run_cutoff("")
        self.assertFalse(finished)


if __name__ == "__main__":
    unittest.main()
