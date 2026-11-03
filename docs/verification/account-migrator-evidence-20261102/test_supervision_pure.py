"""Syscall-free controls: importing this module/reducer does not import the launcher."""
import unittest
from supervision import METHOD, Supervision


class Controls(unittest.TestCase):
    def fresh(self):
        return Supervision(0, 'up-overlap', 'n')

    def owned(self, m, token='a', pid=40, handle='pidfd-old-birth'):
        m.reserve(token, pid, 'fresh-challenge', 1)
        m.bind(token, handle)
        m.commit(token, 'fresh-challenge', 2)

    def audit(self, **overrides):
        value = dict(final=True, lease_returnable=True, cleanup_verified=True, actual_php_pid=10,
                     selected_case='up-overlap', supervisor_nonce='n', first_failure=None, cleanup_failures=[], registration_unknown=False,
                     children=[dict(token='a', pid=40, closed=True, reaped=True, supervised=True, terminal_sent=True, uncertain=False)])
        value.update(overrides)
        return value

    def junit(self, case='up-overlap', count=1, child=''):
        testcase = '<testcase name=\'' + METHOD + ' with data set "' + case + '"\' classname="Tests.Feature.ProductionIdentity.AccountAdmissionMigratorTest">' + child + '</testcase>'
        return ('<testsuites><testsuite tests="' + str(count) + '" failures="0" errors="0" skipped="0">' + testcase * count + '</testsuite></testsuites>').encode()

    def clean(self):
        m = self.fresh(); self.owned(m); m.terminal('a', 9); m.complete(); m.root_exit(0, 5)
        return m

    def test_positive_bound_pidfd_and_complete_result(self):
        m = self.clean()
        self.assertEqual((True, True), m.accept(self.junit(), self.audit(), 10))
        self.assertEqual([], m.targets())

    def test_pid_reused_before_challenge_no_commit_no_signal(self):
        m = self.fresh(); m.reserve('a', 40, 'new', 1); m.bind('a', 'possibly-unrelated-pidfd')
        self.assertEqual([], m.targets())
        m.root_exit(125, 2)
        self.assertFalse(m.os_clean())
        self.assertEqual([], m.targets())

    def test_live_commit_authority_is_handle_not_reacquired_pid(self):
        m = self.fresh(); self.owned(m)
        m.tokens['a']['pid'] = 999  # diagnostic numeric ID cannot alter signal authority
        self.assertEqual(['pidfd-old-birth'], m.targets())
        m.terminal('a', 9)
        self.assertEqual([], m.targets())
        with self.assertRaises(ValueError): m.reserve('a', 40, 'new', 3)

    def test_wrong_replayed_or_missing_challenge_never_authorizes(self):
        for challenge in ('old', None, ''):
            m = self.fresh(); m.reserve('a', 40, 'fresh', 1); m.bind('a', 'q')
            with self.assertRaises(ValueError): m.commit('a', challenge, 2)
            self.assertEqual([], m.targets())
        m = self.fresh(); self.owned(m)
        with self.assertRaises(ValueError): m.commit('a', 'fresh-challenge', 3)

    def test_root_death_inflight_is_unknown_not_adoption(self):
        m = self.fresh(); m.reserve('a', 40, 'x', 1); m.bind('a', 'quarantine')
        m.root_exit(125, 2)
        with self.assertRaises(ValueError): m.commit('a', 'x', 3)
        self.assertEqual([], m.targets())
        self.assertFalse(m.accept(self.junit(), self.audit(), 10)[1])

    def test_terminal_replay_and_second_reaper_refused(self):
        m = self.clean()
        with self.assertRaises(ValueError): m.terminal('a', 0)
        with self.assertRaises(ValueError): m.root_exit(0, 6)
        with self.assertRaises(ValueError): m.reserve('new', 40, 'x', 6)

    def test_expiry_wins_simultaneous_or_eventual_exit_zero(self):
        for end in (480, 481, 500):
            m = self.fresh(); m.complete(); m.root_exit(0, end)
            self.assertTrue(m.expired)
            self.assertFalse(m.accept(self.junit(), self.audit(), 10)[0])

    def test_one_absolute_cleanup_window_across_all_failures(self):
        m = self.fresh(); self.owned(m)
        m.fail('drain', 4); m.fail('setup', 8); m.tick(480)
        self.assertEqual(24, m.cleanup_end)
        self.assertEqual('drain', m.failure)
        self.assertEqual(['pidfd-old-birth'], m.targets())  # cleanup callback still has all proven handles
        self.assertTrue(m.tick(500))

    def test_stalled_reporter_has_no_ownership_or_clock_extension(self):
        m = self.clean(); end = m.cleanup_end
        self.assertTrue(m.tick(end))
        self.assertEqual(end, m.cleanup_end)
        self.assertEqual([], m.targets())

    def test_unknown_registration_cannot_complete(self):
        m = self.fresh(); m.reserve('a', 40, 'x', 1)
        with self.assertRaises(ValueError): m.complete()
        m.fail('unknown', 2); m.root_exit(0, 3)
        self.assertFalse(m.accept(self.junit(), self.audit(), 10)[1])

    def test_missing_mismatched_or_unavailable_native_audit(self):
        m = self.clean()
        for field, value in [('final', False), ('lease_returnable', False), ('cleanup_verified', False),
                             ('actual_php_pid', 11), ('selected_case', 'down-end'), ('supervisor_nonce', 'wrong'),
                             ('cleanup_failures', [{'verified': False}]), ('registration_unknown', True), ('children', [])]:
            self.assertEqual((False, False), m.accept(self.junit(), self.audit(**{field: value}), 10))

    def test_zero_multiple_wrong_partial_or_failed_junit_not_success(self):
        m = self.clean()
        for xml in (self.junit(count=0), self.junit(count=2), self.junit(case='down-end'), b'<broken',
                    self.junit(child='<failure/>'), self.junit(child='<skipped/>'),
                    b'<!DOCTYPE x><testsuites/>'):
            self.assertEqual((False, True), m.accept(xml, self.audit(), 10))

    def test_failed_case_can_separately_verify_cleanup(self):
        m = self.fresh(); self.owned(m); m.terminal('a', 9); m.complete(); m.root_exit(1, 5)
        self.assertEqual((False, True), m.accept(self.junit(child='<failure/>'), self.audit(first_failure={'class': 'Assertion'}), 10))

    def test_registration_deadline_and_duplicate_live_pid(self):
        m = self.fresh(); m.reserve('a', 40, 'x', 1); m.bind('a', 'quarantine')
        m.tick(3)
        self.assertEqual('registration-expired', m.failure)
        self.assertEqual(23, m.cleanup_end)
        self.assertEqual([], m.targets())
        with self.assertRaises(ValueError): m.complete()
        n = self.fresh(); self.owned(n)
        with self.assertRaises(ValueError): n.reserve('b', 40, 'x', 2)

    def test_stop_or_continued_status_is_not_terminal_reap(self):
        for status in ((19 << 8) | 127, 65535, -1, True):
            m = self.fresh(); self.owned(m)
            with self.assertRaises(ValueError): m.terminal('a', status)
            self.assertEqual(['pidfd-old-birth'], m.targets())

    def test_actual_reporter_completion_boundary_expiry_wins(self):
        # Calls the same production decision method as run-one AFTER its read/wait.
        for completed_at in (25, 25.01, 40):
            m = self.clean()  # root completion at5 => cleanup_end25
            self.assertFalse(m.tick(24.99))  # loop began before the deadline
            injected_clock = lambda: completed_at
            self.assertEqual(125, m.reporter_completion(b'P', 0, injected_clock))
            self.assertTrue(m.cleanup_expired)
            self.assertEqual(0, m.reporter_observation['status'])
            self.assertTrue(m.reporter_observation['success_frame'])
            self.assertEqual(25, m.cleanup_end)  # no second cleanup window
            self.assertFalse(m.accept(self.junit(), self.audit(), 10)[0])
            # Even backwards time/another success cannot clear observed expiry.
            self.assertEqual(125, m.reporter_completion(b'P', 0, lambda: 24))
        m = self.clean()
        self.assertEqual(0, m.reporter_completion(b'P', 0, lambda: 24.99))
        self.assertFalse(m.cleanup_expired)
        for frame, status in ((b'F', 0), (b'P', 1), (b'', 0), (b'PP', 0)):
            self.assertEqual(1, self.clean().reporter_completion(frame, status, lambda: 24))

    def test_explicit_fake_action_callbacks_never_get_raw_pid(self):
        calls = []
        m = self.fresh(); self.owned(m, handle=('pidfd', 'birth-A'))
        m.fail('IPC', 3)
        fake_kill = lambda handle: calls.append(handle)
        for handle in m.targets(): fake_kill(handle)
        m.terminal('a', 9)
        for handle in m.targets(): fake_kill(handle)
        self.assertEqual([('pidfd', 'birth-A')], calls)


if __name__ == '__main__':
    unittest.main()
