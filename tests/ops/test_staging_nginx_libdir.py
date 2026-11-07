"""provision.sh must create /var/lib/nginx before it initializes the runtime directories.

The nginx.org packages that Forge installs (seen on the staging Laravel VPS: nginx 1.31.8-1~noble, temp paths under
/var/cache/nginx) ship no /var/lib/nginx, so the no-follow walk to /var/lib/nginx/vasey-staging-fastcgi failed with
"unsafe runtime directory". Ubuntu's own package has the directory. Reads the real script; no host."""
import re
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
PROVISION = (REPO / "ops/staging/provision.sh").read_text()


class NginxLibDirTest(unittest.TestCase):
    def test_the_parent_is_created_root_owned_before_the_runtime_walk(self):
        create = PROVISION.index("[ -e /var/lib/nginx ] || install -d -m 0755 -o root -g root /var/lib/nginx")
        check = PROVISION.index('[ "$(stat -c %u /var/lib/nginx)" = 0 ]')
        call = [m.start() for m in re.finditer(r"^initialize_runtime_directories$", PROVISION, re.M)]
        self.assertEqual(1, len(call))
        self.assertLess(create, check)
        self.assertLess(check, call[0])

    def test_the_site_template_still_uses_the_directory_the_kit_creates(self):
        template = (REPO / "ops/staging/nginx/vasey-staging.conf").read_text()
        self.assertIn("fastcgi_temp_path /var/lib/nginx/vasey-staging-fastcgi 1 2;", template)
        self.assertIn('initialize(nginx, "vasey-staging-fastcgi"', PROVISION)


if __name__ == "__main__":
    unittest.main()
