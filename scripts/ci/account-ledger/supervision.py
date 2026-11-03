"""Pure lifecycle/acceptance reducer. No OS, process, signal, clock or filesystem calls."""
import json
import xml.etree.ElementTree as ET

METHOD = 'test_normal_migrator_process_interruption_and_genuine_ledger_resume'
CASES = ('up-overlap', 'down-overlap', 'up-end', 'down-end')


class Supervision:
    def __init__(self, now, case, nonce):
        if case not in CASES:
            raise ValueError('selection')
        self.case, self.nonce = case, nonce
        self.work_end = now + 480
        self.cleanup_end = None
        self.expired = False
        self.cleanup_expired = False
        self.reporter_observation = None
        self.failure = None
        self.tokens = {}
        self.root_status = None
        self.root_terminal = False
        self.completed = False

    def fail(self, reason, now):
        if self.failure is None:
            self.failure = reason
        self.begin_cleanup(now)

    def begin_cleanup(self, now):
        if self.cleanup_end is None:
            self.cleanup_end = now + 20

    def tick(self, now):
        if self.cleanup_end is None and any(e['phase'] == 'quarantine' and now >= e['reservation_end'] for e in self.tokens.values()):
            self.fail('registration-expired', now)
        if now >= self.work_end and not self.root_terminal:
            self.expired = True
            self.fail('work-expired', now)
        if self.cleanup_end is not None and now >= self.cleanup_end:
            self.cleanup_expired = True
        return self.cleanup_expired

    def reserve(self, token, pid, challenge, now):
        self.tick(now)
        if self.cleanup_end is not None or self.root_terminal or token in self.tokens or not isinstance(pid, int) or isinstance(pid, bool) or pid <= 1 or any(e['pid'] == pid and e['phase'] != 'terminal' for e in self.tokens.values()):
            self.fail('registration-refused', now)
            raise ValueError('registration-refused')
        self.tokens[token] = {'pid': pid, 'challenge': challenge, 'phase': 'quarantine', 'handle': None, 'status': None, 'reservation_end': now + 2}

    def bind(self, token, handle):
        entry = self.tokens[token]
        if entry['phase'] != 'quarantine' or entry['handle'] is not None:
            raise ValueError('bind')
        entry['handle'] = handle  # Opaque pidfd, not a numeric PID signal target.

    def commit(self, token, challenge, now):
        self.tick(now)
        entry = self.tokens[token]
        if self.cleanup_end is not None or self.root_terminal or entry['phase'] != 'quarantine' or entry['handle'] is None or entry['challenge'] != challenge:
            self.fail('commit-refused', now)
            raise ValueError('commit-refused')
        entry['phase'] = 'owned'

    def terminal(self, token, status):
        entry = self.tokens[token]
        if entry['phase'] != 'owned' or not isinstance(status, int) or isinstance(status, bool) or status < 0 or status & 127 == 127:
            raise ValueError('terminal-refused')
        entry['phase'], entry['status'] = 'terminal', status

    def complete(self):
        if self.cleanup_end is not None or self.completed or any(e['phase'] != 'terminal' for e in self.tokens.values()):
            raise ValueError('incomplete-children')
        self.completed = True

    def root_exit(self, status, now):
        self.tick(now)  # expiry wins a simultaneous exit0.
        if self.root_terminal:
            raise ValueError('second-reaper')
        self.root_terminal, self.root_status = True, status
        self.begin_cleanup(now)
        if status != 0 or not self.completed or any(e['phase'] != 'terminal' for e in self.tokens.values()):
            self.fail('root-or-children-unverified', now)

    def targets(self):
        return [e['handle'] for e in self.tokens.values() if e['phase'] == 'owned']

    def os_clean(self):
        return self.root_terminal and self.completed and all(e['phase'] == 'terminal' for e in self.tokens.values())

    def accept(self, junit, audit, root_pid):
        lease = self.os_clean() and bool(audit.get('final') is True and audit.get('lease_returnable') is True
            and audit.get('cleanup_verified') is True and audit.get('actual_php_pid') == root_pid
            and audit.get('selected_case') == self.case and audit.get('supervisor_nonce') == self.nonce
            and audit.get('cleanup_failures') == [] and audit.get('registration_unknown') is False
            and self.audit_children(audit.get('children')))
        # Parse cap enforced by reporter before reducer; reject entities/DTD too.
        if b'<!DOCTYPE' in junit or b'<!ENTITY' in junit:
            return False, lease
        try:
            tree = ET.fromstring(junit)
            cases = list(tree.iter('testcase'))
            expected = METHOD + ' with data set "' + self.case + '"'
            valid = len(cases) == 1 and cases[0].get('name') == expected and cases[0].get('classname') == 'Tests.Supervised.ProductionIdentity.AccountAdmissionMigratorTest'
            valid = valid and len(list(cases[0])) == 0
            suites = list(tree.iter('testsuite'))
            valid = valid and bool(suites) and all(s.get('tests') == '1' and all(s.get(k, '0') == '0' for k in ('failures', 'errors', 'skipped')) for s in suites)
        except (ET.ParseError, ValueError):
            valid = False
        return bool(lease and valid and not self.expired and not self.cleanup_expired and self.failure is None and self.root_status == 0), lease

    def audit_children(self, children):
        if not isinstance(children, list) or len(children) != len(self.tokens):
            return False
        seen = set()
        for child in children:
            if not isinstance(child, dict) or child.get('token') in seen:
                return False
            token = child.get('token')
            entry = self.tokens.get(token)
            if entry is None or child.get('pid') != entry['pid'] or any(child.get(k) is not True for k in ('closed', 'reaped', 'supervised', 'terminal_sent')) or child.get('uncertain') is not False:
                return False
            seen.add(token)
        return seen == set(self.tokens)

    def reporter_completion(self, result, status, clock):
        # Actual adapter calls ONLY after final read + real reporter wait/reap.
        # Recheck at decision, not merely iteration start; expiry is irreversible.
        observed_at = clock()
        self.tick(observed_at)
        self.reporter_observation = {'at': observed_at, 'status': status, 'success_frame': result == b'P'}
        if self.cleanup_expired:
            return 125
        return 0 if result == b'P' and status == 0 and self.failure is None and not self.expired and self.root_status == 0 and self.os_clean() else 1

    def safe(self):
        return {'case': self.case, 'nonce': self.nonce, 'work_end': self.work_end, 'cleanup_end': self.cleanup_end,
                'completed': self.completed, 'cleanup_expired': self.cleanup_expired, 'reporter_observation': self.reporter_observation, 'expired': self.expired, 'first_failure': self.failure, 'root_status': self.root_status,
                'root_terminal': self.root_terminal,
                'tokens': {k: {x: v[x] for x in ('pid', 'phase', 'status')} for k, v in self.tokens.items()}}
