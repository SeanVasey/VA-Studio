"""The staging nginx kit on the current Forge layout: a conf.d http-level file plus a site.conf body inside Forge's wrapper.

Forge (Laravel VPS, nginx.org packages) generates the site file as a wrapper whose server block sets listen, http2,
server_name, server_tokens, the certificate and `root /home/forge/<host>` (the git checkout), then includes
forge-conf/<id>/site.conf, the only part its editor and API change. These tests read the real templates, render them as
provision.sh does, and prove:
  - the split: http-level directives only in the conf.d file, nothing the wrapper sets in the body;
  - the root strategy: every location sets the release root and a catch-all `location /` exists, so no request can be
    resolved against the wrapper's checkout root (static, plus a location-selection model over many paths);
  - every security property of the earlier whole-file template, statically;
  - when nginx is installed: `nginx -t` on a synthetic Forge tree, and a live run of that tree against a stub FastCGI
    responder showing that checkout files are never served and the access model holds.
No host is touched: everything runs in a temporary directory on loopback."""
import base64
import http.client
import importlib.util
import os
import re
import shutil
import socket
import ssl
import struct
import subprocess
import tempfile
import threading
import time
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
STAGING = REPO / "ops/staging"
PROVISION = (STAGING / "provision.sh").read_text()
BODY_TEMPLATE = (STAGING / "nginx/vasey-staging.conf").read_text()
HTTP_TEMPLATE = (STAGING / "nginx/vasey-staging-http.conf").read_text()
CLASSIC_TEMPLATE = (STAGING / "nginx/vasey-staging-classic.conf").read_text()
ROOT = "/srv/vasey-staging"
RELEASE_ROOT = f"{ROOT}/current/public"
HOST = "vasey-staging.on-forge.com"
NGINX = shutil.which("nginx")


def production_nginx():
    """The production kit's three nginx files, as ops/production/build-kit.py derives them from these."""
    spec = importlib.util.spec_from_file_location("build_kit", REPO / "ops/production/build-kit.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return "".join(module.derive(entry, STAGING) for entry in module.DERIVED if entry.dst.startswith("nginx/"))


def render(text, root=ROOT, host=HOST):
    """What provision.sh's render() does to these templates."""
    return text.replace("__VASEY_ROOT__", root).replace("__STAGING_HOST__", host)


# ---- a small nginx configuration parser -----------------------------------------------------------------------------------
class Node:
    def __init__(self, name, args, children=None):
        self.name, self.args, self.children = name, args, children

    def __repr__(self):
        return f"{self.name} {' '.join(self.args)}"

    def find(self, name):
        return [c for c in (self.children or []) if c.name == name]

    def walk(self):
        for child in self.children or []:
            yield child
            yield from child.walk()


def tokens(text):
    position, length = 0, len(text)
    while position < length:
        char = text[position]
        if char.isspace():
            position += 1
        elif char == "#":
            position = text.find("\n", position) if "\n" in text[position:] else length
        elif char in "{};":
            yield char
            position += 1
        elif char in "\"'":
            end = position + 1
            while text[end] != char:
                end += 2 if text[end] == "\\" else 1
            yield ("str", text[position + 1:end])
            position = end + 1
        else:
            end = position
            while end < length and not text[end].isspace() and text[end] not in "{};" and not (text[end] == "#" and end == position):
                end += 1
            yield ("str", text[position:end])
            position = end


def parse(text):
    stack, words = [Node("<root>", [], [])], []
    for token in tokens(text):
        if token == ";":
            stack[-1].children.append(Node(words[0], words[1:]))
            words = []
        elif token == "{":
            node = Node(words[0], words[1:], [])
            stack[-1].children.append(node)
            stack.append(node)
            words = []
        elif token == "}":
            assert not words, f"unterminated directive {words}"
            stack.pop()
        else:
            words.append(token[1])
    assert len(stack) == 1 and not words, "unbalanced braces"
    return stack[0]


def args_of(block, name):
    return [node.args for node in block.find(name)]


def select_location(locations, uri):
    """nginx's location selection: exact match, else longest prefix (stop if `^~`), else first matching regex, else that prefix."""
    best, regexes = None, []
    for node in locations:
        modifier, pattern = (node.args[0], node.args[1]) if len(node.args) == 2 else (None, node.args[0])
        if modifier == "=":
            if uri == pattern:
                return node
        elif modifier in ("~", "~*"):
            regexes.append((node, re.compile(pattern, re.I if modifier == "~*" else 0)))
        elif uri.startswith(pattern) and (best is None or len(pattern) > len(best.args[-1])):
            best = node
    if best is not None and best.args[0] == "^~":
        return best
    for node, regex in regexes:
        if regex.search(uri):
            return node
    return best


BODY = parse(render(BODY_TEMPLATE))
HTTP = parse(HTTP_TEMPLATE)
LOCATIONS = [node for node in BODY.walk() if node.name == "location"]


def location(*args):
    found = [node for node in LOCATIONS if node.args == list(args)]
    assert len(found) == 1, args
    return found[0]


class Split(unittest.TestCase):
    def test_placeholders_are_exactly_the_documented_sets(self):
        self.assertEqual(set(re.findall(r"__[A-Z_]+__", BODY_TEMPLATE)), {"__VASEY_ROOT__"})
        self.assertEqual(set(re.findall(r"__[A-Z_]+__", HTTP_TEMPLATE)), set())
        self.assertEqual(set(re.findall(r"__[A-Z_]+__", CLASSIC_TEMPLATE)),
                         {"__STAGING_HOST__", "__SSL_CERT__", "__SSL_KEY__", "__FORGE_CONF__"})
        self.assertNotRegex(render(BODY_TEMPLATE), r"__[A-Z_]+__")

    def test_the_conf_d_file_holds_only_http_level_helpers(self):
        self.assertEqual({node.name for node in HTTP.children}, {"limit_req_zone", "map"})
        zones = {re.match(r"zone=([a-z_]+):", a[1]).group(1): a for a in args_of(HTTP, "limit_req_zone")}
        self.assertEqual(zones, {
            "vasey_staging_general": ["$binary_remote_addr", "zone=vasey_staging_general:10m", "rate=30r/s"],
            "vasey_staging_webhook": ["$binary_remote_addr", "zone=vasey_staging_webhook:1m", "rate=10r/s"],
        })
        maps = {node.args[1]: (node.args[0], [c.name] + c.args) for node in HTTP.find("map") for c in node.children}
        self.assertEqual({node.args[1]: node.args[0] for node in HTTP.find("map")}, {
            "$vasey_staging_referrer_policy": "$upstream_http_referrer_policy",
            "$vasey_staging_robots": "$upstream_http_x_robots_tag",
            "$vasey_staging_frame_options": "$request_uri",
        })
        self.assertTrue(maps)
        by_var = {node.args[1]: {c.name: c.args[0] for c in node.children} for node in HTTP.find("map")}
        self.assertEqual(by_var["$vasey_staging_referrer_policy"], {"": "strict-origin-when-cross-origin", "default": ""})
        self.assertEqual(by_var["$vasey_staging_robots"], {"": "noindex, nofollow, noarchive", "default": ""})
        self.assertEqual(by_var["$vasey_staging_frame_options"], {"~^/embed/": "", "default": "SAMEORIGIN"})

    def test_every_zone_and_map_the_body_uses_is_defined_once_in_the_conf_d_file(self):
        defined_zones = {re.match(r"zone=([a-z_]+):", a[1]).group(1) for a in args_of(HTTP, "limit_req_zone")}
        used_zones = {a[0].split("=", 1)[1] for node in [BODY, *LOCATIONS] for a in args_of(node, "limit_req")}
        self.assertEqual(used_zones, defined_zones)
        defined_vars = {node.args[1] for node in HTTP.find("map")}
        used_vars = set(re.findall(r"\$vasey_staging_[a-z_]+", BODY_TEMPLATE))
        self.assertEqual(used_vars, defined_vars)
        # The production kit uses its own names, so both kits can share an nginx instance. Its nginx files are derived from these
        # by ops/production/build-kit.py (tests/ops/test_production_nginx_site.py covers them).
        production = production_nginx()
        for name in defined_zones | {v.lstrip("$") for v in defined_vars}:
            self.assertNotIn(name, production)

    def test_the_body_sets_nothing_the_forge_wrapper_owns_and_includes_nothing_forge_controls(self):
        top = {node.name for node in BODY.children}
        for owned in ("server", "http", "listen", "http2", "server_name", "server_tokens", "root", "ssl_certificate",
                      "ssl_certificate_key", "limit_req_zone", "map", "upstream"):
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
        self.assertEqual(args_of(servers[0], "include"), [["/etc/vasey-staging/nginx-site.conf"]])
        self.assertEqual(args_of(servers[0], "server_name"), [[HOST]])
        self.assertEqual([a for a in args_of(tree, "include")], [["forge-conf/__FORGE_CONF__/before/*"], ["forge-conf/__FORGE_CONF__/after/*"]])


class RootStrategy(unittest.TestCase):
    def test_every_location_sets_the_release_root_and_nothing_aliases(self):
        self.assertGreaterEqual(len(LOCATIONS), 10)
        for node in LOCATIONS:
            self.assertEqual(args_of(node, "root"), [[RELEASE_ROOT]], node)
        for node in BODY.walk():
            self.assertNotIn(node.name, ("alias", "autoindex"), node)
            if node.name == "root":
                self.assertEqual(node.args, [RELEASE_ROOT])
        self.assertEqual(BODY.find("root"), [])  # server level belongs to the wrapper

    def test_a_plain_prefix_catch_all_exists(self):
        catch_all = location("/")
        self.assertEqual(args_of(catch_all, "try_files"), [["$uri", "$uri/", "/index.php?$query_string"]])

    def test_no_request_path_falls_back_to_the_wrapper_root(self):
        top_level = BODY.find("location")
        self.assertEqual(top_level, LOCATIONS, "no nested locations")
        paths = ["/", "/README.md", "/composer.json", "/artisan", "/.env", "/.git/config", "/.well-known/acme-challenge/x",
                 "/public/index.php", "/index.php", "/index.php/webhooks/stripe", "/webhooks/stripe", "/webhooks/stripe/",
                 "/build/assets/app.js", "/build", "/favicon.ico", "/robots.txt", "/storage/app/private/x", "/embed/abc",
                 "/livewire-0123abcd/upload-file", "/livewire-0123abcd/update",
                 "/paid-grants/authorizations/00000000-0000-0000-0000-000000000000/redeem",
                 "/paid-grants/origins/00000000-0000-0000-0000-000000000000/document", "/vendor/autoload.php",
                 "/ops/staging/provision.sh", "/database/database.sqlite", "/x" * 50, "//double", "/%2e%2e/"]
        # Internal redirects land on paths too: try_files' and error_page's /index.php.
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
        self.assertEqual(select_location(LOCATIONS, "/webhooks/stripe/").args, ["/"])
        self.assertEqual(select_location(LOCATIONS, "/build/x.php").args, ["^~", "/build/"])


class SecurityProperties(unittest.TestCase):
    """Each property of the earlier whole-file template, mapped to where it now lives."""

    def test_basic_auth_everywhere_except_exactly_post_webhooks_stripe(self):
        self.assertEqual(args_of(BODY, "auth_basic"), [["VASEY.AUDIO staging"]])
        self.assertEqual(args_of(BODY, "auth_basic_user_file"), [["/etc/nginx/vasey-staging.htpasswd"]])
        exempt = [node for node in LOCATIONS if node.find("auth_basic")]
        self.assertEqual([node.args for node in exempt], [["=", "/webhooks/stripe"]])
        webhook = exempt[0]
        self.assertEqual(args_of(webhook, "auth_basic"), [["off"]])
        limit = webhook.find("limit_except")
        self.assertEqual(len(limit), 1)
        self.assertEqual(limit[0].args, ["POST"])
        self.assertEqual([(c.name, c.args) for c in limit[0].children], [("deny", ["all"])])
        self.assertEqual(args_of(webhook, "include"), [])  # parameters spelled out: no raw URI or query string reaches PHP
        params = {a[0]: a[1:] for a in args_of(webhook, "fastcgi_param")}
        self.assertEqual(params["REQUEST_URI"], ["/webhooks/stripe"])
        self.assertEqual(params["QUERY_STRING"], [""])
        self.assertEqual(params["HTTP_PROXY"], [""])
        self.assertEqual(params["HTTP_STRIPE_SIGNATURE"], ["$http_stripe_signature"])
        self.assertEqual(args_of(webhook, "fastcgi_pass"), [["unix:/run/php/vasey-staging.sock"]])
        self.assertEqual(args_of(webhook, "fastcgi_read_timeout"), [["60s"]])
        for node in BODY.walk():
            if node.name in ("satisfy", "allow"):
                self.fail(f"{node} would weaken basic auth")

    def test_rate_limits(self):
        self.assertEqual(args_of(BODY, "limit_req"), [["zone=vasey_staging_general", "burst=200", "nodelay"]])
        self.assertEqual(args_of(BODY, "limit_req_status"), [["429"]])
        self.assertEqual(args_of(location("=", "/webhooks/stripe"), "limit_req"), [["zone=vasey_staging_webhook", "burst=40", "nodelay"]])
        for node in LOCATIONS:
            if node.args != ["=", "/webhooks/stripe"]:
                self.assertEqual(args_of(node, "limit_req"), [], node)

    def test_security_headers_on_every_response(self):
        server = {tuple(a) for a in args_of(BODY, "add_header")}
        self.assertEqual(server, {
            ("Strict-Transport-Security", "max-age=31536000", "always"),
            ("X-Content-Type-Options", "nosniff", "always"),
            ("Referrer-Policy", "$vasey_staging_referrer_policy", "always"),
            ("X-Frame-Options", "$vasey_staging_frame_options", "always"),
            ("X-Robots-Tag", "$vasey_staging_robots", "always"),
            ("Permissions-Policy", "camera=(), microphone=(), geolocation=(), usb=(), interest-cohort=()", "always"),
        })
        # A location with its own add_header inherits none from the server, so it must repeat the ones that always apply.
        for node in LOCATIONS:
            own = {tuple(a) for a in args_of(node, "add_header")}
            if own:
                self.assertEqual(node.args, ["^~", "/build/"])
                self.assertEqual(own, {("Cache-Control", "public, immutable", "always"),
                                       ("Strict-Transport-Security", "max-age=31536000", "always"),
                                       ("X-Content-Type-Options", "nosniff", "always"),
                                       ("X-Robots-Tag", "noindex, nofollow, noarchive", "always")})
        self.assertEqual(args_of(BODY, "fastcgi_hide_header"), [["X-Powered-By"]])

    def test_tls_session_settings_the_wrapper_does_not_set(self):
        self.assertEqual(args_of(BODY, "ssl_protocols"), [["TLSv1.2", "TLSv1.3"]])
        self.assertEqual(args_of(BODY, "ssl_session_tickets"), [["off"]])
        self.assertEqual(args_of(BODY, "ssl_session_cache"), [["shared:VaseyStagingSSL:10m"]])

    def test_upload_limits_and_timeouts(self):
        self.assertEqual(args_of(BODY, "client_max_body_size"), [["16m"]])
        self.assertEqual(args_of(BODY, "client_body_timeout"), [["120s"]])
        self.assertEqual(args_of(BODY, "send_timeout"), [["120s"]])
        livewire = location("~", "^/livewire-[0-9a-f]{8}/upload-file$")
        self.assertEqual(args_of(livewire, "client_max_body_size"), [["210m"]])
        self.assertEqual(args_of(livewire, "client_body_timeout"), [["300s"]])
        self.assertEqual(args_of(livewire, "fastcgi_read_timeout"), [["300s"]])
        self.assertEqual(args_of(location("=", "/webhooks/stripe"), "client_max_body_size"), [["1m"]])
        self.assertEqual(args_of(location("=", "/index.php"), "fastcgi_read_timeout"), [["300s"]])
        self.assertEqual(args_of(location("=", "/index.php"), "internal"), [[]])

    def test_private_fastcgi_temp_path(self):
        self.assertEqual(args_of(BODY, "fastcgi_temp_path"), [["/var/lib/nginx/vasey-staging-fastcgi", "1", "2"]])
        self.assertEqual(args_of(BODY, "fastcgi_buffering"), [["on"]])
        self.assertEqual(args_of(BODY, "fastcgi_max_temp_file_size"), [["1024m"]])
        self.assertIn('initialize(nginx, "vasey-staging-fastcgi"', PROVISION)

    def test_dotfiles_and_other_php_entry_points_are_denied_but_well_known_is_not(self):
        dot = location("~", r"/\.(?!well-known/)")
        php = location("~", r"\.php$")
        self.assertEqual(args_of(dot, "return"), [["404"]])
        self.assertEqual(args_of(php, "return"), [["404"]])
        pattern = re.compile(dot.args[1])
        for path in ("/.env", "/.git/config", "/build/.hidden", "/.well-knownx", "/.htaccess"):
            self.assertTrue(pattern.search(path), path)
        self.assertFalse(pattern.search("/.well-known/acme-challenge/token"))
        # Regex locations are checked in order and the dotfile rule comes first among those a dotfile could also match.
        regex_order = [node.args[1] for node in LOCATIONS if node.args[0] == "~"]
        self.assertLess(regex_order.index(r"/\.(?!well-known/)"), regex_order.index(r"\.php$"))
        self.assertEqual(args_of(BODY, "error_page"), [["404", "/index.php"]])

    def test_paid_delivery_pool_routing(self):
        sockets = {}
        for node in LOCATIONS:
            for args in args_of(node, "fastcgi_pass"):
                sockets.setdefault(args[0], []).append(node.args[-1])
        self.assertEqual(sorted(sockets["unix:/run/php/vasey-paid-delivery.sock"]),
                         ["^/paid-grants/authorizations/[0-9a-f-]{36}/redeem$", "^/paid-grants/origins/[0-9a-f-]{36}/document$"])
        self.assertEqual(set(sockets), {"unix:/run/php/vasey-staging.sock", "unix:/run/php/vasey-paid-delivery.sock"})
        redeem = location("~", "^/paid-grants/authorizations/[0-9a-f-]{36}/redeem$")
        document = location("~", "^/paid-grants/origins/[0-9a-f-]{36}/document$")
        for node, timeout in ((redeem, "480s"), (document, "3900s")):
            self.assertEqual(args_of(node, "fastcgi_read_timeout"), [[timeout]])
            self.assertEqual(args_of(node, "fastcgi_connect_timeout"), [["5s"]])
            self.assertEqual(args_of(node, "client_max_body_size"), [["1m"]])
        self.assertEqual(args_of(redeem, "fastcgi_buffering"), [["on"]])
        self.assertEqual(args_of(redeem, "fastcgi_max_temp_file_size"), [["1024m"]])

    def test_every_fastcgi_location_derives_paths_from_the_release_root(self):
        for node in LOCATIONS:
            if node.find("fastcgi_pass"):
                params = {a[0]: a[1:] for a in args_of(node, "fastcgi_param")}
                self.assertEqual(params["SCRIPT_FILENAME"], ["$realpath_root/index.php"], node)
                self.assertEqual(params["DOCUMENT_ROOT"], ["$realpath_root"], node)
                self.assertEqual(params["HTTP_PROXY"], [""], node)

    def test_logs(self):
        self.assertEqual(args_of(BODY, "access_log"), [["/var/log/nginx/vasey-staging-access.log"]])
        self.assertEqual(args_of(BODY, "error_log"), [["/var/log/nginx/vasey-staging-error.log", "error"]])


class Provisioning(unittest.TestCase):
    def test_provision_renders_the_body_and_installs_the_conf_d_file_under_nginx_t(self):
        self.assertIn('render "$KIT/nginx/vasey-staging.conf" /etc/vasey-staging/nginx-site.conf 0644\n', PROVISION)
        self.assertIn('render "$KIT/nginx/vasey-staging-classic.conf" /etc/vasey-staging/nginx-classic.conf 0644\n', PROVISION)
        self.assertIn("NGINX_HTTP_CONF=/etc/nginx/conf.d/vasey-staging.conf\n", PROVISION)
        install = PROVISION.index('install -m 0644 -o root -g root "$KIT/nginx/vasey-staging-http.conf" "$NGINX_HTTP_CONF.tmp"')
        test = PROVISION.index("if ! nginx_test=$(nginx -t 2>&1); then")
        rollback = PROVISION.index('if [ -n "$nginx_previous" ]; then mv -f "$nginx_previous" "$NGINX_HTTP_CONF"; else rm -f "$NGINX_HTTP_CONF"; fi')
        reload = PROVISION.index("systemctl reload nginx")
        duplicates = PROVISION.index("nginx_dupes=$(grep -rlE")
        self.assertLess(duplicates, install)
        self.assertLess(install, test)
        self.assertLess(test, rollback)
        self.assertLess(rollback, reload)
        # The temporary name does not match conf.d/*.conf, so a half-written file is never included.
        self.assertFalse(re.fullmatch(r".*\.conf", "vasey-staging.conf.tmp"))
        for name in ("nginx/vasey-staging-http.conf", "nginx/vasey-staging-classic.conf"):
            self.assertIn(name, PROVISION.split("# ---------------------------------------------------------------- 0. host checks")[0])

    def test_the_duplicate_definition_check_matches_the_earlier_whole_file_template(self):
        match = re.search(r"grep -rlE --exclude-dir=conf\.d -e '([^']+)' \\\n  -e '([^']+)' /etc/nginx", PROVISION)
        self.assertIsNotNone(match)
        zone, mapping = (re.compile(p.replace("[^[:space:]]", r"\S").replace("[[:space:]]", r"\s")) for p in match.groups())
        old_template = HTTP_TEMPLATE  # the earlier whole-file template carried these exact lines
        self.assertTrue(any(zone.search(line) for line in old_template.splitlines()))
        self.assertEqual(sum(bool(mapping.search(line)) for line in old_template.splitlines()), 3)
        self.assertFalse(any(zone.search(line) or mapping.search(line) for line in BODY_TEMPLATE.splitlines()))


# ---- live checks with nginx -------------------------------------------------------------------------------------------------
CHECKOUT_MARK = "CHECKOUT-MIRROR-MUST-NOT-BE-SERVED"
FORGE_ID = "3487933"


def free_port():
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        return probe.getsockname()[1]


def nginx_version():
    out = subprocess.run([NGINX, "-v"], capture_output=True, text=True).stderr
    return tuple(int(part) for part in re.search(r"nginx/(\d+)\.(\d+)\.(\d+)", out).groups())


class StubFastCGI(threading.Thread):
    """Answers every FastCGI request with 200 and the parameters nginx sent, one line each."""

    def __init__(self, path, label):
        super().__init__(daemon=True)
        self.label = label
        self.server = socket.socket(socket.AF_UNIX)
        self.server.bind(str(path))
        os.chmod(path, 0o777)
        self.server.listen(16)

    def run(self):
        while True:
            try:
                connection, _ = self.server.accept()
            except OSError:
                return
            with connection:
                try:
                    self.answer(connection)
                except (OSError, struct.error):
                    pass

    @staticmethod
    def read(connection, count):
        data = b""
        while len(data) < count:
            chunk = connection.recv(count - len(data))
            if not chunk:
                raise OSError("closed")
            data += chunk
        return data

    def answer(self, connection):
        params, request_id = b"", 1
        while True:
            _, kind, request_id, length, padding, _ = struct.unpack(">BBHHBB", self.read(connection, 8))
            content = self.read(connection, length)
            self.read(connection, padding)
            if kind == 4:
                params += content
            if kind == 5 and length == 0:
                break
        values, position = {}, 0
        while position < len(params):
            sizes = []
            for _ in range(2):
                size = params[position]
                if size >> 7:
                    size = struct.unpack(">I", params[position:position + 4])[0] & 0x7FFFFFFF
                    position += 4
                else:
                    position += 1
                sizes.append(size)
            name = params[position:position + sizes[0]].decode()
            values[name] = params[position + sizes[0]:position + sizes[0] + sizes[1]].decode()
            position += sizes[0] + sizes[1]
        body = f"POOL={self.label}\n" + "".join(f"{k}={values.get(k, '')}\n" for k in ("SCRIPT_FILENAME", "DOCUMENT_ROOT", "REQUEST_URI", "QUERY_STRING"))
        out = ("Status: 200 OK\r\nContent-Type: text/plain\r\n\r\n" + body).encode()
        connection.sendall(struct.pack(">BBHHBB", 1, 6, request_id, len(out), 0, 0) + out)
        connection.sendall(struct.pack(">BBHHBB", 1, 6, request_id, 0, 0, 0))
        connection.sendall(struct.pack(">BBHHBB", 1, 3, request_id, 8, 0, 0) + b"\0" * 8)

    def close(self):
        self.server.close()


@unittest.skipUnless(NGINX, "nginx is not installed")
class ForgeLayoutWithNginx(unittest.TestCase):
    """A synthetic Forge tree: nginx.conf including conf.d then sites-enabled, Forge's wrapper with the checkout as root."""

    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.tree = Path(self.directory.name)
        os.chmod(self.tree, 0o755)
        self.port, self.redirect_port = free_port(), free_port()
        tree = self.tree
        for sub in ("conf.d", "sites-available", "sites-enabled", f"forge-conf/{FORGE_ID}/before", f"forge-conf/{FORGE_ID}/after",
                    f"forge-conf/{FORGE_ID}/{HOST}/before", f"forge-conf/{FORGE_ID}/{HOST}/after", "logs", "fastcgi-temp", "run"):
            (tree / sub).mkdir(parents=True)
        os.chmod(tree / "run", 0o777)
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(tree / "server.key"),
                        "-out", str(tree / "server.crt"), "-days", "1", "-subj", f"/CN={HOST}"], check=True, capture_output=True)
        os.chmod(tree / "server.key", 0o644)
        shutil.copy("/etc/nginx/fastcgi_params", tree / "fastcgi_params")
        hashed = subprocess.run(["openssl", "passwd", "-6", "-stdin"], input="correct-horse-battery", capture_output=True,
                                text=True, check=True).stdout.strip()
        (tree / "htpasswd").write_text(f"operator:{hashed}\n")
        os.chmod(tree / "htpasswd", 0o644)

        # The checkout (Forge's wrapper root) and the release that `current` points to.
        checkout = tree / "home/forge" / HOST
        for name in ("README.md", "composer.json", "artisan", ".env", "index.php", "public/index.php", "public/robots.txt",
                     "public/build/app.js", ".git/config", ".well-known/acme-challenge/token", "storage/app/private/master.wav"):
            (checkout / name).parent.mkdir(parents=True, exist_ok=True)
            (checkout / name).write_text(CHECKOUT_MARK + "\n")
        release = tree / "srv/vasey-staging/releases/abc/public"
        for name, text in (("index.php", "<?php // release"), ("robots.txt", "RELEASE-ROBOTS\n"), ("build/app.js", "RELEASE-BUILD\n"),
                           ("favicon.ico", "RELEASE-ICON\n"), (".well-known/acme-challenge/token", "RELEASE-WELL-KNOWN\n")):
            (release / name).parent.mkdir(parents=True, exist_ok=True)
            (release / name).write_text(text)
        (tree / "srv/vasey-staging/current").symlink_to(tree / "srv/vasey-staging/releases/abc")
        for path in tree.rglob("*"):
            if not path.is_symlink():
                os.chmod(path, 0o755 if path.is_dir() else 0o644)
        os.chmod(tree / "run", 0o777)

        self.fpm = tree / "run/fpm.sock"
        self.paid = tree / "run/paid.sock"
        body = render(BODY_TEMPLATE, root=str(tree / "srv/vasey-staging"))
        for old, new in (("/etc/nginx/vasey-staging.htpasswd", str(tree / "htpasswd")),
                         ("/var/lib/nginx/vasey-staging-fastcgi", str(tree / "fastcgi-temp")),
                         ("/var/log/nginx/", str(tree / "logs") + "/"),
                         ("unix:/run/php/vasey-staging.sock", f"unix:{self.fpm}"),
                         ("unix:/run/php/vasey-paid-delivery.sock", f"unix:{self.paid}")):
            self.assertIn(old, body)
            body = body.replace(old, new)
        (tree / f"forge-conf/{FORGE_ID}/site.conf").write_text(body)
        (tree / "conf.d/vasey-staging.conf").write_text(HTTP_TEMPLATE)
        (tree / f"forge-conf/{FORGE_ID}/{HOST}/before/redirect.conf").write_text(
            f"server {{\n    listen 127.0.0.1:{self.redirect_port};\n    server_name {HOST};\n    server_tokens off;\n"
            f"    return 301 https://$host$request_uri;\n}}\n")
        listen = (f"    http2 on;\n    listen 127.0.0.1:{self.port} ssl;\n" if nginx_version() >= (1, 25, 1)
                  else f"    listen 127.0.0.1:{self.port} ssl http2;\n")
        wrapper = (f"include forge-conf/{FORGE_ID}/before/*;\ninclude forge-conf/{FORGE_ID}/{HOST}/before/*;\n"
                   f"server {{\n{listen}    server_name {HOST};\n    server_tokens off;\n    root {checkout};\n"
                   f"    ssl_certificate {tree}/server.crt;\n    ssl_certificate_key {tree}/server.key;\n"
                   f"    include forge-conf/{FORGE_ID}/site.conf;\n}}\n"
                   f"include forge-conf/{FORGE_ID}/after/*;\ninclude forge-conf/{FORGE_ID}/{HOST}/after/*;\n")
        (tree / "sites-available" / HOST).write_text(wrapper)
        (tree / "sites-enabled" / HOST).symlink_to(tree / "sites-available" / HOST)
        user = "user root;\n" if os.geteuid() == 0 else ""
        (tree / "nginx.conf").write_text(
            f"{user}worker_processes 1;\npid {tree}/nginx.pid;\nerror_log {tree}/logs/error.log;\nevents {{}}\nhttp {{\n"
            f"    access_log off;\n    client_body_temp_path {tree}/run/body;\n    proxy_temp_path {tree}/run/proxy;\n"
            f"    uwsgi_temp_path {tree}/run/uwsgi;\n    scgi_temp_path {tree}/run/scgi;\n"
            f"    include {tree}/conf.d/*.conf;\n    include {tree}/sites-enabled/*;\n}}\n")
        self.checkout = checkout
        self.release = (tree / "srv/vasey-staging/releases/abc/public")

    def tearDown(self):
        self.directory.cleanup()

    def nginx(self, *args):
        return subprocess.run([NGINX, "-p", str(self.tree), "-c", str(self.tree / "nginx.conf"), *args],
                              capture_output=True, text=True)

    def test_nginx_t_accepts_the_conf_d_file_and_the_body_inside_the_forge_wrapper(self):
        run = self.nginx("-t")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("test is successful", run.stderr)

    def test_nginx_t_refuses_the_body_without_the_conf_d_file(self):
        (self.tree / "conf.d/vasey-staging.conf").unlink()
        run = self.nginx("-t")
        self.assertNotEqual(run.returncode, 0)

    def test_nginx_t_refuses_the_conf_d_file_twice(self):
        # What provision.sh's duplicate check guards against: an older whole-file site still defining the same names.
        shutil.copy(self.tree / "conf.d/vasey-staging.conf", self.tree / f"forge-conf/{FORGE_ID}/before/old-site.conf")
        run = self.nginx("-t")
        self.assertNotEqual(run.returncode, 0)

    def test_nginx_t_accepts_the_classic_wrapper(self):
        classic = render(CLASSIC_TEMPLATE)
        site = self.tree / f"forge-conf/{FORGE_ID}/site.conf"
        listen = (f"listen 127.0.0.1:{self.port} ssl;\n    http2 on;" if nginx_version() >= (1, 25, 1)
                  else f"listen 127.0.0.1:{self.port} ssl http2;")
        for old, new in (("listen 443 ssl http2;", listen), ("    listen [::]:443 ssl http2;\n", ""),
                         ("__SSL_CERT__", str(self.tree / "server.crt")), ("__SSL_KEY__", str(self.tree / "server.key")),
                         ("__FORGE_CONF__", FORGE_ID), ("/etc/vasey-staging/nginx-site.conf", str(site))):
            self.assertIn(old, classic)
            classic = classic.replace(old, new)
        (self.tree / "sites-available" / HOST).write_text(classic)
        run = self.nginx("-t")
        self.assertEqual(run.returncode, 0, run.stderr)

    def request(self, method, path, auth=True, body=None):
        context = ssl.create_default_context()
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
        connection = http.client.HTTPSConnection("127.0.0.1", self.port, context=context, timeout=10)
        headers = {"Host": HOST}
        if auth:
            headers["Authorization"] = "Basic " + base64.b64encode(b"operator:correct-horse-battery").decode()
        try:
            connection.putrequest(method, path, skip_host=True, skip_accept_encoding=True)
            for name, value in headers.items():
                connection.putheader(name, value)
            data = body.encode() if body is not None else None
            if data is not None:
                connection.putheader("Content-Type", "application/json")
                connection.putheader("Content-Length", str(len(data)))
            connection.endheaders(data)
            response = connection.getresponse()
            return response.status, {k.lower(): v for k, v in response.getheaders()}, response.read().decode(errors="replace")
        finally:
            connection.close()

    def test_live_requests_never_reach_the_checkout_and_keep_the_access_model(self):
        fpm, paid = StubFastCGI(self.fpm, "default"), StubFastCGI(self.paid, "paid-delivery")
        fpm.start()
        paid.start()
        server = subprocess.Popen([NGINX, "-p", str(self.tree), "-c", str(self.tree / "nginx.conf"), "-g", "daemon off;"],
                                  stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        try:
            deadline = time.time() + 10
            while True:
                try:
                    socket.create_connection(("127.0.0.1", self.port), timeout=1).close()
                    break
                except OSError:
                    if server.poll() is not None or time.time() > deadline:
                        self.fail(f"nginx did not start: {server.stderr.read().decode()}")
                    time.sleep(0.1)
            release_script = f"SCRIPT_FILENAME={self.release}/index.php\nDOCUMENT_ROOT={self.release}\n"

            # Without credentials only POST /webhooks/stripe passes nginx.
            for method, path in (("GET", "/"), ("GET", "/README.md"), ("GET", "/webhooks/stripe/"), ("POST", "/webhooks/stripe/"),
                                 ("POST", "/index.php/webhooks/stripe"), ("GET", "/build/app.js"), ("GET", "/robots.txt"),
                                 ("GET", "/.well-known/acme-challenge/token")):
                status, _, text = self.request(method, path, auth=False)
                self.assertEqual(status, 401, (method, path))
                self.assertNotIn(CHECKOUT_MARK, text)
            status, _, text = self.request("GET", "/webhooks/stripe", auth=False)
            self.assertEqual(status, 403)
            status, headers, text = self.request("POST", "/webhooks/stripe?x=1", auth=False, body="{}")
            self.assertEqual(status, 200, text)
            self.assertIn("POOL=default\n" + release_script, text)
            self.assertIn("REQUEST_URI=/webhooks/stripe\nQUERY_STRING=\n", text)
            self.assertEqual(headers.get("x-frame-options"), "SAMEORIGIN")

            # With credentials: nothing from the checkout, whatever the path.
            probes = ["/", "/README.md", "/composer.json", "/artisan", "/.env", "/.git/config", "/index.php", "/public/index.php",
                      "/public/robots.txt", "/public/build/app.js", "/storage/app/private/master.wav", "/%2eenv", "/README.md/",
                      "/.well-known/acme-challenge/token", "/build/../README.md", "/nonexistent", "/favicon.ico", "/robots.txt",
                      "/build/app.js", "/livewire-0123abcd/upload-file",
                      "/paid-grants/authorizations/00000000-0000-0000-0000-000000000000/redeem",
                      "/paid-grants/origins/00000000-0000-0000-0000-000000000000/document"]
            for path in probes:
                status, headers, text = self.request("GET", path)
                self.assertNotIn(CHECKOUT_MARK, text, path)
                self.assertNotEqual(status, 401, path)
                self.assertEqual(headers.get("strict-transport-security"), "max-age=31536000", path)
                self.assertEqual(headers.get("x-content-type-options"), "nosniff", path)
                self.assertIn("noindex", headers.get("x-robots-tag", ""), path)
                if status == 200 and "SCRIPT_FILENAME=" in text:
                    self.assertIn(release_script, text, path)

            self.assertEqual(self.request("GET", "/robots.txt")[2], "RELEASE-ROBOTS\n")
            self.assertEqual(self.request("GET", "/favicon.ico")[2], "RELEASE-ICON\n")
            self.assertEqual(self.request("GET", "/.well-known/acme-challenge/token")[2], "RELEASE-WELL-KNOWN\n")
            status, headers, text = self.request("GET", "/build/app.js")
            self.assertEqual((status, text), (200, "RELEASE-BUILD\n"))
            self.assertEqual(headers.get("cache-control"), "public, immutable")
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
            for path in ("/paid-grants/origins/00000000-0000-0000-0000-000000000000/document",
                         "/paid-grants/authorizations/00000000-0000-0000-0000-000000000000/redeem"):
                status, _, text = self.request("GET", path)
                self.assertEqual(status, 200, path)
                self.assertIn("POOL=paid-delivery\n" + release_script, text, path)
            status, _, text = self.request("GET", "/livewire-0123abcd/upload-file")
            self.assertIn("POOL=default\n" + release_script, text)
        finally:
            server.terminate()
            server.wait(timeout=10)
            server.stderr.close()
            fpm.close()
            paid.close()


if __name__ == "__main__":
    unittest.main()
