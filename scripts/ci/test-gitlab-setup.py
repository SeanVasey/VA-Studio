#!/usr/bin/env python3
"""Execute CI package-installation boundary refusals without mutating the host."""
import os
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


if __name__ == '__main__':
    unittest.main()
