#!/usr/bin/env python3
"""Real nginx/FPM, owned temporary prefix, synthetic responder and credentials only."""
import base64
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import socket
import ssl
import subprocess
import tempfile
import time

REPO = Path(__file__).resolve().parents[4]
TOOLS = Path("/workspace/.va-studio-toolchain/root")
NGINX = str(TOOLS / "usr/sbin/nginx")
FPM = str(TOOLS / "usr/sbin/php-fpm8.4")
PORT = 18449
HOST = "staging.example.invalid"
USERNAME = "review"
PASSWORD = "synthetic-review-password-20261009"


def run(args, **kwargs):
    result = subprocess.run(args, text=True, capture_output=True, **kwargs)
    if result.returncode:
        print(result.stdout + result.stderr, flush=True)
        result.check_returncode()
    return result


def stop(proc):
    if proc and proc.poll() is None:
        proc.terminate()
        try:
            proc.wait(timeout=10)
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.wait(timeout=5)


def main():
    nginx = fpm = None
    checks = 0
    with tempfile.TemporaryDirectory(prefix="vao-ng-") as scratch:
        root = Path(scratch)
        public = root / "current/public"
        for d in (public / "build", root / "logs", root / "fastcgi-temp", root / "php-upload",
                  root / "php-sys", root / "forge-conf/site/before", root / "forge-conf/site/server",
                  root / "forge-conf/site/after"):
            d.mkdir(parents=True, exist_ok=True)
        (public / "index.php").write_text('''<?php
            header('Content-Type: application/json');
            if (str_contains($_SERVER['REQUEST_URI'], '/upstream-private-headers')) {
                header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow');
            }
            echo json_encode(['uri'=>$_SERVER['REQUEST_URI'], 'query'=>$_SERVER['QUERY_STRING'] ?? '',
                'method'=>$_SERVER['REQUEST_METHOD'], 'script'=>basename($_SERVER['SCRIPT_FILENAME']),
                'sapi'=>PHP_SAPI, 'version'=>PHP_VERSION]);
        ''')
        (public / "build/probe.txt").write_text("synthetic-build-content")
        (public / ".env").write_text("synthetic-dotfile-marker")
        (public / "other.php").write_text("synthetic-other-php-marker")
        hashed = run(["openssl", "passwd", "-6", "-stdin"], input=PASSWORD).stdout.strip()
        auth = root / "htpasswd"
        auth.write_text(f"{USERNAME}:{hashed}\n")
        auth.chmod(0o600)
        cert, key = root / "cert.pem", root / "key.pem"
        run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
             "-subj", f"/CN={HOST}", "-addext", f"subjectAltName=DNS:{HOST},IP:127.0.0.1",
             "-keyout", str(key), "-out", str(cert)])
        key.chmod(0o600)
        replacements = {
            "__STAGING_HOST__": HOST, "__VASEY_ROOT__": scratch,
            "__SSL_CERT__": str(cert), "__SSL_KEY__": str(key), "__FORGE_CONF__": "site",
            "/etc/nginx/vasey-staging.htpasswd": str(auth),
            "/var/lib/nginx/vasey-staging-fastcgi": str(root / "fastcgi-temp"),
            "/var/log/nginx/vasey-staging-access.log": str(root / "logs/access.log"),
            "/var/log/nginx/vasey-staging-error.log": str(root / "logs/error.log"),
            "unix:/run/php/vasey-staging.sock": "unix:" + str(root / "default.sock"),
            "unix:/run/php/vasey-paid-delivery.sock": "unix:" + str(root / "paid.sock"),
            "listen 443 ssl http2;": f"listen 127.0.0.1:{PORT} ssl;",
            "listen [::]:443 ssl http2;": "# IPv6 listener omitted in owned local harness",
        }
        site = (REPO / "ops/staging/nginx/vasey-staging.conf").read_text()
        for before, after in replacements.items():
            site = site.replace(before, after)
        (root / "site.conf").write_text(site)
        shutil.copy(TOOLS / "etc/nginx/fastcgi_params", root / "fastcgi_params")
        (root / "nginx.conf").write_text(f'''worker_processes 1;
            daemon off; pid {root}/nginx.pid; error_log {root}/logs/nginx-global.log;
            events {{ worker_connections 128; }}
            http {{ client_body_temp_path {root}/body-temp; proxy_temp_path {root}/proxy-temp;
              fastcgi_temp_path {root}/fastcgi-global-temp; scgi_temp_path {root}/scgi-temp;
              uwsgi_temp_path {root}/uwsgi-temp; include {root}/site.conf; }}
        ''')
        pool_files = []
        for name, sock in (("vasey-staging", "default.sock"), ("vasey-paid-delivery", "paid.sock")):
            pool = (REPO / f"ops/staging/php-fpm/{name}.conf").read_text()
            for before, after in {"__APP_USER__": "agent", "__APP_GROUP__": "agent", "__NGINX_USER__": "agent",
                                  "__NGINX_GROUP__": "agent", "__VASEY_ROOT__": scratch,
                                  f"/run/php/{name}.sock": str(root / sock),
                                  "/var/log/": str(root / "logs") + "/",
                                  scratch + "/tmp/php-upload": str(root / "php-upload"),
                                  scratch + "/tmp/php-sys": str(root / "php-sys")}.items():
                pool = pool.replace(before, after)
            path = root / f"{name}.conf"
            path.write_text(pool)
            pool_files.append(path)
        (root / "fpm.conf").write_text(f'''[global]
            daemonize = no
            pid = {root}/fpm.pid
            error_log = {root}/logs/fpm-global.log
            ''' + "\n".join(f"include = {p}" for p in pool_files) + "\n")
        print(run([NGINX, "-v"]).stderr.strip(), flush=True)
        print(run([FPM, "-v"]).stdout.splitlines()[0], flush=True)
        print("scope=owned prefix; transport=loopback TLS; app=synthetic FastCGI responder; no Forge activation or provider I/O", flush=True)
        run([NGINX, "-t", "-p", scratch + "/", "-c", str(root / "nginx.conf")])
        run([FPM, "-t", "-y", str(root / "fpm.conf")])
        try:
            fpm = subprocess.Popen([FPM, "-F", "-y", str(root / "fpm.conf")], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            nginx = subprocess.Popen([NGINX, "-p", scratch + "/", "-c", str(root / "nginx.conf")],
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            for _ in range(100):
                if fpm.poll() is not None or nginx.poll() is not None:
                    raise RuntimeError("owned daemon failed to start")
                if (root / "default.sock").exists() and (root / "paid.sock").exists():
                    try:
                        with socket.create_connection(("127.0.0.1", PORT), timeout=0.25):
                            break
                    except OSError:
                        pass
                time.sleep(0.05)
            else:
                raise RuntimeError("owned daemon readiness timed out")
            context = ssl.create_default_context(cafile=str(cert))
            def request(method, path, auth=False, body=None):
                conn = http.client.HTTPSConnection("127.0.0.1", PORT, timeout=10, context=context)
                headers = {"Host": HOST}
                if auth:
                    headers["Authorization"] = "Basic " + base64.b64encode(f"{USERNAME}:{PASSWORD}".encode()).decode()
                if body is not None:
                    headers["Content-Type"] = "application/json"
                conn.request(method, path, body=body, headers=headers)
                response = conn.getresponse()
                value = (response.status, response.getheaders(), response.read().decode())
                conn.close()
                return value
            def check(label, condition):
                nonlocal checks
                assert condition, label
                checks += 1
                print("PASS " + label, flush=True)
            for path in ("/", "/admin", "/build/probe.txt", "/webhooks/stripe/", "/index.php/webhooks/stripe",
                         "/webhooks%252fstripe", "/admin?next=/webhooks/stripe"):
                status, _, _ = request("POST", path)
                check(f"unauthenticated POST {path} stays behind basic auth", status == 401)
            for method in ("GET", "HEAD", "PUT", "PATCH", "DELETE", "OPTIONS"):
                check(f"webhook method {method} refused", request(method, "/webhooks/stripe")[0] == 403)
            for path in ("/webhooks/stripe", "/webhooks/stripe?next=/admin", "/admin/../webhooks/stripe",
                         "//webhooks//stripe", "/webhooks/%73tripe", "/webhooks/stripe%3fnext=/admin"):
                status, _, body = request("POST", path, body="{}")
                if path.endswith("%3fnext=/admin"):
                    check(f"encoded query delimiter {path} stays behind auth", status == 401)
                    continue
                payload = json.loads(body)
                check(f"normalized webhook {path} is pinned to sole route", status == 200
                      and payload["uri"] == "/webhooks/stripe" and payload["query"] == ""
                      and payload["script"] == "index.php" and payload["method"] == "POST")
            for path, marker in (("/.env", "synthetic-dotfile-marker"), ("/other.php", "synthetic-other-php-marker"),
                                 ("/build/../.env", "synthetic-dotfile-marker"), ("/index.php", "<?php")):
                status, _, body = request("GET", path, auth=True)
                check(f"authenticated {path} never serves private/static PHP content", marker not in body)
            status, headers, body = request("GET", "/upstream-private-headers", auth=True)
            check("authenticated request reaches FPM 8.4 CLI-independent responder", status == 200
                  and json.loads(body)["sapi"] == "fpm-fcgi" and json.loads(body)["version"] == "8.4.26")
            referrer = [value for name, value in headers if name.lower() == "referrer-policy"]
            check("upstream no-referrer preserved without weaker appended header", referrer == ["no-referrer"])
            check("oversize unauthenticated webhook body refused before application", request("POST", "/webhooks/stripe",
                                                                                              body="x" * (1024 * 1024 + 1))[0] == 413)
            print(f"RESULT: {checks} checks passed", flush=True)
        finally:
            stop(nginx)
            stop(fpm)


if __name__ == "__main__":
    main()
