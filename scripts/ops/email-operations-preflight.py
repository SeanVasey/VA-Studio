#!/usr/bin/env python3
"""Inspect declared email preparation facts. Never boot, connect, send or install."""

import json
import os
from pathlib import Path
import re
import stat
import sys
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
MAX_BYTES = 32768
UNVERIFIED = [
    "effective_web_worker_and_cached_configuration",
    "sender_ownership_spf_dkim_dmarc_and_dns",
    "smtp_credentials_peer_tls_and_account_scope",
    "provider_adapter_authentication_and_suppression_conditions",
    "queue_supervision_timing_and_missed_wakeup_recovery",
    "scheduler_installation_heartbeat_and_backlog_progress",
    "approved_retention_access_controls_and_restore",
    "actual_submission_delivery_and_unknown_outcome_reconciliation",
    "native_activation_performance_and_release_acceptance",
]
SHAPE = {
    "schema_version": None, "candidate_sha": None, "public_origin": None,
    "disabled_baseline": {
        "identity_enabled": None, "notifications_enabled": None,
        "smtp_settings_bound": None, "suppression_enabled": None,
        "suppression_provider_bound": None, "general_mailer": None,
    },
    "planned_identity_smtp": {
        "provider_reference": None, "account_scope_reference": None,
        "host": None, "port": None, "security": None, "sender": None,
        "hello_domain": None, "revision": None, "host_secret_reference": None,
        "sender_review_reference": None,
    },
    "planned_suppression": {
        "provider_reference": None, "account_scope_reference": None,
        "adapter_revision": None, "review_reference": None,
        "regrant_policy_reference": None, "unknown_outcome_runbook_reference": None,
    },
    "operations": {
        "queue_connection": None, "worker_timeout_seconds": None,
        "queue_retry_after_seconds": None, "worker_tries": None,
        "identity_recovery_limit": None, "worker_supervision_reference": None,
        "identity_recovery_schedule_reference": None,
        "scheduler_observation_reference": None,
    },
    "retention": {
        "policy_reference": None, "log_policy_reference": None,
        "identity_evidence_append_only": None,
        "suppression_evidence_append_only": None, "purge_enabled": None,
    },
}


class Refused(Exception):
    pass


def protected_read(raw_path, template=False):
    """One bounded descriptor; parent protection remains an operator obligation."""
    if not isinstance(raw_path, str) or not raw_path.startswith("/") or "\0" in raw_path:
        raise Refused("input_canonical_regular_file")
    if os.path.realpath(raw_path) != raw_path:
        raise Refused("input_canonical_regular_file")
    cursor = Path("/")
    for part in Path(raw_path).parts[1:]:
        cursor /= part
        if cursor.is_symlink():
            raise Refused("input_canonical_regular_file")
    before = os.lstat(raw_path)
    if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_size > MAX_BYTES:
        raise Refused("input_bounded_single_regular_file")
    if not template and (before.st_mode & 0o7137 or not before.st_mode & 0o400):
        raise Refused("input_permissions")
    descriptor = os.open(raw_path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        opened = os.fstat(descriptor)
        if not stat.S_ISREG(opened.st_mode):
            raise Refused("input_stable_read")
        parts, remaining = [], MAX_BYTES + 1
        while remaining:
            piece = os.read(descriptor, min(8192, remaining))
            if not piece:
                break
            parts.append(piece)
            remaining -= len(piece)
        source = b"".join(parts)
        finished, after = os.fstat(descriptor), os.lstat(raw_path)
        fields = ("st_dev", "st_ino", "st_mode", "st_nlink", "st_size", "st_mtime_ns", "st_ctime_ns")
        if len(source) > MAX_BYTES or os.path.realpath(raw_path) != raw_path or any(
            len({getattr(item, field) for item in (before, opened, finished, after)}) != 1
            for field in fields
        ):
            raise Refused("input_stable_read")
        return source
    finally:
        os.close(descriptor)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise Refused("json_closed_unique_structure")
        result[key] = value
    return result


def parse(source):
    def nonfinite(_):
        raise Refused("json_closed_unique_structure")
    value = json.loads(source.decode("utf-8"), object_pairs_hook=unique_object, parse_constant=nonfinite)
    nodes = 0

    def bound(item, depth=0):
        nonlocal nodes
        nodes += 1
        if nodes > 256 or depth > 8:
            raise Refused("json_closed_unique_structure")
        if isinstance(item, dict):
            for key, child in item.items():
                if len(key) > 96:
                    raise Refused("json_closed_unique_structure")
                bound(child, depth + 1)
        elif isinstance(item, list):
            raise Refused("json_closed_unique_structure")
        elif isinstance(item, str):
            if len(item) > 512 or any(ord(char) < 32 or 0xD800 <= ord(char) <= 0xDFFF for char in item):
                raise Refused("json_closed_unique_structure")
        elif item is not None and type(item) not in (bool, int):
            raise Refused("json_closed_unique_structure")
    bound(value)
    return value


def shaped(value, shape):
    return type(value) is dict and set(value) == set(shape) and all(
        child is None or shaped(value[key], child) for key, child in shape.items()
    )


def domain(value):
    return isinstance(value, str) and len(value) <= 253 and re.fullmatch(
        r"(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?", value
    ) is not None


def origin(value):
    if not isinstance(value, str):
        return False
    try:
        parts = urlsplit(value)
        port = parts.port
    except ValueError:
        return False
    if not domain(parts.hostname):
        return False
    canonical = "https://" + parts.hostname + ("" if port is None else ":" + str(port))
    # This installation planning format is deliberately canonical HTTPS; it makes no DNS claim.
    return parts.scheme == "https" and not parts.username and not parts.password \
        and not parts.path and not parts.query and not parts.fragment \
        and value == canonical and (port is None or 1 <= port <= 65535)


def reference(value):
    # An opaque operator record name. Never dereferenced or executed; never a credential commitment.
    return isinstance(value, str) and re.fullmatch(r"[a-z][a-z0-9._:-]{0,95}", value) is not None


def integer(value, low, high):
    return type(value) is int and low <= value <= high


def validate(facts, template):
    checks = []
    def check(name, accepted):
        checks.append({"id": name, "status": "pass" if accepted else "blocked"})
    check("closed_versioned_schema", shaped(facts, SHAPE) and type(facts.get("schema_version")) is int
          and facts["schema_version"] == 1)
    if checks[-1]["status"] == "blocked":
        return checks
    allowed = lambda value, predicate: value is None and template or predicate(value)
    check("candidate_shape", allowed(facts["candidate_sha"], lambda v: isinstance(v, str) and re.fullmatch(r"[a-f0-9]{40}", v) is not None))
    check("planned_https_origin", allowed(facts["public_origin"], origin))
    baseline = facts["disabled_baseline"]
    check("literal_disabled_unbound_baseline", all(baseline[k] is False for k in baseline if k != "general_mailer")
          and baseline["general_mailer"] == "log")
    smtp = facts["planned_identity_smtp"]
    validators = {key: reference for key in smtp}
    validators.update(host=lambda v: domain(v) and not v.endswith((".test", ".invalid", ".localhost")),
                      hello_domain=domain, port=lambda v: integer(v, 1, 65535),
                      security=lambda v: v in ("implicit_tls", "starttls"),
                      sender=lambda v: isinstance(v, str) and len(v) <= 254 and re.fullmatch(r"[a-z0-9._+-]+@[^@]+", v) is not None and domain(v.split("@", 1)[1]) and not v.endswith(".test"),
                      revision=lambda v: isinstance(v, str) and re.fullmatch(r"[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}", v) is not None)
    check("planned_identity_smtp_scope", all(allowed(value, validators[key]) for key, value in smtp.items()))
    check("planned_suppression_separate_scope", all(allowed(value, reference) for value in facts["planned_suppression"].values()))
    operation = facts["operations"]
    check("queued_wakeup_configuration", operation["queue_connection"] == "database"
          and integer(operation["worker_timeout_seconds"], 1, 900)
          and integer(operation["queue_retry_after_seconds"], 1, 86400)
          and operation["queue_retry_after_seconds"] > max(960, operation["worker_timeout_seconds"])
          and type(operation["worker_tries"]) is int and operation["worker_tries"] == 1)
    check("bounded_identity_recovery", integer(operation["identity_recovery_limit"], 1, 100))
    check("declared_supervision_and_schedule_references", all(allowed(operation[k], reference) for k in (
        "worker_supervision_reference", "identity_recovery_schedule_reference", "scheduler_observation_reference")))
    retention = facts["retention"]
    check("append_only_no_purge", retention["identity_evidence_append_only"] is True
          and retention["suppression_evidence_append_only"] is True and retention["purge_enabled"] is False)
    check("declared_retention_references", all(allowed(retention[k], reference) for k in ("policy_reference", "log_policy_reference")))
    return checks


def report(checks, template):
    blocked = any(check["status"] == "blocked" for check in checks)
    return {
        "schema_version": 1, "scope": "template" if template else "operator_declared_configuration_facts",
        "result": "BLOCKED" if blocked else ("TEMPLATE_VALID" if template else "DECLARED_FACTS_VALID"),
        "host_verified": False, "provider_authenticated": False, "activation_ready": False,
        "deployment_ready": False, "declared_values_are_not_observations": True,
        "checks": checks, "unverified": UNVERIFIED,
    }


def main(arguments):
    template = arguments == ["--template"]
    try:
        if template:
            path = str(ROOT / "ops/email-operations/facts.example.json")
        elif len(arguments) == 2 and arguments[0] == "--facts":
            path = arguments[1]
        else:
            raise Refused("usage_template_or_absolute_facts_file")
        source = protected_read(path, template)
        try:
            facts = parse(source)
        except (ValueError, UnicodeError, RecursionError):
            raise Refused("json_closed_unique_structure") from None
        checks = validate(facts, template)
    except Refused as refused:
        checks = [{"id": str(refused), "status": "blocked"}]
    except Exception:
        checks = [{"id": "inspection_failed", "status": "blocked"}]
    result = report(checks, template)
    print(json.dumps(result, indent=2))
    return 1 if result["result"] == "BLOCKED" else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
