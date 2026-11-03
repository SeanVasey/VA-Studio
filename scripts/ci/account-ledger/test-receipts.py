#!/usr/bin/env python3
"""Synthetic HOST receipt fixtures only; no provider/runtime proof is produced."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('ledger_receipt_controls', HERE / 'receipts.py')
receipts = importlib.util.module_from_spec(spec)
spec.loader.exec_module(receipts)


def fixture(sha='a' * 40, run_id=123, attempt=1):
    files = {}
    for case in receipts.CASES:
        lineage = {'parent_source_bound': True, 'killed_worker_source_bound_to_original_supervised_pid': True,
            'worker_actual_execution_proof': 'ORIGINAL_READY_REFLECTION_STACK_ASSERTIONS',
            'ordinary_artisan_origins': 2 if case.startswith('up-') else 1, 'all_registered_pids_accounted': True}
        proof = {'junit_sha256': 'b' * 64, 'launcher_sha256': 'c' * 64, 'parent_worker_restart_origins': True,
            'lineage': lineage, 'job_descriptor_sha256': hashlib.sha256(case.encode()).hexdigest(),
            'runtime_image': 'sha256:' + 'd' * 64,
            'mysql_image': 'mysql@sha256:0f51e63e537a1f176423fa03ef559aabe6fa4372a53160a44bc7e75da7d15364',
            'controller_engine': 'docker@sha256:b0ea6e8d57b28d04a6999591fdd0f10de8717e956f4f4b6128d2a8cf57b27eb9',
            'server_uuid_sha256': 'e' * 64}
        value = {'schema': 1, 'kind': 'required-supervised-ledger-case', 'candidate_sha': sha,
            'run_id': run_id, 'run_attempt': attempt, 'case': case, 'assertions': 7, 'outer_exit': 0,
            'outer_reaped': True, 'strict_no_fesw': True, 'proof': proof,
            'cleanup': dict.fromkeys(('state', 'profile', 'resources', 'php', 'memory', 'source'), True),
            'lease_returnable': True, 'case_container_removed': True}
        files['ledger-' + case + '.json'] = json.dumps(value).encode()
    files['ledger-suite.json'] = json.dumps({'schema': 1, 'kind': 'required-supervised-ledger-suite',
        'candidate_sha': sha, 'run_id': run_id, 'run_attempt': attempt, 'cases': list(receipts.CASES),
        'assertions': 28, 'service_removed': True, 'owned_volume_removed': True, 'controller_shutdown_required': True}).encode()
    return files


class ReceiptControls(unittest.TestCase):
    def test_complete_synthetic_shape(self):
        self.assertEqual(receipts.validate(fixture(), 'a' * 40, 123, 1)['assertions'], 28)

    def test_no_missing_extra_duplicate_or_foreign_provider(self):
        for case in receipts.CASES:
            files = fixture()
            del files['ledger-' + case + '.json']
            with self.subTest(case=case), self.assertRaises(receipts.Refusal):
                receipts.validate(files, 'a' * 40, 123, 1)
        files = fixture()
        files['extra.json'] = b'{}'
        with self.assertRaises(receipts.Refusal):
            receipts.validate(files, 'a' * 40, 123, 1)

    def test_each_cleanup_and_origin_obligation_is_mandatory(self):
        for key in ('state', 'profile', 'resources', 'php', 'memory', 'source'):
            files = fixture()
            value = json.loads(files['ledger-up-overlap.json'])
            value['cleanup'][key] = False
            files['ledger-up-overlap.json'] = json.dumps(value).encode()
            with self.subTest(key=key), self.assertRaises(receipts.Refusal):
                receipts.validate(files, 'a' * 40, 123, 1)
        for key in ('parent_source_bound', 'killed_worker_source_bound_to_original_supervised_pid', 'all_registered_pids_accounted'):
            files = fixture()
            value = json.loads(files['ledger-up-overlap.json'])
            value['proof']['lineage'][key] = False
            files['ledger-up-overlap.json'] = json.dumps(value).encode()
            with self.subTest(key=key), self.assertRaises(receipts.Refusal):
                receipts.validate(files, 'a' * 40, 123, 1)

    def test_wrong_sha_run_attempt_exit_assertions_and_fesw_refuse(self):
        for key, replacement in [('candidate_sha', 'f' * 40), ('run_id', 124), ('run_attempt', 2),
                                 ('outer_exit', 1), ('assertions', 0), ('assertions', True),
                                 ('strict_no_fesw', False), ('outer_reaped', False), ('lease_returnable', False)]:
            files = fixture()
            value = json.loads(files['ledger-up-overlap.json'])
            value[key] = replacement
            files['ledger-up-overlap.json'] = json.dumps(value).encode()
            with self.subTest(key=key, replacement=replacement), self.assertRaises(receipts.Refusal):
                receipts.validate(files, 'a' * 40, 123, 1)

    def test_independent_case_admissions_and_same_owned_server_required(self):
        for key, replacement in [('job_descriptor_sha256', hashlib.sha256(b'up-overlap').hexdigest()),
                                 ('server_uuid_sha256', 'f' * 64), ('runtime_image', 'sha256:' + 'f' * 64),
                                 ('mysql_image', 'mysql:8.4')]:
            files = fixture()
            value = json.loads(files['ledger-down-end.json'])
            value['proof'][key] = replacement
            files['ledger-down-end.json'] = json.dumps(value).encode()
            with self.subTest(key=key), self.assertRaises(receipts.Refusal):
                receipts.validate(files, 'a' * 40, 123, 1)

    def test_no_suite_order_counter_or_resource_teardown_forgery(self):
        for key, replacement in [('cases', list(reversed(receipts.CASES))), ('assertions', 29),
                                 ('service_removed', False), ('owned_volume_removed', False)]:
            files = fixture()
            value = json.loads(files['ledger-suite.json'])
            value[key] = replacement
            files['ledger-suite.json'] = json.dumps(value).encode()
            with self.subTest(key=key), self.assertRaises(receipts.Refusal):
                receipts.validate(files, 'a' * 40, 123, 1)


if __name__ == '__main__':
    unittest.main()
