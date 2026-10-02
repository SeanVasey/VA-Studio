#!/usr/bin/env python3
"""Execute CI package-installation boundary refusals without mutating the host."""
import os
import tempfile
import stat
import contextlib
import io
from unittest.mock import patch
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ('setup-gitlab-php.sh', 'setup-gitlab-node.sh', 'setup-gitlab-related-scanner.sh')


class GitLabSetupBoundaries(unittest.TestCase):
    def refuse(self, additions, arguments=()):
        env = {key: value for key, value in os.environ.items()
               if key not in ('GITLAB_CI', 'CI_DISPOSABLE_ENVIRONMENT')}
        env.update(additions)
        for script in SCRIPTS:
            with self.subTest(script=script, environment=additions, arguments=arguments):
                result = subprocess.run(['bash', str(ROOT / 'scripts/ci' / script), *arguments],
                                        cwd=ROOT, env=env, capture_output=True, text=True, timeout=5)
                self.assertEqual(result.returncode, 1)
                self.assertEqual(result.stdout, '')
                self.assertIn('requires a disposable GitLab container running as root.', result.stderr)

    def test_developer_invocation_refused(self):
        self.refuse({})

    def test_persistent_gitlab_runner_refused(self):
        self.refuse({'GITLAB_CI': 'true', 'CI_DISPOSABLE_ENVIRONMENT': 'false'})

    def test_missing_disposable_proof_refused(self):
        self.refuse({'GITLAB_CI': 'true'})

    def test_non_gitlab_disposable_runner_refused(self):
        self.refuse({'GITLAB_CI': 'false', 'CI_DISPOSABLE_ENVIRONMENT': 'true'})

    def test_unexpected_arguments_refused_before_installation(self):
        self.refuse({'GITLAB_CI': 'true', 'CI_DISPOSABLE_ENVIRONMENT': 'true'}, ('--allow-host',))


class GitLabPrivateStorageBoundaries(unittest.TestCase):
    def setUp(self):
        if os.geteuid() != 0:
            self.skipTest('Native disposable CI runs as root; ownership tests require root.')
        self.temp = tempfile.TemporaryDirectory(prefix='gitlab-private-setup-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.private = self.root/'storage/app/private'
        self.private.mkdir(parents=True)
        self.private.chmod(0o777)
        self.environment = os.environ | {'GITLAB_CI':'true', 'CI_DISPOSABLE_ENVIRONMENT':'true',
                                         'CI_PROJECT_DIR':str(self.root), 'LARAVEL_STORAGE_PATH':''}

    def prepare(self, env=None, args=()):
        return subprocess.run(['bash','-c','source "$1"; shift; prepare_gitlab_private_storage "$@"',
                               'private-storage-test',str(ROOT/'scripts/ci/setup-gitlab-php.sh'),*args],
                              cwd=self.root, env=env or self.environment, capture_output=True,text=True,timeout=5)

    def test_only_exact_owned_private_root_is_normalized(self):
        descendant = self.private/'existing.txt'; descendant.write_text('synthetic'); descendant.chmod(0o640)
        parent_mode = stat.S_IMODE((self.root/'storage/app').stat().st_mode)
        result=self.prepare()
        self.assertEqual(0,result.returncode,result.stderr)
        self.assertEqual('CI private storage mode: 0777 -> 0700 (verified)\n',result.stdout)
        self.assertEqual(0o700,stat.S_IMODE(self.private.stat().st_mode))
        self.assertEqual(0o640,stat.S_IMODE(descendant.stat().st_mode))
        self.assertEqual(parent_mode,stat.S_IMODE((self.root/'storage/app').stat().st_mode))

    def test_missing_native_disposable_proof_refuses_before_chmod(self):
        result=self.prepare(self.environment|{'CI_DISPOSABLE_ENVIRONMENT':'false'})
        self.assertEqual(1,result.returncode)
        self.assertEqual(0o777,stat.S_IMODE(self.private.stat().st_mode))

    def test_checkout_or_storage_override_refuses_before_chmod(self):
        for change in [{'CI_PROJECT_DIR':str(self.root/'different')},{'LARAVEL_STORAGE_PATH':str(self.root/'other')}]:
            result=self.prepare(self.environment|change)
            self.assertEqual(1,result.returncode)
            self.assertEqual(0o777,stat.S_IMODE(self.private.stat().st_mode))

    def test_symlink_private_root_or_parent_refuses_without_touching_target(self):
        for path in [self.private,self.root/'storage/app']:
            relocated=self.root/('real-'+path.name)
            path.rename(relocated);path.symlink_to(relocated,target_is_directory=True)
            try:
                result=self.prepare()
                self.assertEqual(1,result.returncode)
                target=relocated if path==self.private else relocated/'private'
                self.assertEqual(0o777,stat.S_IMODE(target.stat().st_mode))
            finally:
                path.unlink();relocated.rename(path)

    def test_unowned_private_root_refuses_without_touching_permissions(self):
        # Mock only ownership metadata: some managed execution runtimes cannot
        # assign an alternate UID, while the actual checker must still reject it.
        script=(ROOT/'scripts/ci/setup-gitlab-php.sh').read_text()
        checker=script.split("python3 - <<'PY_STORAGE'\n",1)[1].split('\nPY_STORAGE',1)[0]
        original=Path.lstat
        def other_owner(path,*args,**kwargs):
            entry=original(path,*args,**kwargs)
            if path==self.private:
                values=list(entry);values[4]=65534
                return os.stat_result(values)
            return entry
        with patch.dict(os.environ,self.environment),patch.object(Path,'cwd',return_value=self.root), \
                patch.object(Path,'lstat',other_owner),contextlib.redirect_stderr(io.StringIO()), \
                self.assertRaises(SystemExit) as rejected:
            exec(checker,{'__name__':'synthetic_private_storage_checker'})
        self.assertEqual(1,rejected.exception.code)
        self.assertEqual(0o777,stat.S_IMODE(self.private.stat().st_mode))

    def test_unexpected_arguments_refuse_before_chmod(self):
        result=self.prepare(args=('--other',))
        self.assertEqual(1,result.returncode)
        self.assertEqual(0o777,stat.S_IMODE(self.private.stat().st_mode))


if __name__ == '__main__':
    unittest.main()
