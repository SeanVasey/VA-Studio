#!/usr/bin/env python3
"""Native GitLab provenance and complete-shard adversarial unit fixtures, not acceptance."""
from copy import deepcopy
from datetime import datetime, timedelta, timezone
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value

native = module('gitlab_receipts', 'gitlab-database-receipts.py')
fixtures = module('legacy_receipt_fixtures', 'test-database-receipts.py')
proof = native.proof
H = fixtures.H
ROOT = Path(fixtures.ROOT)
NOW = datetime.now(timezone.utc)


def at(seconds):
    return (NOW + timedelta(seconds=seconds)).isoformat()


def identity():
    return {'provider': 'gitlab', 'project_id': native.PROJECT, 'project_path': native.PATH,
            'commit': 'a' * 40, 'tree': 'b' * 40, 'event': 'merge_request_event',
            'ref': 'refs/merge-requests/1/head', 'pipeline_id': 123,
            'policy_sha256': {name: H for name in native.POLICY}}


def env():
    return {'GITLAB_CI': 'true', 'CI_PROJECT_ID': str(native.PROJECT), 'CI_PROJECT_PATH': native.PATH,
            'CI_API_V4_URL': native.API, 'CI_COMMIT_SHA': 'a' * 40, 'CI_PIPELINE_ID': '123',
            'CI_PIPELINE_SOURCE': 'merge_request_event', 'CI_MERGE_REQUEST_IID': '1',
            'CI_MERGE_REQUEST_PROJECT_ID': str(native.PROJECT), 'CI_MERGE_REQUEST_EVENT_TYPE': 'detached',
            'CI_JOB_ID': '13', 'CI_JOB_NAME': 'backend', 'CI_RUNNER_ID': '513'}


def runtime(engine):
    database = {'version': '3.50.1'} if engine == 'sqlite' else {
        'version': '8.4.11', 'version_comment': 'MySQL Community', 'sql_mode': 'STRICT_TRANS_TABLES',
        'character_set_server': 'utf8mb4', 'collation_server': 'utf8mb4_0900_ai_ci', 'lower_case_table_names': 0, 'innodb_strict_mode': 1, 'performance_schema': 1, 'isolation': 'REPEATABLE-READ', 'storage_engine': 'InnoDB',
        'service_image_reference': 'mysql:8.4', 'service_image_digest': 'not exposed by GitLab service API; no reuse enabled'}
    return {'engine': engine, 'php': {'version': '8.4.26', 'integer_size': 8, 'memory_limit': '512M', 'binary_sha256': H,
            'extensions': ['fileinfo', 'pdo_mysql', 'pdo_sqlite', 'mbstring', 'intl', 'bcmath', 'gd', 'zip', 'curl', 'dom', 'xml', 'xmlwriter', 'posix', 'pcntl']},
            'tools': {key: {'binary_sha256': H, 'version_sha256': H} for key in ('composer', 'ffmpeg', 'qpdf', 'pdftocairo', 'flock')},
            'database': database, 'dependencies': fixtures.runtime(engine)['dependencies'], 'os_release_sha256': H}


def producer(job):
    return {'job_id': job['id'], 'job_name': job['name'], 'runner_id': job['runner']['id'], 'job_image_reference': 'php:8.4-cli-bookworm'}


def evidence(engine, shard, job, alternate=False):
    files = fixtures.evidence(engine, shard, alternate)
    prefix = f'phpunit-ci-{engine}-{shard}'
    del files[prefix + '-receipt.json']
    initial = {'schema_version': 1, 'purpose': 'gitlab-database-start-not-acceptance', 'engine': engine, 'shard': shard,
               'source': identity(), 'producer': producer(job), 'checkout_root': str(ROOT),
               'runtime': runtime(engine), 'started_at': at(-40)}
    files[prefix + '-start.json'] = proof.canonical(initial)
    value = initial | {'purpose': 'gitlab-database-receipt-not-acceptance', 'runtime_sha256': proof.digest(proof.canonical(runtime(engine))),
                       'test_step_outcome': 'success', 'finished_at': at(-30),
                       **proof.database_evidence(files, {'checkout_root': str(ROOT), 'policy_sha256': identity()['policy_sha256']}, engine, shard, {fixtures.SKIP})}
    files[prefix + '-receipt.json'] = proof.canonical(value)
    return files


class FakeGitlab:
    def __init__(self):
        self.pipeline = {'id': 123, 'project_id': native.PROJECT, 'sha': 'a' * 40,
                         'source': 'merge_request_event', 'ref': identity()['ref'], 'status': 'running', 'created_at': at(-70)}
        self.rows, self.archives = [], {}
        for name in [*native.DATABASE_JOBS, *sorted(native.UPSTREAM - native.DATABASE_JOBS.keys()), 'backend']:
            identifier = len(self.rows) + 1
            job = {'id': identifier, 'name': name, 'pipeline': deepcopy(self.pipeline), 'commit': {'id': 'a' * 40},
                   'ref': identity()['ref'], 'allow_failure': False, 'erased_at': None,
                   'status': 'running' if name == 'backend' else 'success', 'created_at': at(-60),
                   'started_at': at(-10) if name == 'backend' else at(-50),
                   'finished_at': None if name == 'backend' else at(-20), 'runner': {'id': 500 + identifier}, 'artifacts': []}
            self.rows.append(job)
            if name in native.DATABASE_JOBS:
                engine, shard = native.DATABASE_JOBS[name]
                self.replace(identifier, evidence(engine, shard, job))
        self.calls = 0

    def get(self, path):
        return deepcopy(self.rows[-1] if path == '/job' else self.pipeline)

    def jobs(self, pipeline):
        self.calls += 1
        return deepcopy(self.rows)

    def artifact(self, identifier):
        return self.archives[identifier]

    def replace(self, identifier, files):
        raw = fixtures.zipped(files)
        self.archives[identifier] = raw
        row = self.rows[identifier - 1]
        name = f"database-{native.DATABASE_JOBS[row['name']][0]}-{native.DATABASE_JOBS[row['name']][1]}-123-{identifier}.zip"
        row['artifacts'] = [{'file_type': 'archive', 'filename': name, 'size': len(raw), 'file_format': 'zip'},
                            {'file_type': 'trace', 'filename': 'job.log', 'size': 10, 'file_format': None}]
        row['artifacts_expire_at'] = at(600000)


class NativeCollectorTests(unittest.TestCase):
    def test_physical_write_process_control_is_required_by_both_engine_receipts(self):
        for engine in ('sqlite', 'mysql'):
            native.validate_runtime(runtime(engine), engine)
            for extension in ('pcntl', 'posix'):
                value = runtime(engine)
                value['php']['extensions'].remove(extension)
                with self.subTest(engine=engine, missing=extension), self.assertRaisesRegex(proof.ReceiptError, 'Unsupported PHP runtime'):
                    native.validate_runtime(value, engine)
                api = FakeGitlab()
                job = next(row for row in api.rows if native.DATABASE_JOBS.get(row['name']) == (engine, 1))
                files = evidence(engine, 1, job)
                for kind in ('start', 'receipt'):
                    key = f'phpunit-ci-{engine}-1-{kind}.json'
                    document = proof.json_data(files[key])
                    document['runtime'] = value
                    if kind == 'receipt':
                        document['runtime_sha256'] = proof.digest(proof.canonical(value))
                    files[key] = proof.canonical(document)
                api.replace(job['id'], files)
                with self.subTest(engine=engine, forged_archive_missing=extension), self.assertRaisesRegex(proof.ReceiptError, 'Unsupported PHP runtime'):
                    self.collect(api)

    def collect(self, api):
        with patch.object(native, 'source', return_value=identity()), patch.object(proof, 'sqlite_skip_pairs', return_value={fixtures.SKIP}), \
                patch.object(proof, 'validate_discovered_files'), patch.object(proof, 'locked_dependencies', return_value=({}, runtime('mysql')['dependencies'])):
            return native.collect(ROOT, env(), api)

    def test_six_complete_native_archives_without_legacy_metadata_remain_outer_pending(self):
        value = self.collect(FakeGitlab())
        self.assertEqual(6, len(value['database_receipts']))
        self.assertEqual(12, len(value['upstream_jobs']))
        self.assertFalse(value['reuse_enabled'])
        self.assertIn('pending', value['outer_acceptance'])
        self.assertTrue(all('authenticated exact-job' in a['digest_origin'] for a in value['artifacts']))
        self.assertEqual(5, sum(r['results']['executed_cases'] for r in value['database_receipts'] if r['engine'] == 'mysql'))

    def test_wrong_pipeline_project_commit_source_ref_or_own_job_rejects(self):
        for field, bad in [('id',124), ('project_id',1), ('sha','c'*40), ('source','schedule'), ('ref','main'), ('status','success'), ('created_at',at(10))]:
            api = FakeGitlab(); api.pipeline[field] = bad
            with self.subTest(field=field), self.assertRaises(proof.ReceiptError): self.collect(api)
        for field, bad in [('id',99), ('name','foreign'), ('status','success'), ('runner',{'id':99}), ('ref','main')]:
            api = FakeGitlab(); api.rows[-1][field] = bad
            with self.subTest(own_field=field), self.assertRaises(proof.ReceiptError): self.collect(api)

    def test_missing_duplicate_optional_erased_or_non_success_upstream_rejects(self):
        for mutation in ('missing','duplicate_name','duplicate_id','optional','erased','running','failed','canceled','skipped','manual','foreign_pipeline','foreign_commit','foreign_ref','future','after_aggregate','before_pipeline'):
            api = FakeGitlab(); row = api.rows[0]
            if mutation == 'missing': api.rows.pop(0)
            elif mutation == 'duplicate_name': api.rows[1]['name'] = row['name']
            elif mutation == 'duplicate_id': api.rows[1]['id'] = row['id']
            elif mutation == 'optional': row['allow_failure'] = True
            elif mutation == 'erased': row['erased_at'] = at(-1)
            elif mutation in ('running','failed','canceled','skipped','manual'): row['status'] = mutation
            elif mutation == 'foreign_pipeline': row['pipeline']['id'] = 124
            elif mutation == 'foreign_commit': row['commit']['id'] = 'c'*40
            elif mutation == 'foreign_ref': row['ref'] = 'main'
            elif mutation == 'future': row['finished_at'] = at(10)
            elif mutation == 'after_aggregate': row['finished_at'] = at(-5)
            elif mutation == 'before_pipeline': row['created_at'] = at(-80)
            with self.subTest(mutation=mutation), self.assertRaises(proof.ReceiptError): self.collect(api)

    def test_missing_ambiguous_wrong_format_size_name_expired_or_conflicting_archive_rejects(self):
        for mutation in ('missing','duplicate','format','name','zero','oversized','download_size','expired','legacy_conflict'):
            api = FakeGitlab(); row = api.rows[0]; metadata = row['artifacts'][0]
            if mutation == 'missing': row['artifacts'] = []
            elif mutation == 'duplicate': row['artifacts'].append(deepcopy(metadata))
            elif mutation == 'format': metadata['file_format'] = 'gzip'
            elif mutation == 'name': metadata['filename'] = 'prior.zip'
            elif mutation == 'zero': metadata['size'] = 0
            elif mutation == 'oversized': metadata['size'] = proof.MAX_ZIP + 1
            elif mutation == 'download_size': metadata['size'] += 1
            elif mutation == 'expired': row['artifacts_expire_at'] = at(-1)
            elif mutation == 'legacy_conflict': row['artifacts_file'] = {'filename': metadata['filename'], 'size': 1}
            with self.subTest(mutation=mutation), self.assertRaises(proof.ReceiptError): self.collect(api)
        api = FakeGitlab(); metadata = api.rows[0]['artifacts'][0]
        api.rows[0]['artifacts_file'] = {'filename':metadata['filename'],'size':metadata['size']}
        self.assertEqual(6,len(self.collect(api)['artifacts']))

    def test_receipt_wrong_source_job_runner_runtime_dependencies_time_or_start_rejects(self):
        for mutation in ('source','job','runner','image','mysql_version','mysql_schema','mysql_isolation','php','dependencies','runtime_hash','time','start'):
            api = FakeGitlab(); files = evidence('mysql',1,api.rows[0]); key = 'phpunit-ci-mysql-1-receipt.json'; value = proof.json_data(files[key])
            if mutation == 'source': value['source']['pipeline_id'] = 124
            elif mutation == 'job': value['producer']['job_id'] = 100
            elif mutation == 'runner': value['producer']['runner_id'] = 100
            elif mutation == 'image': value['producer']['job_image_reference'] = 'php:latest'
            elif mutation == 'mysql_version': value['runtime']['database']['version'] = '8.0.42'
            elif mutation == 'mysql_schema': value['runtime']['database']['performance_schema'] = 0
            elif mutation == 'mysql_isolation': value['runtime']['database']['isolation'] = 'READ-COMMITTED'
            elif mutation == 'php': value['runtime']['php']['version'] = '8.3.1'
            elif mutation == 'dependencies': value['runtime']['dependencies']['composer_lock_sha256'] = 'b'*64
            elif mutation == 'runtime_hash': value['runtime_sha256'] = 'b'*64
            elif mutation == 'time': value['finished_at'] = at(-5)
            elif mutation == 'start':
                start = proof.json_data(files['phpunit-ci-mysql-1-start.json']); start['producer']['job_id'] = 99
                files['phpunit-ci-mysql-1-start.json'] = proof.canonical(start)
            files[key] = proof.canonical(value); api.replace(1,files)
            with self.subTest(mutation=mutation), self.assertRaises(proof.ReceiptError): self.collect(api)

    def test_complete_junit_census_and_no_mysql_skips_enforced_even_with_rehashed_outer_archive(self):
        for mutation in ('missing','duplicate','failure','mysql_skip','unknown'):
            api = FakeGitlab(); files = evidence('mysql',1,api.rows[0]); tree = ET.fromstring(files['phpunit-ci-mysql-1-results.xml'])
            case = tree[0][0]
            if mutation == 'missing': tree[0].remove(case)
            elif mutation == 'duplicate': tree[0].append(deepcopy(case))
            elif mutation == 'failure': ET.SubElement(case,'failure')
            elif mutation == 'mysql_skip': ET.SubElement(case,'skipped')
            elif mutation == 'unknown': case.set('name','test_unselected')
            files['phpunit-ci-mysql-1-results.xml'] = ET.tostring(tree); api.replace(1,files)
            with self.subTest(mutation=mutation), self.assertRaises(proof.ReceiptError): self.collect(api)

    def test_inconsistent_partition_manifest_rejects(self):
        api = FakeGitlab(); api.replace(1,evidence('mysql',1,api.rows[0],alternate=True))
        with self.assertRaises(proof.ReceiptError): self.collect(api)

    def test_latest_native_attempt_change_during_collection_rejects(self):
        api = FakeGitlab(); original = api.jobs
        def changing(pipeline):
            result = original(pipeline)
            if api.calls == 2: result[0]['id'] = 99
            return result
        api.jobs = changing
        with self.assertRaises(proof.ReceiptError): self.collect(api)

    def test_producer_metadata_change_during_collection_rejects(self):
        api = FakeGitlab(); original = api.jobs
        def changing(pipeline):
            result = original(pipeline)
            if api.calls == 2: result[0]['runner']['id'] = 99
            return result
        api.jobs = changing
        with self.assertRaises(proof.ReceiptError): self.collect(api)


class NativeSourceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        def git(*args):
            return subprocess.run(['git', *args], cwd=self.root, check=True, capture_output=True).stdout.decode().strip()
        self.git = git
        git('init', '--quiet')
        git('config', 'user.email', 'synthetic@example.test')
        git('config', 'user.name', 'Synthetic Receipt Test')
        for name in native.POLICY:
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('synthetic policy\n')
        git('add', '.')
        git('commit', '--quiet', '-m', 'Synthetic source fixture')
        self.environment = env() | {'CI_COMMIT_SHA':git('rev-parse','HEAD')}

    def test_current_clean_native_checkout_and_all_policy_bytes_are_bound(self):
        value=native.source(self.root,self.environment)
        self.assertEqual(self.git('rev-parse','HEAD^{tree}'),value['tree'])
        self.assertEqual('refs/merge-requests/1/head',value['ref'])
        self.assertEqual(set(native.POLICY),set(value['policy_sha256']))
        self.assertTrue(all(v==proof.digest(b'synthetic policy\n') for v in value['policy_sha256'].values()))

    def test_wrong_project_provider_event_config_mr_source_or_sha_rejects(self):
        mutations={'GITLAB_CI':'false','CI_PROJECT_ID':'1','CI_PROJECT_PATH':'other/project',
                   'CI_API_V4_URL':'https://evil.test/api/v4','CI_PIPELINE_SOURCE':'schedule',
                   'CI_CONFIG_PATH':'other.yml','CI_MERGE_REQUEST_PROJECT_ID':'1',
                   'CI_MERGE_REQUEST_EVENT_TYPE':'merged_result','CI_MERGE_REQUEST_IID':'',
                   'CI_COMMIT_SHA':'c'*40,'CI_PIPELINE_ID':'0'}
        for key,value in mutations.items():
            with self.subTest(key=key),self.assertRaises(proof.ReceiptError):
                native.source(self.root,self.environment|{key:value})

    def test_dirty_tracked_or_untracked_source_rejects(self):
        policy=self.root/native.POLICY[0]; original=policy.read_bytes(); policy.write_text('changed')
        with self.assertRaises(proof.ReceiptError):native.source(self.root,self.environment)
        policy.write_bytes(original)
        (self.root/'untracked.php').write_text('synthetic')
        with self.assertRaises(proof.ReceiptError):native.source(self.root,self.environment)

    def test_missing_tracked_policy_cannot_be_replaced_by_an_untracked_file(self):
        name=native.POLICY[0]
        self.git('rm',name); self.git('commit','--quiet','-m','Remove policy')
        self.environment['CI_COMMIT_SHA']=self.git('rev-parse','HEAD')
        with self.assertRaises(proof.ReceiptError):native.source(self.root,self.environment)

    def test_main_push_only_and_manual_native_ref_binding(self):
        push=self.environment|{'CI_PIPELINE_SOURCE':'push','CI_COMMIT_BRANCH':'main','CI_DEFAULT_BRANCH':'main','CI_COMMIT_REF_NAME':'main'}
        self.assertEqual('main',native.source(self.root,push)['ref'])
        with self.assertRaises(proof.ReceiptError):native.source(self.root,push|{'CI_COMMIT_BRANCH':'feature'})
        manual=self.environment|{'CI_PIPELINE_SOURCE':'web','CI_COMMIT_REF_NAME':'synthetic-feature'}
        self.assertEqual('synthetic-feature',native.source(self.root,manual)['ref'])


class NativeApiTests(unittest.TestCase):
    def test_preflight_provenance_permission_denial_fails_closed(self):
        api = native.Gitlab('synthetic-job-token')
        with patch.object(api,'request',return_value=(403,{},b'{}')), self.assertRaisesRegex(proof.ReceiptError,'READ_JOBS/READ_PIPELINES'):
            api.get('/projects/87181037/pipelines/123')

    def test_auth_failure_diagnostic_identifies_fixed_endpoint_status_without_secrets(self):
        api=native.Gitlab('synthetic-secret-token')
        with patch.object(api,'request',return_value=(401,{},b'{"secret":"body-value"}')):
            with self.assertRaises(proof.ReceiptError) as caught:
                api.get('/job')
        text=str(caught.exception)
        self.assertIn('GET /job HTTP 401',text)
        self.assertNotIn('synthetic-secret-token',text)
        self.assertNotIn('body-value',text)

    def test_jobs_pagination_rejects_duplicate_ids_wrong_next_or_truncated_total(self):
        for mutation in ('duplicate','next','total'):
            api = native.Gitlab('synthetic-job-token')
            rows = [{'id':1},{'id':1 if mutation == 'duplicate' else 2}]
            headers = {'x-next-page':'4'} if mutation == 'next' else {'x-total':'3'} if mutation == 'total' else {}
            with patch.object(api,'request',return_value=(200,headers,proof.canonical(rows))), self.subTest(mutation=mutation), self.assertRaises(proof.ReceiptError):
                api.jobs(123)

    def test_credentials_are_absent_from_signed_external_artifact_requests(self):
        class Response:
            status=200; headers={}
            def read(self,n): return b'zip'
            def __enter__(self): return self
            def __exit__(self,*a): pass
        api=native.Gitlab('synthetic-job-token')
        with patch.object(api.opener,'open',return_value=Response()) as opened:
            api.request('https://cdn.artifacts.gitlab-static.net/native.zip?signature=synthetic',external=True)
            self.assertEqual({},dict(opened.call_args.args[0].header_items()))

    def test_unapproved_origin_or_api_path_rejects_before_network(self):
        api=native.Gitlab('synthetic-job-token')
        for path in ('http://cdn.artifacts.gitlab-static.net/a','https://user:secret@cdn.artifacts.gitlab-static.net/a',
                     'https://cdn.artifacts.gitlab-static.net.evil.test/a','https://localhost/a','https://bucket.amazonaws.com/a'):
            with self.subTest(path=path),self.assertRaises(proof.ReceiptError): api.request(path,external=True)
        for path in ('/projects/1/pipelines/123','/projects/87181037/jobs/1/trace','/projects/87181037/jobs/artifacts/main/download'):
            with self.subTest(path=path),self.assertRaises(proof.ReceiptError): api.request(path)

    def test_naive_malformed_and_missing_native_times_reject(self):
        for value in (None, '2026-10-02T21:00:00', 'not-a-time', 123):
            with self.subTest(value=value), self.assertRaises(proof.ReceiptError):
                native.timestamp(value)

    def test_native_runtime_rejects_missing_fileinfo_nonstrict_mysql_or_fake_image_digest(self):
        for mutation in ('extension', 'strict', 'charset', 'service_digest', 'shape', 'memory_default', 'memory_unbounded'):
            value=runtime('mysql')
            if mutation=='extension':value['php']['extensions'].remove('fileinfo')
            elif mutation=='strict':value['database']['sql_mode']='NO_ENGINE_SUBSTITUTION'
            elif mutation=='charset':value['database']['character_set_server']='latin1'
            elif mutation=='service_digest':value['database']['service_image_digest']='sha256:'+H
            elif mutation=='shape':del value['database']['innodb_strict_mode']
            elif mutation=='memory_default':value['php']['memory_limit']='128M'
            elif mutation=='memory_unbounded':value['php']['memory_limit']='-1'
            with self.subTest(mutation=mutation), self.assertRaises(proof.ReceiptError):
                native.validate_runtime(value,'mysql')

    def test_finish_failure_cannot_create_a_receipt(self):
        with self.assertRaisesRegex(proof.ReceiptError,'PHPUnit did not finish successfully'):
            native.finish(ROOT,'mysql',1,{'DATABASE_TEST_OUTCOME':'failure'})


if __name__ == '__main__':
    unittest.main()
