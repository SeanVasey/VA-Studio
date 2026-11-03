#!/usr/bin/env python3
"""HOST-only locked-package identity controls, never Composer or native runtime."""
import copy
import importlib.util
from pathlib import Path
import unittest

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('dependency_producer', HERE / 'dependency-producer.py')
dependencies = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dependencies)


class DependencyControls(unittest.TestCase):
    def setUp(self):
        self.package = {'name': 'vendor/package', 'version': 'v1.0.0',
                        'source': {'type': 'git', 'url': 'https://example.invalid/source', 'reference': 'a' * 40},
                        'dist': {'type': 'zip', 'url': 'https://example.invalid/archive', 'reference': 'a' * 40}}
        self.lock = {'packages': [self.package], 'packages-dev': []}
        self.installed = {'dev': True, 'packages': [copy.deepcopy(self.package)]}

    def test_complete_locked_identity_accepts_only_exact_package_metadata(self):
        dependencies.verify_packages(self.lock, self.installed)
        for key, value in [('version', 'v1.0.1'), ('name', 'other/package'),
                           ('source', {**self.package['source'], 'reference': 'b' * 40})]:
            installed = copy.deepcopy(self.installed)
            installed['packages'][0][key] = value
            with self.subTest(key=key), self.assertRaises(dependencies.runtime.Refusal):
                dependencies.verify_packages(self.lock, installed)

    def test_missing_dev_extra_missing_and_duplicate_packages_refuse(self):
        for installed in ({'dev': False, 'packages': [self.package]}, {'dev': True, 'packages': []},
                          {'dev': True, 'packages': [self.package, self.package]}):
            with self.subTest(installed=installed), self.assertRaises(dependencies.runtime.Refusal):
                dependencies.verify_packages(self.lock, installed)

    def test_installer_and_audit_disable_scripts_and_plugins_without_updating_lock(self):
        source = (HERE / 'dependency-producer.py').read_text()
        self.assertEqual(source.count("'--no-plugins', '--no-scripts'"), 2)
        self.assertNotIn("'update'", source)
        self.assertIn("'audit', '--locked'", source)
        self.assertIn("snapshot.verify(workspace, expected_sha)", source)

    def test_controller_has_pinned_engine_and_no_public_daemon_ports(self):
        source = (HERE / 'Controller.Dockerfile').read_text()
        self.assertIn('docker@sha256:b0ea6e8d57b28d04a6999591fdd0f10de8717e956f4f4b6128d2a8cf57b27eb9', source)
        self.assertIn('/usr/bin/docker', source)
        self.assertNotIn('EXPOSE', source)
        self.assertNotIn('ADD https', source)

    def test_controller_daemon_is_unix_only_with_distinct_nested_network(self):
        spec = importlib.util.spec_from_file_location('isolated_controller', HERE / 'controller.py')
        controller = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(controller)
        command = controller.daemon_command()
        self.assertIn('--host=unix:///var/run/docker.sock', command)
        self.assertIn('--exec-opt=native.cgroupdriver=cgroupfs', command)
        self.assertIn('--bip=172.31.240.1/24', command)
        self.assertFalse(any('tcp://' in token or '172.17.' in token or '--pid=host' in token for token in command))


if __name__ == '__main__':
    unittest.main()
