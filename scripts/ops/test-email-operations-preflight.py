#!/usr/bin/env python3
"""Real isolated CLI and opened-file controls; all facts are synthetic declarations."""

import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/ops/email-operations-preflight.py"
TEMPLATE = json.loads((ROOT / "ops/email-operations/facts.example.json").read_text())
SPEC = importlib.util.spec_from_file_location("email_preflight", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
SENTINEL = "SYNTHETIC_PRIVATE_FACT_NEVER_REPORT_5721"


def configured():
    value = copy.deepcopy(TEMPLATE)
    value["candidate_sha"] = "a" * 40
    value["public_origin"] = "https://store.example.com"
    smtp = value["planned_identity_smtp"]
    for key in smtp:
        smtp[key] = "synthetic-record-v1"
    smtp.update(host="smtp.example.com", port=587, security="starttls", sender="identity@example.com",
                hello_domain="store.example.com", revision="12345678-1234-4234-8234-123456789abc")
    for key in value["planned_suppression"]:
        value["planned_suppression"][key] = "synthetic-suppression-v1"
    for key in value["operations"]:
        if key.endswith("_reference"):
            value["operations"][key] = "synthetic-operations-v1"
    for key in ("policy_reference", "log_policy_reference"):
        value["retention"][key] = "synthetic-retention-v1"
    return value


class EmailOperationsTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="va-email-facts-")
        self.directory = Path(self.temporary.name)
        self.path = self.directory / "facts.json"

    def tearDown(self):
        self.temporary.cleanup()

    def run_check(self, value=None, raw=None, mode=0o600, args=None, env=None):
        if value is not None or raw is not None:
            if self.path.exists():
                self.path.unlink()
            self.path.write_bytes(raw if raw is not None else json.dumps(value).encode())
            self.path.chmod(mode)
        result = subprocess.run([sys.executable, str(SCRIPT), *(args or ["--facts", str(self.path)])],
                                capture_output=True, text=True, env=env)
        self.assertEqual("", result.stderr)
        self.assertNotIn(SENTINEL, result.stdout)
        self.assertNotIn(str(self.directory), result.stdout)
        report = json.loads(result.stdout)
        for key in ("host_verified", "provider_authenticated", "activation_ready", "deployment_ready"):
            self.assertIs(report[key], False)
        self.assertIs(report["declared_values_are_not_observations"], True)
        self.assertEqual(9, len(report["unverified"]))
        return result, report

    def blocked(self, **kwargs):
        result, report = self.run_check(**kwargs)
        self.assertEqual(1, result.returncode)
        self.assertEqual("BLOCKED", report["result"])
        return report

    def test_template_is_valid_only_in_explicit_template_scope(self):
        result, report = self.run_check(args=["--template"])
        self.assertEqual(0, result.returncode)
        self.assertEqual("TEMPLATE_VALID", report["result"])
        self.assertEqual("template", report["scope"])

    def test_template_copy_is_incomplete_operator_facts(self):
        report = self.blocked(value=TEMPLATE)
        self.assertEqual("operator_declared_configuration_facts", report["scope"])

    def test_complete_synthetic_declarations_are_never_host_evidence(self):
        result, report = self.run_check(value=configured())
        self.assertEqual(0, result.returncode)
        self.assertEqual("DECLARED_FACTS_VALID", report["result"])

    def test_missing_sender_provider_and_retention_references_block(self):
        for section, key in [("planned_identity_smtp", "sender"), ("planned_identity_smtp", "provider_reference"),
                             ("planned_suppression", "account_scope_reference"), ("retention", "policy_reference")]:
            with self.subTest(section=section, key=key):
                value = configured(); value[section][key] = None
                self.blocked(value=value)

    def test_all_activation_and_binding_flags_must_remain_literal_false(self):
        for key in TEMPLATE["disabled_baseline"]:
            if key == "general_mailer":
                continue
            for invalid in (True, 0, "false", None):
                with self.subTest(key=key, invalid=invalid):
                    value = configured(); value["disabled_baseline"][key] = invalid
                    self.blocked(value=value)

    def test_general_mail_smtp_or_failover_does_not_bind_identity_transport(self):
        for mailer in ("smtp", "failover", "array", None):
            value = configured(); value["disabled_baseline"]["general_mailer"] = mailer
            self.blocked(value=value)

    def test_origin_requires_canonical_https_without_credentials_path_or_query(self):
        for origin in ("http://store.example.com", "https://user:pass@example.com", "https://example.com/",
                       "https://example.com?x=1", "https://example.com#part", "https://example.com:0",
                       "https://example.com:65536", "https://example.com:bad", "https://EXAMPLE.com"):
            value = configured(); value["public_origin"] = origin
            self.blocked(value=value)

    def test_custom_https_port_is_a_shape_not_connectivity_proof(self):
        value = configured(); value["public_origin"] = "https://store.example.com:8443"
        result, _ = self.run_check(value=value); self.assertEqual(0, result.returncode)

    def test_origin_rejects_empty_and_noncanonical_explicit_ports(self):
        for origin in ("https://store.example.com:", "https://store.example.com:000443",
                       "https://store.example.com:0443"):
            with self.subTest(origin=origin):
                value = configured(); value["public_origin"] = origin
                self.blocked(value=value)

    def test_candidate_requires_exact_lowercase_40_character_shape(self):
        for candidate in (None, "a" * 39, "a" * 41, "A" * 40, True):
            value = configured(); value["candidate_sha"] = candidate
            self.blocked(value=value)

    def test_smtp_accepts_source_supported_modes_and_port_range(self):
        for port, mode in [(1, "implicit_tls"), (65535, "starttls"), (465, "implicit_tls")]:
            value = configured(); value["planned_identity_smtp"].update(port=port, security=mode)
            result, _ = self.run_check(value=value); self.assertEqual(0, result.returncode)

    def test_plaintext_or_invalid_smtp_ports_are_refused(self):
        for field, invalid in [("security", "none"), ("security", "tls"), ("port", 0), ("port", 65536),
                               ("port", True), ("port", "587"), ("host", "smtp.invalid"), ("host", "127.0.0.1"),
                               ("sender", "identity@example.test"), ("sender", "Name <identity@example.com>")]:
            value = configured(); value["planned_identity_smtp"][field] = invalid
            self.blocked(value=value)

    def test_sender_and_public_origin_need_not_share_domain(self):
        value = configured(); value["planned_identity_smtp"]["sender"] = "identity@another.example.com"
        result, _ = self.run_check(value=value); self.assertEqual(0, result.returncode)

    def test_provider_scopes_remain_distinct_operator_references(self):
        value = configured(); value["planned_suppression"]["account_scope_reference"] = "different-synthetic-scope"
        result, _ = self.run_check(value=value); self.assertEqual(0, result.returncode)
        del value["planned_suppression"]["review_reference"]; self.blocked(value=value)

    def test_credential_fields_and_unknown_keys_are_never_admitted_or_echoed(self):
        for section in (None, "planned_identity_smtp", "planned_suppression"):
            value = configured(); target = value if section is None else value[section]
            target["password"] = SENTINEL; self.blocked(value=value)

    def test_duplicate_and_escaped_duplicate_names_are_refused(self):
        source = json.dumps(configured())
        self.blocked(raw=source.replace('"schema_version": 1', '"schema_version": 1, "schema_version": 1').encode())
        self.blocked(raw=source.replace('"schema_version": 1', '"schema_version": 1, "schema_\\u0076ersion": 1').encode())

    def test_invalid_utf8_truncation_and_nonfinite_values_are_refused(self):
        for raw in (b"\xff", b'{"password":"' + SENTINEL.encode(), b"NaN", b"Infinity", b"1.5"):
            self.blocked(raw=raw)

    def test_arrays_deep_objects_and_unpaired_surrogates_are_refused(self):
        for raw in (b"[]", b'{"x":' * 20 + b"null" + b"}" * 20, b'{"x":"\\ud800"}'):
            self.blocked(raw=raw)

    def test_input_exact_byte_cap_is_accepted_and_next_byte_refused(self):
        raw = json.dumps(configured()).encode(); raw += b" " * (MODULE.MAX_BYTES - len(raw))
        result, _ = self.run_check(raw=raw); self.assertEqual(0, result.returncode)
        self.blocked(raw=raw + b" ")

    def test_world_access_group_write_and_no_owner_read_are_refused(self):
        for mode in (0o644, 0o660, 0o700, 0o000, 0o4600):
            self.blocked(value=configured(), mode=mode)

    def test_owner_readonly_and_trusted_group_read_are_admitted(self):
        for mode in (0o400, 0o600, 0o440, 0o640):
            result, _ = self.run_check(value=configured(), mode=mode); self.assertEqual(0, result.returncode)

    def test_symlink_file_and_parent_are_refused(self):
        self.run_check(value=configured())
        link = self.directory / "alias"; link.symlink_to(self.path)
        self.blocked(args=["--facts", str(link)])
        parent = self.directory / "alias-parent"; parent.symlink_to(self.directory, target_is_directory=True)
        self.blocked(args=["--facts", str(parent / "facts.json")])

    def test_hardlink_and_nonregular_input_are_refused(self):
        self.run_check(value=configured()); os.link(self.path, self.directory / "hardlink")
        self.blocked(); self.blocked(args=["--facts", str(self.directory)])

    def test_missing_and_relative_inputs_produce_fixed_output(self):
        self.blocked(); self.blocked(args=["--facts", "relative.json"])

    def test_usage_is_closed(self):
        for args in ([], ["--template", "extra"], ["--facts"], ["--facts", "/missing", "--probe"]):
            # Explicit subprocess for [] because run_check uses default args for an empty list.
            if not args:
                result = subprocess.run([sys.executable, str(SCRIPT)], capture_output=True, text=True)
                self.assertEqual(1, result.returncode); self.assertEqual("", result.stderr)
            else:
                self.blocked(args=args)

    def test_queue_retry_must_exceed_shared_lease_and_worker_timeout(self):
        for field, invalid in [("queue_retry_after_seconds", 960), ("queue_retry_after_seconds", True),
                               ("worker_timeout_seconds", 901), ("worker_tries", 3),
                               ("queue_connection", "sync"), ("worker_tries", True)]:
            value = configured(); value["operations"][field] = invalid
            self.blocked(value=value)

    def test_recovery_limit_is_actual_integer_one_through_100(self):
        for limit in (1, 100):
            value = configured(); value["operations"]["identity_recovery_limit"] = limit
            result, _ = self.run_check(value=value); self.assertEqual(0, result.returncode)
        for limit in (0, 101, True, "50"):
            value = configured(); value["operations"]["identity_recovery_limit"] = limit
            self.blocked(value=value)

    def test_retention_cannot_enable_purge_or_remove_append_only_facts(self):
        for field, invalid in [("purge_enabled", True), ("identity_evidence_append_only", False),
                               ("suppression_evidence_append_only", 1)]:
            value = configured(); value["retention"][field] = invalid
            self.blocked(value=value)

    def test_reference_is_never_shell_evaluated_or_environment_interpolated(self):
        sentinel = self.directory / "executed"
        for reference in ("$(touch " + str(sentinel) + ")", "${B5_FACT_REFERENCE}", "https://provider.example.com/secret"):
            value = configured(); value["planned_identity_smtp"]["host_secret_reference"] = reference
            self.blocked(value=value, env={**os.environ, "B5_FACT_REFERENCE": SENTINEL})
        self.assertFalse(sentinel.exists())

    def test_opened_descriptor_change_is_refused(self):
        self.run_check(value=configured()); original_read = MODULE.os.read
        changed = False
        def rewrite(descriptor, count):
            nonlocal changed
            data = original_read(descriptor, count)
            if not changed:
                changed = True
                self.path.write_bytes(self.path.read_bytes() + b" ")
            return data
        with patch.object(MODULE.os, "read", rewrite):
            with self.assertRaisesRegex(MODULE.Refused, "input_stable_read"):
                MODULE.protected_read(str(self.path))

    def test_opened_path_replacement_is_refused(self):
        self.run_check(value=configured()); original_read = MODULE.os.read
        changed = False
        def replace(descriptor, count):
            nonlocal changed
            data = original_read(descriptor, count)
            if not changed:
                changed = True
                alternative = self.directory / "replacement"
                alternative.write_bytes(self.path.read_bytes()); alternative.chmod(0o600)
                alternative.replace(self.path)
            return data
        with patch.object(MODULE.os, "read", replace):
            with self.assertRaisesRegex(MODULE.Refused, "input_stable_read"):
                MODULE.protected_read(str(self.path))


if __name__ == "__main__":
    unittest.main()
