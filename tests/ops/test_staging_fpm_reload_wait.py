"""provision.sh must wait for the new FPM pool sockets after `systemctl reload`, not test once.

The php-fpm reload is a USR2 re-exec that systemctl does not wait for. On the staging host the reload started at
22:19:20 and the new pools were ready at 22:19:22, so a single immediate `[ -S ... ]` refused a healthy server
("FPM pool sockets missing after reload"). Reads the real script; no host."""
import re
import unittest
from pathlib import Path

PROVISION = (Path(__file__).resolve().parents[2] / "ops/staging/provision.sh").read_text()


class FpmReloadWaitTest(unittest.TestCase):
    def test_the_socket_check_polls_with_a_bound_before_failing(self):
        reload = PROVISION.index('systemctl reload "$FPM_SERVICE"')
        loop = re.search(r"for _ in \$\(seq 1 (\d+)\); do\n(.*?)\ndone\n", PROVISION[reload:], re.S)
        self.assertIsNotNone(loop)
        self.assertLessEqual(int(loop.group(1)), 60)
        self.assertIn("sleep 1", loop.group(2))
        self.assertIn("break", loop.group(2))
        for socket in ("/run/php/vasey-staging.sock", "/run/php/vasey-paid-delivery.sock"):
            self.assertIn(f"[ -S {socket} ]", loop.group(2))
        failure = PROVISION.index('die "FPM pool sockets missing', reload)
        self.assertGreater(failure, reload + loop.end() - 1)


if __name__ == "__main__":
    unittest.main()
