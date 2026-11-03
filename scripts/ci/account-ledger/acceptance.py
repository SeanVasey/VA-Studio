"""Accepted pure origin/lineage/JUnit controls, composed without native IO."""
import json
import re
import types
import xml.etree.ElementTree as ET
class Refusal(RuntimeError): pass
def require(ok, reason):
    if not ok: raise Refusal(reason)
def closed_object(pairs):
    value = {}
    for key, item in pairs:
        require(key not in value, 'duplicate-json')
        value[key] = item
    return value
base = types.SimpleNamespace(require=require, Refusal=Refusal)
accepted = types.SimpleNamespace(closed_object=closed_object)
def validate_metrics(stderr, selection):
    require(selection['result_kind'] == 'empty' and stderr == b'', 'nonempty-ledger-stderr')
    return {'complete': True, 'budget_met': True}

def validate_origins(raw, candidate_sha, selected):
    value = json.loads(raw, object_pairs_hook=accepted.closed_object)
    base.require(type(value) is dict and list(value) == ['candidate_sha', 'selected_case', 'prepared_events',
        'base_path', 'source_origins', 'source_includes', 'vendor_binding', 'private_storage', 'representatives'],
        'integrated_origin_shape')
    base.require(value['candidate_sha'] == candidate_sha and value['selected_case'] is True
        and type(value['prepared_events']) is int and value['prepared_events'] == 1
        and all(value[name] is True for name in ['base_path', 'source_origins', 'source_includes',
            'vendor_binding', 'private_storage', 'representatives']), 'integrated_origin_refused')
    return value

def validate_lineage(bound,final,report,case,sha):
    keys=['candidate_sha','case','pid','role','direction','source_origins','representatives','vendor_binding','application_constructed_claim']
    tokens=report['supervision']['tokens'];root_pid=report['root_pid']
    base.require(type(root_pid) is int and root_pid>1 and type(tokens) is dict,'ledger_pid_inventory')
    normal_count=2 if case.startswith('up-') else 1
    base.require(len(tokens)==normal_count+1 and all(type(v) is dict and type(v.get('pid')) is int and v['pid']>1
        and v.get('phase')=='terminal' and type(v.get('status')) is int and v['status'] in [0,9] for v in tokens.values()),'ledger_terminal_tokens')
    workers={v['pid'] for v in tokens.values() if v['status']==9};normals={v['pid'] for v in tokens.values() if v['status']==0}
    base.require(len(workers)==1 and len(normals)==normal_count and root_pid not in workers|normals,'ledger_original_process_roles')
    base.require(len(bound)==normal_count+2 and len(final)==normal_count,'ledger_origin_count')
    seen=set();directions={}
    for value in bound:
        base.require(type(value) is dict and list(value)==keys and value['candidate_sha']==sha and value['case']==case
            and type(value['pid']) is int and value['pid'] not in seen and value['application_constructed_claim'] is False
            and all(value[k] is True for k in ['source_origins','representatives','vendor_binding']),'ledger_bound_origin')
        seen.add(value['pid'])
        expected='parent' if value['pid']==root_pid else ('worker' if value['pid'] in workers else 'ordinary_artisan' if value['pid'] in normals else None)
        base.require(value['role']==expected,'ledger_bound_pid_role')
        if expected=='parent':base.require(value['direction'] is None,'ledger_parent_direction')
        elif expected=='worker':base.require(value['direction']==case.split('-')[0],'ledger_worker_direction')
        else:directions[value['pid']]=value['direction']
    base.require(seen=={root_pid}|workers|normals and [directions[v['pid']] for v in tokens.values() if v['status']==0]==(['down','up'] if normal_count==2 else ['down']),'ledger_bound_inventory')
    finals=set()
    for value in final:
        base.require(type(value) is dict and list(value)==keys+['application_origin'] and value['application_origin'] is True
            and value['pid'] in normals and value['pid'] not in finals and {k:value[k] for k in keys} in bound,'ledger_restart_application_origin')
        finals.add(value['pid'])
    base.require(finals==normals,'ledger_restart_lineage')
    return {'parent_source_bound':True,'killed_worker_source_bound_to_original_supervised_pid':True,
        'worker_actual_execution_proof':'ORIGINAL_READY_REFLECTION_STACK_ASSERTIONS',
        'ordinary_artisan_origins':normal_count,'all_registered_pids_accounted':True}

def evaluate_junit(xml, stdout, stderr, native_exit, selection):
    tree = ET.fromstring(xml)
    cases = list(tree.iter('testcase'))
    suites = list(tree.iter('testsuite'))
    assertions = cases[0].get('assertions', '') if len(cases) == 1 else ''
    selected = len(cases) == 1 and cases[0].get('name') == selection['method'] and cases[0].get('class') == selection['class']
    counts = {tag: len(list(tree.iter(tag))) for tag in ['failure', 'error', 'skipped', 'warning']}
    suite_shape = bool(suites) and all(s.get('tests') == '1' and s.get('failures') == '0'
        and s.get('errors') == '0' and s.get('skipped') == '0' and s.get('warnings', '0') == '0' for s in suites)
    positive_assertions = re.fullmatch('[1-9][0-9]*', assertions) is not None
    log = stdout.decode('utf-8', 'strict')
    clean_log = not any(marker in log for marker in ['WARNINGS!', 'PHPUnit Warnings', 'Warnings:', 'OK, but there were issues!'])
    summary = positive_assertions and re.search(r'^OK \(1 test, '+re.escape(assertions)+r' assertions\)$', log, re.MULTILINE) is not None
    try:
        metrics = validate_metrics(stderr, selection)
    except (base.Refusal, ValueError, UnicodeError):
        metrics = {'complete': False, 'key': selection['key'], 'bytes': len(stderr), 'budget_met': False}
    passed = native_exit == 0 and selected and positive_assertions and suite_shape and not any(counts.values()) and clean_log and summary and metrics['complete'] and metrics['budget_met']
    return {'native_exit': native_exit, 'testcase_count': len(cases), 'exact_selection': selected,
        'assertions': int(assertions) if positive_assertions else None, 'counts': counts,
        'product_stderr_present': stderr != b'', 'metrics': metrics, 'positive': passed}
