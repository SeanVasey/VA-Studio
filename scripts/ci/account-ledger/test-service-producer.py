#!/usr/bin/env python3
"""HOST-only qualification/transport source controls; no native client is started."""
import hashlib
import importlib.util
from pathlib import Path
import unittest

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('service_producer', HERE / 'service-producer.py')
service = importlib.util.module_from_spec(spec)
spec.loader.exec_module(service)


class ServiceControls(unittest.TestCase):
    def test_exact_six_to_ten_scope_for_both_native_revocation_profiles(self):
        for mode in (0, 1):
            before = service.grant_set(list(service.grants(mode)), mode)
            after = service.grant_set(list(service.grants(mode, True)), mode, True)
            self.assertEqual(len(before), 6)
            self.assertEqual(len(after), 10)
            self.assertEqual(before - after, {service.USAGE})
            self.assertEqual(len(after - before), 5)
            self.assertIn(service.PROCESS, after - before)
            service.observer_delta(before, after, mode)

    def test_global_all_grant_option_missing_and_duplicate_grants_refuse(self):
        original = list(service.grants(0, True))
        for rows in (original + [original[0]], original[:-1],
                     [*original, 'GRANT ALL PRIVILEGES ON *.* TO `account_fixture`@`%`'],
                     [row + ' WITH GRANT OPTION' for row in original]):
            with self.subTest(rows=rows), self.assertRaises(service.runtime.Refusal):
                service.grant_set(rows, 0, True)

    def test_partial_revokes_changes_require_different_exact_peer_scope(self):
        self.assertNotEqual(service.grants(0), service.grants(1))
        for bad in (-1, 2, True, '0'):
            with self.subTest(bad=bad), self.assertRaises(service.runtime.Refusal):
                service.grants(bad)
        with self.assertRaises(service.runtime.Refusal):
            service.grant_set(list(service.grants(0)), 1)

    def test_instrumentation_requires_positive_full_instrument_coverage(self):
        service.instrumentation(['1\t4\t4\t200\t200\t3\t3\t1\t1'])
        for raw in ['0\t4\t4\t200\t200\t3\t3\t1\t1',
                    '1\t4\t3\t200\t200\t3\t3\t1\t1',
                    '1\t4\t4\t200\t199\t3\t3\t1\t1',
                    '1\t4\t4\t0\t0\t3\t3\t1\t1',
                    '1\t4\t4\t200\t200\t3\t2\t1\t1']:
            with self.subTest(raw=raw), self.assertRaises(service.runtime.Refusal):
                service.instrumentation([raw])

    def test_admin_and_private_archive_are_exact_accepted_sources(self):
        expected = {'admin.py': 'f6013d0d24d5cc34f47b7e96b249021b6bc9f4c18a3c0d29aff56923384e8120',
                    'private_archive.py': 'c90306e06baf3979eeed815fbe3deadd5e46cffd42ab1500a48eb58dff0580fa',
                    'RUNTIME-SPEC.json': '85e21d401429b6515685004ba15d630e302a106519eeec46040b8d4ca75e768a'}
        for name, digest in expected.items():
            self.assertEqual(hashlib.sha256((HERE / 'factory' / name).read_bytes()).hexdigest(), digest, name)

    def test_factory_delta_is_only_declared_root_and_source_binding_parameters(self):
        text = (HERE / 'factory/provision.py').read_text()
        restored = text.replace("Path(SPEC['private_directory'])", "Path('/tmp/va-account-real-versions-private-04')")
        restored = restored.replace("'inherited_factory_manifest_sha256':SPEC['inherited_factory_manifest_sha256']",
                                    "'source_manifest_sha256':hashlib.sha256((HERE/'SOURCE-HASHES.json').read_bytes()).hexdigest()")
        restored = restored.replace("metadata.st_uid==SPEC['controller_uid']", 'metadata.st_uid==1000')
        restored = restored.replace("SPEC['owner_label']+'='+SPEC['owner_value']", "SPEC['owner_label']+'=account-real-versions-04'")
        restored = restored.replace("==SPEC['owner_value']", "=='account-real-versions-04'")
        restored = restored.replace("        self.source_preflight();row=self.inspect();ownership.running(self.anchor,row,SPEC,'main')",
            "        from dependency_audit import audit\n        need(audit()['status']=='PASS_SOURCE_DEPENDENCY_PREFLIGHT');row=self.inspect();ownership.running(self.anchor,row,SPEC,'main')")
        self.assertEqual(hashlib.sha256(restored.encode()).hexdigest(),
                         '5748061e299de16dc762b63b3873c8fc1e33a73babef48364c0a3bcec7cbd29b')
        ownership = (HERE / 'factory/ownership.py').read_text().replace("spec['owner_value']", "'account-real-versions-04'")
        self.assertEqual(hashlib.sha256(ownership.encode()).hexdigest(),
                         '5cfc770f21ae31e7ad53b74e196e78d89c2a651f980e8fb41705a14026e22715')

    def test_observer_native_sql_and_permission_checks_are_exact_accepted_source(self):
        probe = (HERE / 'observer-probe.php').read_text()
        functions = probe[probe.index('function qualifiedRow'):probe.index('$pdo = null;')]
        native = probe[probe.index('    $pdo = new Pdo'):]
        self.assertEqual(hashlib.sha256(functions.encode()).hexdigest(),
                         '25acfbdd42217527cc6eb420396e62d405026feb425c782cc49d36e4395f7e2d')
        self.assertEqual(hashlib.sha256(native.encode()).hexdigest(),
                         '962cbcacd93936a6f22326ac0c4b6585a2b7030566500527588d765beb17999b')


if __name__ == '__main__':
    unittest.main()
