"""The production nginx kit on the current Forge layout, derived from staging's: a conf.d http-level file plus a site.conf body.

Production will be a Forge site too (Sean's decision of 2026-10-18), so it gets the three-file layout of
tests/ops/test_staging_nginx_site.py, built by ops/production/build-kit.py from the staging files. These tests read the
derived files exactly as the kit ships them and prove:
  - the split and the root strategy, as for staging (every location sets the release root, a catch-all `location /`);
  - a directive-level diff against the whole-file production template this replaces (frozen in
    tests/fixtures/ops/vasey-production-whole-file-a5f87638.conf, main a5f87638): headers, rate limits, logs, the paid
    routes and everything else are the same directives; the only differences are the per-location `root` and the dropped
    Forge `server/*` include;
  - the production-only properties: no basic auth, no robots header, no webhook location, the masked access log;
  - provisioning: the derived provision.sh installs the http file like staging, with production names in its
    duplicate-definition check, which is run against the old template;
  - when nginx is installed: `nginx -t` on a synthetic Forge tree, and a live loopback run against stub FastCGI pools.
No host is touched: everything runs in a temporary directory on loopback."""
import hashlib
import importlib.util
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
STAGING = REPO / "ops/staging"
OLD_TEMPLATE_PATH = REPO / "tests/fixtures/ops/vasey-production-whole-file-a5f87638.conf"
OLD_TEMPLATE_SHA256 = "f65b027599004177d41e848461d9294c0a41af594ef0b24287bb7bfd0b90e86f"  # git blob 92ae2e22 at a5f87638


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


build_kit = load("build_kit", REPO / "ops/production/build-kit.py")
# The parser, location model, stub FastCGI and request helper are the staging test's; loaded as a module object so its test
# classes are not collected a second time here.
site = load("staging_nginx_site_helpers", Path(__file__).resolve().parent / "test_staging_nginx_site.py")
parse, args_of, select_location, StubFastCGI = site.parse, site.args_of, site.select_location, site.StubFastCGI


def derived(dst):
    entry = next(e for e in build_kit.DERIVED if e.dst == dst)
    return build_kit.derive(entry, STAGING)


PROVISION = derived("provision.sh")
BODY_TEMPLATE = derived("nginx/vasey-production.conf")
HTTP_TEMPLATE = derived("nginx/vasey-production-http.conf")
CLASSIC_TEMPLATE = derived("nginx/vasey-production-classic.conf")
OLD_TEMPLATE = OLD_TEMPLATE_PATH.read_text()
ROOT = "/srv/vasey-production"
RELEASE_ROOT = f"{ROOT}/current/public"
HOST = "shop.example.invalid"
NGINX = shutil.which("nginx")


def render(text, root=ROOT, host=HOST):
    """What provision.sh's render() does to these templates."""
    return text.replace("__VASEY_ROOT__", root).replace("__PRODUCTION_HOST__", host)


BODY = parse(render(BODY_TEMPLATE))
HTTP = parse(HTTP_TEMPLATE)
LOCATIONS = [node for node in BODY.walk() if node.name == "location"]


def location(*args):
    found = [node for node in LOCATIONS if node.args == list(args)]
    assert len(found) == 1, args
    return found[0]


def canon(node, drop=()):
    """A comparable form of a directive and everything under it; `drop` names directives left out at every depth."""
    children = None if node.children is None else tuple(canon(c, drop) for c in node.children if c.name not in drop)
    return node.name, tuple(node.args), children


class Split(unittest.TestCase):
    def test_placeholders_are_exactly_the_documented_sets(self):
        self.assertEqual(set(re.findall(r"__[A-Z_]+__", BODY_TEMPLATE)), {"__VASEY_ROOT__"})
        self.assertEqual(set(re.findall(r"__[A-Z_]+__", HTTP_TEMPLATE)), set())
        self.assertEqual(set(re.findall(r"__[A-Z_]+__", CLASSIC_TEMPLATE)),
                         {"__PRODUCTION_HOST__", "__SSL_CERT__", "__SSL_KEY__", "__FORGE_CONF__"})
        self.assertNotRegex(render(BODY_TEMPLATE), r"__[A-Z_]+__")

    def test_every_file_is_derived_and_names_nothing_from_staging(self):
        for text in (BODY_TEMPLATE, HTTP_TEMPLATE, CLASSIC_TEMPLATE):
            self.assertTrue(text.startswith("# DERIVED by ops/production/build-kit.py from ops/staging/nginx/"))
            body = "\n".join(text.splitlines()[3:])
            for token in build_kit.FORBIDDEN + ["VaseyStaging", "staging.htpasswd"]:
                self.assertNotIn(token, body)

    def test_the_conf_d_file_holds_only_http_level_helpers(self):
        self.assertEqual([node.name for node in HTTP.children], ["limit_req_zone", "map", "map", "map", "log_format"])
        self.assertEqual(args_of(HTTP, "limit_req_zone"),
                         [["$binary_remote_addr", "zone=vasey_production_general:10m", "rate=30r/s"]])
        self.assertEqual({node.args[1]: node.args[0] for node in HTTP.find("map")}, {
            "$vasey_production_referrer_policy": "$upstream_http_referrer_policy",
            "$vasey_production_frame_options": "$request_uri",
            "$vasey_production_logged_uri": "$request_uri",
        })
        self.assertEqual([a[0] for a in args_of(HTTP, "log_format")], ["vasey_production"])
        for live in ("set_real_ip_from", "real_ip_header", "real_ip_recursive"):
            self.assertEqual([n for n in HTTP.walk() if n.name == live], [], live)
        self.assertIn("#   set_real_ip_from <reviewed proxy address or range>;", HTTP_TEMPLATE)

    def test_every_zone_map_and_format_the_body_uses_is_defined_once_in_the_conf_d_file(self):
        defined_zones = {re.match(r"zone=([a-z_]+):", a[1]).group(1) for a in args_of(HTTP, "limit_req_zone")}
        used_zones = {a[0].split("=", 1)[1] for node in [BODY, *LOCATIONS] for a in args_of(node, "limit_req")}
        self.assertEqual(used_zones, defined_zones)
        defined_vars = {node.args[1] for node in HTTP.find("map")}
        used_vars = set(re.findall(r"\$vasey_production_[a-z_]+", BODY_TEMPLATE)) | \
            set(re.findall(r"\$vasey_production_[a-z_]+", " ".join(" ".join(a) for a in args_of(HTTP, "log_format"))))
        self.assertEqual(used_vars, defined_vars)
        self.assertEqual({a[1] for a in args_of(BODY, "access_log")}, {a[0] for a in args_of(HTTP, "log_format")})
        # The staging kit uses its own names, so both kits can share an nginx instance.
        staging = parse((STAGING / "nginx/vasey-staging-http.conf").read_text())
        staging_names = {re.match(r"zone=([a-z_]+):", a[1]).group(1) for a in args_of(staging, "limit_req_zone")} | \
            {node.args[1] for node in staging.find("map")} | {a[0] for a in args_of(staging, "log_format")}
        self.assertEqual(staging_names & (defined_zones | defined_vars | {"vasey_production"}), set())

    def test_the_body_sets_nothing_the_forge_wrapper_owns_and_includes_nothing_forge_controls(self):
        top = {node.name for node in BODY.children}
        for owned in ("server", "http", "listen", "http2", "server_name", "server_tokens", "root", "ssl_certificate",
                      "ssl_certificate_key", "limit_req_zone", "map", "log_format", "upstream"):
            self.assertNotIn(owned, top)
        includes = [node.args for node in BODY.walk() if node.name == "include"]
        self.assertTrue(includes)
        self.assertTrue(all(args == ["fastcgi_params"] for args in includes), includes)
        self.assertNotIn("forge-conf", "\n".join(l.split("#")[0] for l in BODY_TEMPLATE.splitlines()))

    def test_the_classic_wrapper_only_adds_what_forge_would(self):
        tree = parse(render(CLASSIC_TEMPLATE))
        servers = tree.find("server")
        self.assertEqual(len(servers), 1)
        self.assertEqual(sorted(node.name for node in servers[0].children),
                         sorted(["listen", "listen", "server_name", "server_tokens", "ssl_certificate", "ssl_certificate_key", "include"]))
        self.assertEqual(args_of(servers[0], "include"), [["/etc/vasey-production/nginx-site.conf"]])
        self.assertEqual(args_of(servers[0], "server_name"), [[HOST]])
        self.assertEqual(args_of(tree, "include"), [["forge-conf/__FORGE_CONF__/before/*"], ["forge-conf/__FORGE_CONF__/after/*"]])


class RootStrategy(unittest.TestCase):
    def test_every_location_sets_the_release_root_and_nothing_aliases(self):
        self.assertGreaterEqual(len(LOCATIONS), 9)
        for node in LOCATIONS:
            self.assertEqual(args_of(node, "root"), [[RELEASE_ROOT]], node)
        for node in BODY.walk():
            self.assertNotIn(node.name, ("alias", "autoindex"), node)
            if node.name == "root":
                self.assertEqual(node.args, [RELEASE_ROOT])
        self.assertEqual(BODY.find("root"), [])

    def test_a_plain_prefix_catch_all_exists(self):
        self.assertEqual(args_of(location("/"), "try_files"), [["$uri", "$uri/", "/index.php?$query_string"]])

    def test_no_request_path_falls_back_to_the_wrapper_root(self):
        self.assertEqual(BODY.find("location"), LOCATIONS, "no nested locations")
        paths = ["/", "/README.md", "/composer.json", "/artisan", "/.env", "/.git/config", "/.well-known/acme-challenge/x",
                 "/public/index.php", "/index.php", "/index.php/webhooks/stripe", "/webhooks/stripe", "/webhooks/stripe/",
                 "/build/assets/app.js", "/build", "/favicon.ico", "/robots.txt", "/storage/app/private/x", "/embed/abc",
                 "/livewire-0123abcd/upload-file", "/livewire-0123abcd/update",
                 "/paid-grants/authorizations/00000000-0000-0000-0000-000000000000/redeem",
                 "/paid-grants/origins/00000000-0000-0000-0000-000000000000/document", "/vendor/autoload.php",
                 "/ops/production/provision.sh", "/database/database.sqlite", "/x" * 50, "//double", "/%2e%2e/"]
        paths += [args[-1].split("?")[0] for node in LOCATIONS for args in args_of(node, "try_files") if args[-1].startswith("/")]
        paths += [args[-1] for args in args_of(BODY, "error_page")]
        for path in paths:
            chosen = select_location(LOCATIONS, path)
            self.assertIsNotNone(chosen, path)
            self.assertEqual(args_of(chosen, "root"), [[RELEASE_ROOT]], path)

    def test_the_selection_model_matches_the_intended_routes(self):
        self.assertEqual(select_location(LOCATIONS, "/.env").args, ["~", r"/\.(?!well-known/)"])
        self.assertEqual(select_location(LOCATIONS, "/.well-known/acme-challenge/x").args, ["/"])
        self.assertEqual(select_location(LOCATIONS, "/public/index.php").args, ["~", r"\.php$"])
        self.assertEqual(select_location(LOCATIONS, "/index.php").args, ["=", "/index.php"])
        self.assertEqual(select_location(LOCATIONS, "/webhooks/stripe").args, ["/"])  # no exemption: Laravel answers
        self.assertEqual(select_location(LOCATIONS, "/build/x.php").args, ["^~", "/build/"])


class SameDirectivesAsTheWholeFileTemplate(unittest.TestCase):
    """Directive-level diff: the old whole-file production template against conf.d file + classic wrapper + body."""

    @classmethod
    def setUpClass(cls):
        cls.old = parse(render(OLD_TEMPLATE))
        classic = parse(render(CLASSIC_TEMPLATE))
        cls.old_server = cls.old.find("server")[0]
        new_server = classic.find("server")[0]
        cls.new_server_children = [c for c in new_server.children if c.args != ["/etc/vasey-production/nginx-site.conf"]] + BODY.children
        cls.classic = classic

    def test_the_frozen_template_is_the_one_main_shipped(self):
        self.assertEqual(hashlib.sha256(OLD_TEMPLATE_PATH.read_bytes()).hexdigest(), OLD_TEMPLATE_SHA256)
        self.assertFalse((REPO / "ops/production/nginx").exists(), "the production nginx files are derived, not committed")

    def test_http_level_directives_are_identical(self):
        old = sorted(canon(c) for c in self.old.children if c.name not in ("server", "include"))
        new = sorted(canon(c) for c in HTTP.children)
        self.assertEqual(new, old)

    def test_the_forge_before_and_after_includes_are_kept_in_the_classic_wrapper(self):
        self.assertEqual(args_of(self.classic, "include"), args_of(self.old, "include"))

    def test_server_level_directives_differ_only_by_the_root_and_the_forge_server_include(self):
        def plain(children):
            return sorted(canon(c) for c in children if c.name != "location")
        old = [c for c in self.old_server.children
               if c.name != "root" and c.args != ["forge-conf/__FORGE_CONF__/server/*"]]
        self.assertEqual(plain(self.new_server_children), plain(old))
        # What was left out on the old side, exactly:
        dropped = [canon(c) for c in self.old_server.children if c.name == "root" or c.args == ["forge-conf/__FORGE_CONF__/server/*"]]
        self.assertEqual(dropped, [("root", (RELEASE_ROOT,), None), ("include", ("forge-conf/__FORGE_CONF__/server/*",), None)])

    def test_locations_are_the_same_in_the_same_order_apart_from_their_own_root(self):
        old = [canon(c) for c in self.old_server.children if c.name == "location"]
        new = [canon(c, drop=("root",)) for c in self.new_server_children if c.name == "location"]
        self.assertEqual(new, old)
        self.assertTrue(all(args_of(c, "root") == [[RELEASE_ROOT]] for c in self.new_server_children if c.name == "location"))


class ProductionProperties(unittest.TestCase):
    def directives(self, text):
        return "\n".join(l.split("#")[0] for l in text.splitlines())

    def test_no_basic_auth_no_noindex_and_no_webhook_exemption(self):
        for text in (BODY_TEMPLATE, HTTP_TEMPLATE, CLASSIC_TEMPLATE):
            for forbidden in ("auth_basic", "htpasswd", "X-Robots-Tag", "/webhooks/stripe", "limit_except", "satisfy", "allow "):
                self.assertNotIn(forbidden, self.directives(text))

    def test_rate_limit_headers_tls_and_logs(self):
        self.assertEqual(args_of(BODY, "limit_req"), [["zone=vasey_production_general", "burst=200", "nodelay"]])
        self.assertEqual(args_of(BODY, "limit_req_status"), [["429"]])
        self.assertTrue(all(args_of(node, "limit_req") == [] for node in LOCATIONS))
        self.assertEqual({tuple(a) for a in args_of(BODY, "add_header")}, {
            ("Strict-Transport-Security", "max-age=31536000", "always"),
            ("X-Content-Type-Options", "nosniff", "always"),
            ("Referrer-Policy", "$vasey_production_referrer_policy", "always"),
            ("X-Frame-Options", "$vasey_production_frame_options", "always"),
            ("Permissions-Policy", "camera=(), microphone=(), geolocation=(), usb=(), interest-cohort=()", "always"),
        })
        self.assertEqual({tuple(a) for a in args_of(location("^~", "/build/"), "add_header")},
                         {("Cache-Control", "public, immutable", "always"), ("Strict-Transport-Security", "max-age=31536000", "always"),
                          ("X-Content-Type-Options", "nosniff", "always")})
        self.assertEqual(args_of(BODY, "ssl_session_cache"), [["shared:VaseyProductionSSL:10m"]])
        self.assertEqual(args_of(BODY, "ssl_session_tickets"), [["off"]])
        self.assertEqual(args_of(BODY, "access_log"), [["/var/log/nginx/vasey-production-access.log", "vasey_production"]])
        self.assertEqual(args_of(BODY, "error_log"), [["stderr", "error"]])
        self.assertEqual(args_of(BODY, "fastcgi_temp_path"), [["/var/lib/nginx/vasey-production-fastcgi", "1", "2"]])

    def test_paid_delivery_pool_routing_and_upload_limits(self):
        sockets = {}
        for node in LOCATIONS:
            for args in args_of(node, "fastcgi_pass"):
                sockets.setdefault(args[0], []).append(node.args[-1])
        self.assertEqual(sorted(sockets["unix:/run/php/vasey-paid-delivery.sock"]),
                         ["^/paid-grants/authorizations/[0-9a-f-]{36}/redeem$", "^/paid-grants/origins/[0-9a-f-]{36}/document$"])
        self.assertEqual(set(sockets), {"unix:/run/php/vasey-production.sock", "unix:/run/php/vasey-paid-delivery.sock"})
        redeem = location("~", "^/paid-grants/authorizations/[0-9a-f-]{36}/redeem$")
        document = location("~", "^/paid-grants/origins/[0-9a-f-]{36}/document$")
        for node, timeout in ((redeem, "480s"), (document, "3900s")):
            self.assertEqual(args_of(node, "fastcgi_read_timeout"), [[timeout]])
            self.assertEqual(args_of(node, "fastcgi_connect_timeout"), [["5s"]])
            self.assertEqual(args_of(node, "client_max_body_size"), [["1m"]])
        self.assertEqual(args_of(location("~", "^/livewire-[0-9a-f]{8}/upload-file$"), "client_max_body_size"), [["210m"]])
        self.assertEqual(args_of(BODY, "client_max_body_size"), [["16m"]])
        for node in LOCATIONS:
            if node.find("fastcgi_pass"):
                params = {a[0]: a[1:] for a in args_of(node, "fastcgi_param")}
                self.assertEqual(params["SCRIPT_FILENAME"], ["$realpath_root/index.php"], node)
                self.assertEqual(params["DOCUMENT_ROOT"], ["$realpath_root"], node)
                self.assertEqual(params["HTTP_PROXY"], [""], node)

    def test_the_access_log_mask(self):
        mapping = next(node for node in HTTP.find("map") if node.args[1] == "$vasey_production_logged_uri")
        pattern, replacement = mapping.children[0].name, mapping.children[0].args[0]
        regex = re.compile(pattern.lstrip("~").replace("(?<", "(?P<"))
        for path, logged in (("/paid-grants/authorizations/0f0e0d0c-0b0a-0908-0706-050403020100/redeem?sig=x",
                              "/paid-grants/authorizations/:uuid/redeem"),
                             ("/paid-grants/origins/0f0e0d0c-0b0a-0908-0706-050403020100/document", "/paid-grants/origins/:uuid/document")):
            match = regex.match(path)
            self.assertIsNotNone(match, path)
            self.assertEqual(replacement.replace("${head}", match["head"]).replace("${tail}", match["tail"]), logged)
        self.assertIsNone(regex.match("/paid-grants/authorizations/0f0e0d0c/redeem"))


class Provisioning(unittest.TestCase):
    def test_provision_renders_the_body_and_installs_the_conf_d_file_under_nginx_t(self):
        self.assertIn('render "$KIT/nginx/vasey-production.conf" /etc/vasey-production/nginx-site.conf 0644\n', PROVISION)
        self.assertIn('render "$KIT/nginx/vasey-production-classic.conf" /etc/vasey-production/nginx-classic.conf 0644\n', PROVISION)
        self.assertIn("NGINX_HTTP_CONF=/etc/nginx/conf.d/vasey-production.conf\n", PROVISION)
        duplicates = PROVISION.index("nginx_dupes=$(grep -rlE")
        install = PROVISION.index('install -m 0644 -o root -g root "$KIT/nginx/vasey-production-http.conf" "$NGINX_HTTP_CONF.tmp"')
        test = PROVISION.index("if ! nginx_test=$(nginx -t 2>&1); then")
        rollback = PROVISION.index('if [ -n "$nginx_previous" ]; then mv -f "$nginx_previous" "$NGINX_HTTP_CONF"; else rm -f "$NGINX_HTTP_CONF"; fi')
        reload = PROVISION.index("systemctl reload nginx")
        self.assertLess(duplicates, install)
        self.assertLess(install, test)
        self.assertLess(test, rollback)
        self.assertLess(rollback, reload)
        checks = PROVISION.split("# ---------------------------------------------------------------- 0. host checks")[0]
        for name in ("nginx/vasey-production.conf", "nginx/vasey-production-http.conf", "nginx/vasey-production-classic.conf"):
            self.assertIn(name, checks)
        self.assertNotIn("fill __SSL_CERT__", PROVISION)

    def duplicate_grep(self):
        match = re.search(r"nginx_dupes=\$\((grep -rlE .*?) /etc/nginx 2>/dev/null \|\| true\)", PROVISION, re.S)
        self.assertIsNotNone(match)
        return match.group(1)

    def test_the_duplicate_definition_check_finds_the_old_template_and_nothing_the_kit_installs(self):
        grep = self.duplicate_grep()
        self.assertNotIn("webhook", grep)
        self.assertNotIn("robots", grep)
        with tempfile.TemporaryDirectory() as directory:
            etc = Path(directory) / "nginx"
            (etc / "conf.d").mkdir(parents=True)
            (etc / "forge-conf/1").mkdir(parents=True)
            (etc / "conf.d/vasey-production.conf").write_text(HTTP_TEMPLATE)  # the kit's own file: excluded
            (etc / "forge-conf/1/site.conf").write_text(render(BODY_TEMPLATE))
            (etc / "sites-available").mkdir()
            (etc / "sites-available/classic").write_text(render(CLASSIC_TEMPLATE))
            run = subprocess.run(["bash", "-c", f"{grep} {etc}"], capture_output=True, text=True)
            self.assertEqual((run.returncode, run.stdout), (1, ""), run.stderr)  # nothing found
            (etc / "sites-available/old-whole-file").write_text(render(OLD_TEMPLATE))
            run = subprocess.run(["bash", "-c", f"{grep} {etc}"], capture_output=True, text=True)
            self.assertEqual(run.stdout.split(), [str(etc / "sites-available/old-whole-file")])
            # Each kind of definition is found on its own: the zone, every map and the log format.
            for line in [l for l in OLD_TEMPLATE.splitlines() if re.match(r"(limit_req_zone|map|log_format) ", l)]:
                (etc / "sites-available/old-whole-file").write_text(line + "\n")
                run = subprocess.run(["bash", "-c", f"{grep} {etc}"], capture_output=True, text=True)
                self.assertEqual(run.returncode, 0, line)


# ---- live checks with nginx -------------------------------------------------------------------------------------------------
CHECKOUT_MARK = site.CHECKOUT_MARK
FORGE_ID = "4100001"
UUID = "0f0e0d0c-0b0a-0908-0706-050403020100"


@unittest.skipUnless(NGINX, "nginx is not installed")
class ForgeLayoutWithNginx(unittest.TestCase):
    """A synthetic Forge tree: nginx.conf including conf.d then sites-enabled, Forge's wrapper with the checkout as root."""

    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        tree = self.tree = Path(self.directory.name)
        os.chmod(tree, 0o755)
        self.port, self.redirect_port = site.free_port(), site.free_port()
        for sub in ("conf.d", "sites-available", "sites-enabled", f"forge-conf/{FORGE_ID}/before", f"forge-conf/{FORGE_ID}/after",
                    "logs", "fastcgi-temp", "run"):
            (tree / sub).mkdir(parents=True)
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(tree / "server.key"),
                        "-out", str(tree / "server.crt"), "-days", "1", "-subj", f"/CN={HOST}"], check=True, capture_output=True)
        shutil.copy("/etc/nginx/fastcgi_params", tree / "fastcgi_params")

        checkout = tree / "home/forge" / HOST
        for name in ("README.md", "composer.json", "artisan", ".env", "index.php", "public/index.php", "public/robots.txt",
                     "public/build/app.js", ".git/config", ".well-known/acme-challenge/token", "storage/app/private/master.wav"):
            (checkout / name).parent.mkdir(parents=True, exist_ok=True)
            (checkout / name).write_text(CHECKOUT_MARK + "\n")
        release = tree / "srv/vasey-production/releases/abc/public"
        for name, text in (("index.php", "<?php // release"), ("robots.txt", "RELEASE-ROBOTS\n"), ("build/app.js", "RELEASE-BUILD\n"),
                           ("favicon.ico", "RELEASE-ICON\n"), (".well-known/acme-challenge/token", "RELEASE-WELL-KNOWN\n")):
            (release / name).parent.mkdir(parents=True, exist_ok=True)
            (release / name).write_text(text)
        (tree / "srv/vasey-production/current").symlink_to(tree / "srv/vasey-production/releases/abc")
        for path in tree.rglob("*"):
            if not path.is_symlink():
                os.chmod(path, 0o755 if path.is_dir() else 0o644)
        os.chmod(tree / "run", 0o777)

        self.fpm, self.paid = tree / "run/fpm.sock", tree / "run/paid.sock"
        body = render(BODY_TEMPLATE, root=str(tree / "srv/vasey-production"))
        for old, new in (("/var/lib/nginx/vasey-production-fastcgi", str(tree / "fastcgi-temp")),
                         ("/var/log/nginx/", str(tree / "logs") + "/"),
                         ("unix:/run/php/vasey-production.sock", f"unix:{self.fpm}"),
                         ("unix:/run/php/vasey-paid-delivery.sock", f"unix:{self.paid}")):
            self.assertIn(old, body)
            body = body.replace(old, new)
        (tree / f"forge-conf/{FORGE_ID}/site.conf").write_text(body)
        (tree / "conf.d/vasey-production.conf").write_text(HTTP_TEMPLATE)
        (tree / f"forge-conf/{FORGE_ID}/before/redirect.conf").write_text(
            f"server {{\n    listen 127.0.0.1:{self.redirect_port};\n    server_name {HOST};\n    server_tokens off;\n"
            f"    return 301 https://$host$request_uri;\n}}\n")
        listen = (f"    http2 on;\n    listen 127.0.0.1:{self.port} ssl;\n" if site.nginx_version() >= (1, 25, 1)
                  else f"    listen 127.0.0.1:{self.port} ssl http2;\n")
        (tree / "sites-available" / HOST).write_text(
            f"include forge-conf/{FORGE_ID}/before/*;\n"
            f"server {{\n{listen}    server_name {HOST};\n    server_tokens off;\n    root {checkout};\n"
            f"    ssl_certificate {tree}/server.crt;\n    ssl_certificate_key {tree}/server.key;\n"
            f"    include forge-conf/{FORGE_ID}/site.conf;\n}}\n"
            f"include forge-conf/{FORGE_ID}/after/*;\n")
        (tree / "sites-enabled" / HOST).symlink_to(tree / "sites-available" / HOST)
        user = "user root;\n" if os.geteuid() == 0 else ""
        (tree / "nginx.conf").write_text(
            f"{user}worker_processes 1;\npid {tree}/nginx.pid;\nerror_log {tree}/logs/error.log;\nevents {{}}\nhttp {{\n"
            f"    access_log off;\n    client_body_temp_path {tree}/run/body;\n    proxy_temp_path {tree}/run/proxy;\n"
            f"    uwsgi_temp_path {tree}/run/uwsgi;\n    scgi_temp_path {tree}/run/scgi;\n"
            f"    include {tree}/conf.d/*.conf;\n    include {tree}/sites-enabled/*;\n}}\n")
        self.release = release

    def tearDown(self):
        self.directory.cleanup()

    def nginx(self, *args):
        return subprocess.run([NGINX, "-p", str(self.tree), "-c", str(self.tree / "nginx.conf"), *args], capture_output=True, text=True)

    def test_nginx_t_accepts_the_conf_d_file_and_the_body_inside_the_forge_wrapper(self):
        run = self.nginx("-t")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("test is successful", run.stderr)

    def test_nginx_t_refuses_the_body_without_the_conf_d_file(self):
        (self.tree / "conf.d/vasey-production.conf").unlink()
        self.assertNotEqual(self.nginx("-t").returncode, 0)

    def test_nginx_t_refuses_the_conf_d_file_next_to_the_old_template_definitions(self):
        old_http = "\n".join(l for l in OLD_TEMPLATE.split("include forge-conf/__FORGE_CONF__/before/*;")[0].splitlines())
        (self.tree / f"forge-conf/{FORGE_ID}/before/old-site.conf").write_text(old_http + "\n")
        run = self.nginx("-t")
        self.assertNotEqual(run.returncode, 0)
        self.assertRegex(run.stderr, r"already bound|duplicate")

    def test_nginx_t_accepts_the_classic_wrapper(self):
        classic = render(CLASSIC_TEMPLATE)
        listen = (f"listen 127.0.0.1:{self.port} ssl;\n    http2 on;" if site.nginx_version() >= (1, 25, 1)
                  else f"listen 127.0.0.1:{self.port} ssl http2;")
        for old, new in (("listen 443 ssl http2;", listen), ("    listen [::]:443 ssl http2;\n", ""),
                         ("__SSL_CERT__", str(self.tree / "server.crt")), ("__SSL_KEY__", str(self.tree / "server.key")),
                         ("__FORGE_CONF__", FORGE_ID),
                         ("/etc/vasey-production/nginx-site.conf", str(self.tree / f"forge-conf/{FORGE_ID}/site.conf"))):
            self.assertIn(old, classic)
            classic = classic.replace(old, new)
        (self.tree / "sites-available" / HOST).write_text(classic)
        run = self.nginx("-t")
        self.assertEqual(run.returncode, 0, run.stderr)

    def request(self, method, path, body=None):
        return site.ForgeLayoutWithNginx.request(self, method, path, auth=False, body=body)

    def test_live_requests_never_reach_the_checkout_and_keep_the_production_behaviour(self):
        fpm, paid = StubFastCGI(self.fpm, "default"), StubFastCGI(self.paid, "paid-delivery")
        fpm.start()
        paid.start()
        server = subprocess.Popen([NGINX, "-p", str(self.tree), "-c", str(self.tree / "nginx.conf"), "-g", "daemon off;"],
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            deadline = time.time() + 10
            while True:
                try:
                    socket.create_connection(("127.0.0.1", self.port), timeout=1).close()
                    break
                except OSError:
                    if server.poll() is not None or time.time() > deadline:
                        self.fail(f"nginx did not start: {(self.tree / 'logs/error.log').read_text()}")
                    time.sleep(0.1)
            release_script = f"SCRIPT_FILENAME={self.release}/index.php\nDOCUMENT_ROOT={self.release}\n"

            probes = ["/", "/README.md", "/composer.json", "/artisan", "/.env", "/.git/config", "/index.php", "/public/index.php",
                      "/public/robots.txt", "/public/build/app.js", "/storage/app/private/master.wav", "/%2eenv", "/README.md/",
                      "/.well-known/acme-challenge/token", "/build/../README.md", "/nonexistent", "/favicon.ico", "/robots.txt",
                      "/build/app.js", "/livewire-0123abcd/upload-file", "/webhooks/stripe",
                      f"/paid-grants/authorizations/{UUID}/redeem", f"/paid-grants/origins/{UUID}/document"]
            for path in probes:  # public: no credentials anywhere, and nothing from the checkout
                status, headers, text = self.request("GET", path)
                self.assertNotIn(CHECKOUT_MARK, text, path)
                self.assertNotEqual(status, 401, path)
                self.assertNotIn("www-authenticate", headers, path)
                self.assertNotIn("x-robots-tag", headers, path)
                self.assertEqual(headers.get("strict-transport-security"), "max-age=31536000", path)
                self.assertEqual(headers.get("x-content-type-options"), "nosniff", path)
                if status == 200 and "SCRIPT_FILENAME=" in text:
                    self.assertIn(release_script, text, path)

            self.assertEqual(self.request("GET", "/robots.txt")[2], "RELEASE-ROBOTS\n")
            self.assertEqual(self.request("GET", "/favicon.ico")[2], "RELEASE-ICON\n")
            self.assertEqual(self.request("GET", "/.well-known/acme-challenge/token")[2], "RELEASE-WELL-KNOWN\n")
            status, headers, text = self.request("GET", "/build/app.js")
            self.assertEqual((status, text, headers.get("cache-control")), (200, "RELEASE-BUILD\n", "public, immutable"))
            status, headers, text = self.request("GET", "/some/laravel/route?q=1")
            self.assertEqual(status, 200)
            self.assertIn(release_script, text)
            self.assertEqual(headers.get("referrer-policy"), "strict-origin-when-cross-origin")
            self.assertEqual(headers.get("x-frame-options"), "SAMEORIGIN")
            self.assertEqual(headers.get("permissions-policy"), "camera=(), microphone=(), geolocation=(), usb=(), interest-cohort=()")
            self.assertNotIn("x-frame-options", self.request("GET", "/embed/track")[1])
            for path in ("/.env", "/.git/config", "/public/index.php"):  # denied, then rendered by Laravel's 404 via /index.php
                status, _, text = self.request("GET", path)
                self.assertEqual(status, 404, path)
                self.assertIn(release_script, text, path)
            # No webhook exemption: the path is an ordinary Laravel route on the default pool, query string and all.
            status, _, text = self.request("POST", "/webhooks/stripe?x=1", body="{}")
            self.assertEqual(status, 200, text)
            self.assertIn("POOL=default\n" + release_script + "REQUEST_URI=/webhooks/stripe?x=1\n", text)
            for path in (f"/paid-grants/origins/{UUID}/document", f"/paid-grants/authorizations/{UUID}/redeem?sig=s3cret"):
                status, _, text = self.request("GET", path)
                self.assertEqual(status, 200, path)
                self.assertIn("POOL=paid-delivery\n" + release_script, text, path)
            self.assertIn("POOL=default\n" + release_script, self.request("GET", "/livewire-0123abcd/upload-file")[2])
        finally:
            server.terminate()
            server.wait(timeout=10)
            fpm.close()
            paid.close()
        log = (self.tree / "logs/vasey-production-access.log").read_text()
        self.assertIn('"GET /paid-grants/authorizations/:uuid/redeem" 200', log)
        self.assertIn('"GET /paid-grants/origins/:uuid/document" 200', log)
        self.assertIn('"GET /some/laravel/route?q=1" 200', log)
        self.assertNotIn(UUID, log)
        self.assertNotIn("s3cret", log)


if __name__ == "__main__":
    unittest.main()
