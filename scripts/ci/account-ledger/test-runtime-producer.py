#!/usr/bin/env python3
"""HOST-only negative controls. No Docker/PHP/native runtime is invoked."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('runtime_producer', HERE / 'runtime-producer.py')
producer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(producer)


def complete_policy():
    # Parsing a source policy does not provision or admit its runtime.
    value = json.loads((HERE / 'runtime-policy.json').read_bytes())
    return value


class PolicyControls(unittest.TestCase):
    def test_checked_in_policy_uses_reviewed_build_and_base(self):
        producer.policy(complete_policy())

    def test_ambient_runtime_and_unreviewed_recipe_refuse(self):
        for key, replacement in [('base_repo_digest', 'debian:trixie'), ('recipe', '/tmp/ambient/Dockerfile')]:
            value = complete_policy()
            value['runtime'][key] = replacement
            with self.subTest(key=key), self.assertRaises(producer.Refusal):
                producer.policy(value)

    def test_version_and_module_drift_refuse(self):
        for name, replacement in [('php_version', '8.4.28'), ('python_version', '3.13.6'),
                                  ('php_binary', '/usr/bin/php'), ('php_modules', {})]:
            with self.subTest(name=name), self.assertRaises(producer.Refusal):
                value = complete_policy()
                value['runtime'][name] = replacement
                producer.policy(value)

    def test_closed_keys_at_every_policy_level(self):
        for section in (None, 'runtime', 'mysql'):
            with self.subTest(section=section), self.assertRaises(producer.Refusal):
                value = complete_policy()
                target = value if section is None else value[section]
                target['ambient_override'] = True
                producer.policy(value)

    def test_tags_and_base_image_identity_cannot_admit_runtime(self):
        value = complete_policy()
        value['mysql']['repo_digest'] = 'mysql:8.4'
        with self.assertRaises(producer.Refusal):
            producer.policy(value)
        expected = complete_policy()['mysql']
        row = {'Id': 'sha256:' + '4' * 64, 'RepoDigests': [expected['repo_digest']],
               'Os': 'linux', 'Architecture': 'amd64'}
        with self.assertRaisesRegex(producer.Refusal, 'image-provenance-mismatch'):
            producer.image_matches(row, expected)

    def test_image_arch_and_digest_both_bind(self):
        expected = complete_policy()['mysql']
        row = {'Id': expected['image_id'], 'RepoDigests': [expected['repo_digest']],
               'Os': 'linux', 'Architecture': 'amd64'}
        producer.image_matches(row, expected)
        for name, replacement in [('Architecture', 'arm64'), ('RepoDigests', [])]:
            with self.subTest(name=name), self.assertRaises(producer.Refusal):
                producer.image_matches({**row, name: replacement}, expected)

    def test_duplicate_and_large_json_refuse(self):
        for raw in (b'{"schema":1,"schema":1}', b' ' * 65537):
            with self.subTest(size=len(raw)), self.assertRaises(producer.Refusal):
                producer.load(raw)


class OwnershipControls(unittest.TestCase):
    def test_acceptance_owned_path_is_not_independent(self):
        # Runs as the ordinary HOST test UID, and checks actual filesystem ownership.
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'policy.json'
            path.write_text('{}')
            with self.assertRaises(producer.Refusal):
                producer.trusted_path(path)

    def test_symlink_and_traversal_paths_refuse(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / 'real').write_text('{}')
            (root / 'link').symlink_to(root / 'real')
            for path in (root / 'link', root / 'child' / '..' / 'real'):
                with self.subTest(path=path), self.assertRaises(producer.Refusal):
                    producer.trusted_path(path)

    def test_create_plan_keeps_controller_custody_outside_acceptance_uid(self):
        command = producer.create_command('sha256:' + '2' * 64,
                                          'va-ledger-runtime-' + 'a' * 32, Path('/sealed/probes'))
        for flag in ('--pull=never', '--user=1000:1000', '--network=none', '--read-only',
                     '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--memory=512m',
                     '--memory-swap=512m', '--pids-limit=64', '--log-driver=none', '--init=false'):
            self.assertIn(flag, command)
        self.assertEqual(command.count('--mount'), 1)
        self.assertIn('type=bind,src=/sealed/probes,dst=/qualification,readonly', command)
        self.assertFalse(any('docker.sock' in word or word.startswith('--pid=')
                             or word.startswith('--privileged') for word in command))

    def test_create_plan_cannot_adopt_ambient_name_or_tag(self):
        for image, name in [('debian:latest', 'va-ledger-runtime-' + 'a' * 32),
                            ('sha256:' + '2' * 64, 'existing-runtime')]:
            with self.subTest(name=name), self.assertRaises(producer.Refusal):
                producer.create_command(image, name, Path('/sealed/probes'))

    def test_unknown_wait_or_incomplete_transport_cannot_qualify(self):
        for failure in ('wait', 'profile', 'transport'):
            instance = producer.Producer()
            calls = []

            def command(arguments, stage, **unused):
                calls.append(stage)
                if stage == 'controller-profile':
                    return json.dumps({'Version': '28.4.0', 'ApiVersion': '1.51', 'GitCommit': '249d679',
                                       'Os': 'linux', 'Arch': 'amd64'})
                if stage == 'controller-cgroup':
                    return json.dumps({'CgroupVersion': '2', 'CgroupDriver': 'cgroupfs', 'OomKillDisable': False})
                if stage in ('create', 'start'):
                    return 'a' * 64 + '\n'
                if stage == 'profile':
                    if failure == 'profile':
                        return '{"profile_match":false}'
                    return '{"profile_match":true}'
                if stage == 'signed-package-closure':
                    return 'pkg\t1\tamd64\n' * 11
                if stage == 'signed-download-closure':
                    return 'b' * 64 + '  /usr/share/va-ledger/debs/example_1_amd64.deb\n'
                if stage == 'wait':
                    return '0\n' if failure == 'wait' else '137\n'
                if stage == 'stop':
                    self.assertIn('--signal=KILL', arguments)
                if stage == 'removed' and failure == 'transport':
                    instance.receipt['transport_uncertain'] = True
                return ''

            with self.subTest(failure=failure), patch.object(producer, 'trusted_path'), \
                    patch.object(instance, 'command', side_effect=command), \
                    patch.object(instance, 'image'), patch.object(instance, 'build', return_value='sha256:' + '2' * 64):
                with self.assertRaises(producer.Refusal):
                    instance.qualify(complete_policy(), Path('/sealed/probes'), 'a' * 32)
            self.assertIn('stop', calls)
            self.assertIn('wait', calls)
            if failure != 'wait':
                self.assertIn('removed', calls)

    def test_publication_is_exclusive_and_read_only(self):
        with tempfile.TemporaryDirectory() as temporary, patch.object(producer, 'trusted_path'):
            directory = Path(temporary)
            descriptor = {'kind': 'runtime-qualification-only', 'native_admitted': False}
            producer.publish(directory, descriptor)
            target = directory / 'runtime-qualified.json'
            self.assertEqual(target.stat().st_mode & 0o777, 0o444)
            self.assertEqual(json.loads(target.read_bytes()), descriptor)
            with self.assertRaises(FileExistsError):
                producer.publish(directory, descriptor)

    def test_progress_retains_owned_allocation_without_native_admission(self):
        with tempfile.TemporaryDirectory() as temporary, patch.object(producer, 'trusted_path'):
            directory = Path(temporary)
            instance = producer.Producer(directory)
            instance.receipt['intended_name'] = 'va-ledger-runtime-' + 'a' * 32
            instance.receipt['created_id'] = 'b' * 64
            instance.record('before-profile', {'raw_output': 'must-not-be-retained'})
            progress = directory / 'runtime-progress.json'
            data = json.loads(progress.read_bytes())
            self.assertFalse(data['native_admitted'])
            self.assertFalse(data['runtime_qualified'])
            self.assertEqual(data['created_id'], 'b' * 64)
            self.assertEqual(progress.stat().st_mode & 0o777, 0o400)
            self.assertNotIn('must-not-be-retained', progress.read_text())
            self.assertFalse((directory / 'runtime-progress.partial').exists())
            with self.assertRaisesRegex(producer.Refusal, 'fresh-owned-output-required'):
                producer.Producer(directory)

    def test_publication_never_follows_existing_symlink(self):
        with tempfile.TemporaryDirectory() as temporary, patch.object(producer, 'trusted_path'):
            directory = Path(temporary)
            victim = directory / 'victim'
            victim.write_text('untouched')
            (directory / 'runtime-qualified.json').symlink_to(victim)
            with self.assertRaises(FileExistsError):
                producer.publish(directory, {})
            self.assertEqual(victim.read_text(), 'untouched')

    def test_source_manifest_binds_exact_producer_and_probes(self):
        manifest = json.loads((HERE / 'source-manifest.json').read_bytes())
        self.assertEqual(set(manifest), {'runtime-producer.py', 'runtime-policy.json', 'capture.py',
                                        'runtime-probe.php', 'runtime-probe.py', 'Runtime.Dockerfile', '.dockerignore', 'source-snapshot.py', 'network.py'})
        for name, expected in manifest.items():
            self.assertEqual(hashlib.sha256((HERE / name).read_bytes()).hexdigest(), expected, name)

    def test_reused_capture_is_byte_identical_to_accepted_transport(self):
        # Independently recorded SHA256 of accepted factory's public capture.py.
        self.assertEqual(hashlib.sha256((HERE / 'capture.py').read_bytes()).hexdigest(),
                         '39bd4cb691387b9eb838e8b1454d0ac992bc3da367c7788bf7ad822515d31c8f')

    def test_build_recipe_has_no_ambient_installer_or_signature_override(self):
        recipe = (HERE / 'Runtime.Dockerfile').read_text()
        self.assertIn('FROM debian@sha256:f6b08b09a05725f9431502d3b5714f5c4576e82c1a84fa1debe0c24a7e0c3ec4', recipe)
        self.assertIn('php8.4-cli=8.4.27-1~deb13u1', recipe)
        self.assertIn('python3.13-minimal=3.13.5-2+deb13u5', recipe)
        self.assertIn('python3.13=3.13.5-2+deb13u5', recipe)
        self.assertIn('libpython3.13-stdlib=3.13.5-2+deb13u5', recipe)
        for unsafe in ('--allow-unauthenticated', 'trusted=yes', 'Check-Valid-Until=false',
                       'Verify-Peer=false', 'curl ', 'wget ', 'ADD ', 'COPY '):
            self.assertNotIn(unsafe, recipe)
        self.assertEqual((HERE / '.dockerignore').read_text(), '**\n!Runtime.Dockerfile\n')


if __name__ == '__main__':
    unittest.main()
