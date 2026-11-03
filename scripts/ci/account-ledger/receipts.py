#!/usr/bin/env python3
"""Strict common four-provider receipt validation; current-run API collection only."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
CASES = ('up-overlap', 'down-overlap', 'up-end', 'down-end')
NAMES = {f'ledger-{case}.json' for case in CASES} | {'ledger-suite.json'}


class Refusal(RuntimeError):
    pass


def require(ok, reason):
    if not ok:
        raise Refusal(reason)


def closed(value, keys):
    require(type(value) is dict and set(value) == set(keys), 'closed-ledger-receipt')


def unique(pairs):
    value = {}
    for key, item in pairs:
        require(key not in value, 'duplicate-ledger-field')
        value[key] = item
    return value


def value(raw):
    require(type(raw) is bytes and len(raw) <= 65536, 'ledger-file-bound')
    return json.loads(raw, object_pairs_hook=unique)


def validate(files, sha, run_id, attempt):
    require(set(files) == NAMES and re.fullmatch('[0-9a-f]{40}', sha), 'all-four-current-receipts-required')
    total = 0
    runtime_identities, descriptors = set(), set()
    for case in CASES:
        item = value(files['ledger-' + case + '.json'])
        closed(item, ('schema', 'kind', 'candidate_sha', 'run_id', 'run_attempt', 'case', 'assertions',
                      'outer_exit', 'outer_reaped', 'strict_no_fesw', 'proof', 'cleanup', 'lease_returnable', 'case_container_removed'))
        require(type(item['schema']) is int and item['schema'] == 1 and item['kind'] == 'required-supervised-ledger-case'
                and item['candidate_sha'] == sha and item['run_id'] == run_id and item['run_attempt'] == attempt
                and type(item['run_id']) is int and type(item['run_attempt']) is int and item['case'] == case,
                'ledger-source-run-case')
        require(type(item['assertions']) is int and 0 < item['assertions'] <= 10000000
                and type(item['outer_exit']) is int and item['outer_exit'] == 0
                and all(item[key] is True for key in ('outer_reaped', 'strict_no_fesw', 'lease_returnable', 'case_container_removed')),
                'ledger-case-incomplete')
        closed(item['cleanup'], ('state', 'profile', 'resources', 'php', 'memory', 'source'))
        require(all(result is True for result in item['cleanup'].values()), 'six-cleanup-checks-required')
        proof = item['proof']
        closed(proof, ('junit_sha256', 'launcher_sha256', 'parent_worker_restart_origins', 'lineage',
                       'job_descriptor_sha256', 'runtime_image', 'mysql_image', 'controller_engine', 'server_uuid_sha256'))
        require(all(type(proof[key]) is str and re.fullmatch('[0-9a-f]{64}', proof[key])
                    for key in ('junit_sha256', 'launcher_sha256', 'job_descriptor_sha256', 'server_uuid_sha256'))
                and proof['parent_worker_restart_origins'] is True,
                'strict-origin-evidence-required')
        require(re.fullmatch('sha256:[0-9a-f]{64}', proof['runtime_image'])
                and proof['mysql_image'] == 'mysql@sha256:0f51e63e537a1f176423fa03ef559aabe6fa4372a53160a44bc7e75da7d15364'
                and proof['controller_engine'] == 'docker@sha256:b0ea6e8d57b28d04a6999591fdd0f10de8717e956f4f4b6128d2a8cf57b27eb9', 'qualified-runtime-provenance')
        runtime_identities.add((proof['runtime_image'], proof['server_uuid_sha256']))
        descriptors.add(proof['job_descriptor_sha256'])
        require(proof['lineage'] == {'parent_source_bound': True, 'killed_worker_source_bound_to_original_supervised_pid': True,
            'worker_actual_execution_proof': 'ORIGINAL_READY_REFLECTION_STACK_ASSERTIONS',
            'ordinary_artisan_origins': 2 if case.startswith('up-') else 1, 'all_registered_pids_accounted': True},
            'full-process-lineage-required')
        require(type(proof['lineage']['ordinary_artisan_origins']) is int
                and all(proof['lineage'][key] is True for key in ('parent_source_bound',
                    'killed_worker_source_bound_to_original_supervised_pid', 'all_registered_pids_accounted')),
                'typed-process-lineage')
        total += item['assertions']
    suite = value(files['ledger-suite.json'])
    require(len(runtime_identities) == 1 and len(descriptors) == 4, 'one-owned-service-four-distinct-admissions')
    closed(suite, ('schema', 'kind', 'candidate_sha', 'run_id', 'run_attempt', 'cases', 'assertions',
                   'service_removed', 'owned_volume_removed', 'controller_shutdown_required'))
    require(type(suite['schema']) is int and suite['schema'] == 1 and suite['kind'] == 'required-supervised-ledger-suite'
            and suite['candidate_sha'] == sha and suite['run_id'] == run_id and suite['run_attempt'] == attempt
            and suite['cases'] == list(CASES) and type(suite['assertions']) is int and suite['assertions'] == total
            and all(suite[key] is True for key in ('service_removed', 'owned_volume_removed', 'controller_shutdown_required')),
            'complete-serial-suite-required')
    return {'cases': list(CASES), 'assertions': total, 'same_current_source': True,
            'strict_four_provider_receipts': True, 'outer_acceptance': 'pending aggregate and pipeline conclusion'}


def collect_github(root, env, api=None):
    spec = importlib.util.spec_from_file_location('ledger_database_transport', ROOT / 'scripts/ci/database-receipts.py')
    proof = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(proof)
    source = proof.source_identity(root, env)
    run_id, attempt = source['run_id'], source['run_attempt']
    api = api or proof.Github(env.get('RECEIPT_GITHUB_TOKEN', ''))
    workflow = api.get('/actions/workflows/final-verification.yml')
    run = api.get(f'/actions/runs/{run_id}')
    require(workflow.get('path') == proof.WORKFLOW_PATH and workflow.get('name') == 'Foundation CI'
            and run.get('workflow_id') == workflow.get('id') and run.get('id') == run_id and run.get('run_attempt') == attempt
            and run.get('path') == proof.WORKFLOW_PATH and run.get('event') == 'workflow_dispatch'
            and run.get('head_sha') == source['event']['head'] and run.get('repository', {}).get('id') == proof.REPOSITORY_ID,
            'canonical-current-ledger-workflow')
    jobs = api.pages(f'/actions/runs/{run_id}/attempts/{attempt}/jobs', 'jobs')
    matched = [job for job in jobs if job.get('name') == 'backend-ledger']
    require(len(matched) == 1, 'one-required-ledger-job')
    job = matched[0]
    require(job.get('run_id') == run_id and job.get('run_attempt') == attempt and job.get('head_sha') == source['event']['head']
            and job.get('status') == 'completed' and job.get('conclusion') == 'success', 'required-ledger-job-success')
    for name in ('Verify the exact ledger candidate', 'Run the required four serial supervised ledger providers',
                 'Retain required supervised ledger receipts'):
        steps = [step for step in job.get('steps', []) if step.get('name') == name]
        require(len(steps) == 1 and steps[0].get('status') == 'completed' and steps[0].get('conclusion') == 'success',
                'required-ledger-step')
    artifacts = api.pages(f'/actions/runs/{run_id}/artifacts', 'artifacts')
    matched = [item for item in artifacts if item.get('name') == f'backend-ledger-{run_id}-{attempt}']
    require(len(matched) == 1, 'one-current-ledger-artifact')
    artifact = matched[0]
    require(proof.positive(artifact.get('id')) and artifact.get('expired') is False
            and artifact.get('workflow_run', {}).get('id') == run_id
            and artifact.get('workflow_run', {}).get('repository_id') == proof.REPOSITORY_ID
            and re.fullmatch('sha256:[0-9a-f]{64}', artifact.get('digest', '')), 'ledger-artifact-provenance')
    raw = api.artifact(artifact['id'])
    require(hashlib.sha256(raw).hexdigest() == artifact['digest'][7:], 'ledger-artifact-digest')
    summary = validate(proof.archive(raw, NAMES), source['event']['head'], run_id, attempt)
    return {**summary, 'source': source, 'job_id': job['id'], 'artifact_id': artifact['id'], 'reuse_enabled': False}


def collect_gitlab(api, job, identity, proof, timestamp, now):
    artifacts = job.get('artifacts')
    require(type(artifacts) is list, 'native-ledger-artifacts')
    archives = [item for item in artifacts if item.get('file_type') == 'archive']
    expected = f"ledger-{identity['pipeline_id']}-{job['id']}.zip"
    require(len(archives) == 1 and archives[0].get('filename') == expected and archives[0].get('file_format') == 'zip'
            and proof.positive(archives[0].get('size')) and archives[0]['size'] <= proof.MAX_ZIP
            and timestamp(job.get('artifacts_expire_at')) > now, 'native-ledger-archive-provenance')
    raw = api.artifact(job['id'])
    require(len(raw) == archives[0]['size'], 'native-ledger-archive-size')
    return validate(proof.archive(raw, NAMES), identity['commit'], identity['pipeline_id'], job['id'])


if __name__ == '__main__':
    try:
        if sys.argv[1:] != ['collect']:
            raise Refusal('explicit-current-run-collection-required')
        result = collect_github(ROOT, os.environ)
        (ROOT / 'ledger-collection.json').write_text(json.dumps(result, indent=2) + '\n')
    except Exception:
        raise SystemExit('Required current-run supervised ledger receipts refused.') from None
