#!/usr/bin/env python3
"""Mock command routing/safety tests, not evidence of a native macOS installation."""
import base64
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).with_name("bootstrap-macos.sh")
REAL_GIT = shutil.which("git")
MOCK = r'''#!/usr/bin/env python3
import base64, json, os, pathlib, sys
name = pathlib.Path(sys.argv[0]).name
args = sys.argv[1:]
with open(os.environ['MOCK_LOG'], 'a') as log:
    log.write(json.dumps([name, *args]) + '\n')
prefix = pathlib.Path(os.environ['MOCK_PREFIX'])
if name == 'uname':
    print(os.environ.get('MOCK_OS', 'Darwin'))
elif name == 'brew':
    if args[:1] == ['--prefix']:
        print(prefix / args[1] if len(args) > 1 else prefix)
    elif args[:2] == ['list', '--versions']:
        sys.exit(1 if args[2] in os.environ.get('MOCK_MISSING', '').split(',') else 0)
elif name == 'php':
    if args[0] == '-r':
        if 'fopen(".env", "x")' in args[1]:
            if os.environ.get('MOCK_REAL_PHP'):
                os.execv(os.environ['MOCK_REAL_PHP'], [os.environ['MOCK_REAL_PHP'], *args])
            text = pathlib.Path('.env.example').read_text()
            key = base64.b64encode(os.urandom(32)).decode()
            with open('.env', 'x') as env:
                env.write(text.replace('APP_KEY=\n', 'APP_KEY=base64:' + key + '\n'))
            os.chmod('.env', 0o600)
        elif os.environ.get('MOCK_BAD_PHP'):
            sys.exit(2)
    elif '--version' in args:
        print('Composer version 2.10.3')
elif name == 'node' and os.environ.get('MOCK_BAD_NODE'):
    sys.exit(2)
elif name == 'mysqld':
    print('mysqld Ver 8.4.11 for macos')
'''


class BootstrapTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.checkout = self.root / "checkout with spaces"
        (self.checkout / "scripts/dev").mkdir(parents=True)
        shutil.copy2(SCRIPT, self.checkout / "scripts/dev/bootstrap-macos.sh")
        for name, value in {
            "composer.json": '{"name":"vaseydev/vaseyaudio"}',
            "package.json": '{"name":"vaseyaudio"}',
            "composer.lock": "{}", "package-lock.json": "{}",
            "artisan": "", ".env.example": "APP_ENV=local\nAPP_KEY=\nDB_CONNECTION=sqlite\n",
        }.items():
            (self.checkout / name).write_text(value)
        subprocess.run([REAL_GIT, "init", "-q", str(self.checkout)], check=True)
        self.mockbin = self.root / "mockbin"
        self.mockbin.mkdir()
        self.prefix = self.root / "homebrew with spaces"
        for formula in ["php@8.4", "node@24", "mysql@8.4", "composer"]:
            (self.prefix / formula / "bin").mkdir(parents=True)
        (self.prefix / "composer/libexec").mkdir()
        (self.prefix / "composer/libexec/composer.phar").write_text("mock")
        for name in ["uname", "brew", "php", "node", "mysqld", "npm", "ffmpeg", "ffprobe", "qpdf", "pdftotext", "clamscan", "playwright"]:
            target = self.mockbin / name
            target.write_text(MOCK)
            target.chmod(0o755)
        for name, formula in [("php", "php@8.4"), ("node", "node@24"), ("mysqld", "mysql@8.4")]:
            (self.prefix / formula / "bin" / name).symlink_to(self.mockbin / name)
        (self.checkout / "node_modules/.bin").mkdir(parents=True)
        (self.checkout / "node_modules/.bin/playwright").symlink_to(self.mockbin / "playwright")
        self.log = self.root / "commands.jsonl"
        self.env = dict(os.environ, PATH=f"{self.mockbin}:{os.environ['PATH']}", MOCK_LOG=str(self.log), MOCK_PREFIX=str(self.prefix))
        self.originals = {name: (self.checkout / name).read_bytes() for name in ["composer.json", "package.json", "composer.lock", "package-lock.json"]}

    def tearDown(self):
        self.temporary.cleanup()

    def run_script(self, *flags, **environment):
        result = subprocess.run(["bash", str(self.checkout / "scripts/dev/bootstrap-macos.sh"), *flags], env=dict(self.env, **environment), capture_output=True, text=True)
        for name, contents in self.originals.items():
            self.assertEqual((self.checkout / name).read_bytes(), contents)
        return result

    def commands(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()]

    def test_non_mac_rejected_before_mutation(self):
        result = self.run_script(MOCK_OS="Linux")
        self.assertEqual(result.returncode, 2)
        self.assertEqual(self.commands(), [["uname", "-s"]])
        self.assertFalse((self.checkout / ".env").exists())

    def test_check_is_read_only(self):
        result = self.run_script("--check")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse((self.checkout / ".env").exists())
        self.assertFalse(any(c[0] == "npm" or "install" in c for c in self.commands()))

    def test_missing_formula_in_check_does_not_install(self):
        result = self.run_script("--check", MOCK_MISSING="node@24")
        self.assertEqual(result.returncode, 2)
        self.assertIn("Missing Homebrew formula: node@24", result.stderr)
        self.assertFalse(any("install" in c for c in self.commands()))

    def test_existing_environment_key_and_database_preserved(self):
        original = b"APP_KEY=private-existing-key\nDB_CONNECTION=mysql\n"
        (self.checkout / ".env").write_bytes(original)
        database = self.checkout / "database.sqlite"
        database.write_bytes(b"private existing database bytes")
        result = self.run_script(MOCK_MISSING="php@8.4,node@24")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.checkout / ".env").read_bytes(), original)
        self.assertEqual(database.read_bytes(), b"private existing database bytes")
        commands = self.commands()
        self.assertIn(["brew", "install", "php@8.4"], commands)
        self.assertIn(["brew", "install", "node@24"], commands)
        composer_installs = [c for c in commands if c[0] == "php" and "install" in c]
        self.assertEqual(len(composer_installs), 1)
        self.assertIn("--no-scripts", composer_installs[0])
        self.assertEqual(composer_installs[0][1], str(self.prefix / "composer/libexec/composer.phar"))
        self.assertIn(["npm", "ci", "--ignore-scripts"], commands)
        self.assertIn(["npm", "run", "build"], commands)
        self.assertFalse(any("artisan" in c or "services" in c or c[0] == "playwright" for c in commands))
        self.assertNotIn("private-existing-key", result.stdout + result.stderr)

    def test_new_environment_has_random_key_private_permissions(self):
        result = self.run_script("--no-build")
        self.assertEqual(result.returncode, 0, result.stderr)
        contents = (self.checkout / ".env").read_text()
        encoded = contents.split("APP_KEY=base64:")[1].splitlines()[0]
        self.assertEqual(len(base64.b64decode(encoded)), 32)
        self.assertEqual((self.checkout / ".env").stat().st_mode & 0o777, 0o600)
        self.assertFalse(any(c == ["npm", "run", "build"] for c in self.commands()))

    def test_environment_symlink_kept(self):
        target = self.root / "external environment"
        target.write_text("APP_KEY=keep-this-key\n")
        (self.checkout / ".env").symlink_to(target)
        result = self.run_script("--no-build")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue((self.checkout / ".env").is_symlink())
        self.assertEqual(target.read_text(), "APP_KEY=keep-this-key\n")

    @unittest.skipUnless(shutil.which("php"), "Host PHP not available for actual environment-generation snippet")
    def test_environment_generation_with_actual_host_php(self):
        result = self.run_script("--no-build", MOCK_REAL_PHP=shutil.which("php"))
        self.assertEqual(result.returncode, 0, result.stderr)
        encoded = (self.checkout / ".env").read_text().split("APP_KEY=base64:")[1].splitlines()[0]
        self.assertEqual(len(base64.b64decode(encoded)), 32)
        self.assertEqual((self.checkout / ".env").stat().st_mode & 0o777, 0o600)

    def test_unknown_flag_rejected_without_commands(self):
        result = self.run_script("--reset-database")
        self.assertEqual(result.returncode, 2)
        self.assertFalse(self.log.exists())

    def test_wrong_project_refused_before_brew(self):
        (self.checkout / "composer.json").write_text('{"name":"other/project"}')
        self.originals["composer.json"] = (self.checkout / "composer.json").read_bytes()
        result = self.run_script(MOCK_MISSING="php@8.4")
        self.assertEqual(result.returncode, 2)
        self.assertFalse(any(c[0] == "brew" for c in self.commands()))

    def test_browser_install_is_explicit_and_locked(self):
        result = self.run_script("--with-browsers")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(["playwright", "install", "chromium", "webkit"], self.commands())
        self.assertFalse(any(c[0] == "npx" for c in self.commands()))

    def test_existing_mysql_data_prevents_first_install(self):
        directory = self.prefix / "var/mysql"
        directory.mkdir(parents=True)
        marker = directory / "real-data"
        marker.write_text("preserve")
        result = self.run_script(MOCK_MISSING="mysql@8.4")
        self.assertEqual(result.returncode, 2)
        self.assertEqual(marker.read_text(), "preserve")
        self.assertFalse(any("install" in c for c in self.commands()))

    def test_runtime_failure_prevents_application_install(self):
        for variable in ["MOCK_BAD_PHP", "MOCK_BAD_NODE"]:
            result = self.run_script(**{variable: "1"})
            self.assertEqual(result.returncode, 2)
        self.assertFalse(any(c[0] == "npm" or (c[0] == "php" and "install" in c) for c in self.commands()))

    @unittest.skipIf(any(Path(p).exists() for p in ["/opt/homebrew/bin/brew", "/usr/local/bin/brew"]), "Native Homebrew fallback present; never invoke a real installation in mocked tests")
    def test_missing_homebrew_has_concrete_instruction(self):
        # Standard Homebrew locations are absent in this Linux mock runner.
        (self.mockbin / "brew").unlink()
        # Limit PATH to mocks and the exact utilities needed before brew discovery.
        for name in ["python3", "git", "grep", "dirname"]:
            (self.mockbin / name).symlink_to(shutil.which(name))
        self.env["PATH"] = str(self.mockbin)
        result = subprocess.run(["/bin/bash", str(self.checkout / "scripts/dev/bootstrap-macos.sh")], env=self.env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 2)
        self.assertIn("https://brew.sh/", result.stderr)
        self.assertFalse((self.checkout / ".env").exists())


if __name__ == "__main__":
    unittest.main()
