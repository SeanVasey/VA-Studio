"""Pure host watchdog tests: fake clocks/processes only, no Docker/native operations."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest

spec = importlib.util.spec_from_file_location('rr_launcher', Path(__file__).with_name('run.py'))
launcher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(launcher)


class Clock:
    def __init__(self):
        self.now = 0.0

    def __call__(self):
        return self.now


class Stream:
    def fileno(self):
        return 13

    def read(self, *args):
        raise AssertionError('A stream held open must never trigger a blocking read')


class Process:
    def __init__(self, code=None):
        self.stdout = Stream()
        self.code = code

    def poll(self):
        return self.code

    def kill(self):
        raise AssertionError('Never kill an unknown PID/Docker client')

    terminate = kill


class Selector:
    def __init__(self, clock, reads=0):
        self.clock = clock
        self.reads = reads
        self.closed = False

    def register(self, *args):
        pass

    def select(self, timeout):
        self.clock.now += timeout
        if self.reads:
            self.reads -= 1
            return [(None, None)]
        return []

    def close(self):
        self.closed = True


class LauncherBoundsTest(unittest.TestCase):
    def supervisor(self, directory, *, process=None, reads=0, data=b'', abort=None):
        clock = Clock()
        selector = Selector(clock, reads)
        calls = []

        def execute(args, **options):
            calls.append((args, options))
            if abort:
                return abort(clock, args, options)
            return SimpleNamespace(returncode=0, stdout='{"event":"owned_abort","results":[]}\n', stderr='')

        result = launcher.supervise(process or Process(), directory, directory / 'manifest.json',
                                     clock=clock, selector=selector, run_fn=execute,
                                     read_fn=lambda *args: data)
        self.assertTrue(selector.closed)
        return result, calls

    def test_open_stream_no_ready_abort_kills_none_stops_at_hard_bound(self):
        with tempfile.TemporaryDirectory() as name:
            directory = Path(name)
            (directory / 'manifest.json').write_text('{"retained":true}')
            result, calls = self.supervisor(directory)
            self.assertTrue(result['hard_deadline_reached'])
            self.assertFalse(result['termination_proven'])
            self.assertLessEqual(result['elapsed_seconds'], launcher.HARD_AFTER + 0.21)
            self.assertEqual(1, len(calls))
            self.assertIn('--abort', calls[0][0])
            self.assertTrue((directory / 'cancel').exists())
            final = launcher.final_result(result, audit=True)
            self.assertEqual('STOP_CLEANUP_UNVERIFIED', final['outcome'])
            self.assertFalse(final['cleanup_verified'])
            self.assertFalse(final['lease_returnable'])
            self.assertEqual('{"retained":true}', (directory / 'manifest.json').read_text())

    def test_exited_docker_with_open_inherited_stream_still_has_hard_bound(self):
        with tempfile.TemporaryDirectory() as name:
            result, calls = self.supervisor(Path(name), process=Process(0))
            self.assertEqual(0, result['driver_exit'])
            self.assertFalse(result['stream_eof'])
            self.assertTrue(result['hard_deadline_reached'])
            self.assertEqual('STOP_CLEANUP_UNVERIFIED', launcher.final_result(result, True)['outcome'])

    def test_partial_stream_and_existing_partial_receipt_survive_hard_stop(self):
        with tempfile.TemporaryDirectory() as name:
            directory = Path(name)
            original = '{"last_event":"before-stop"}'
            (directory / 'partial.json').write_text(original)
            data = b'{"event":"started"}\n{"unfinished"'
            result, _ = self.supervisor(directory, reads=1, data=data)
            self.assertEqual(data, (directory / 'ordered-stream.raw').read_bytes())
            self.assertIn('{"unfinished"', result['transport_errors'])
            self.assertEqual(original, (directory / 'partial.json').read_text())
            self.assertTrue((directory / 'supervisor-result.json').exists())

    def test_hung_abort_preserves_partial_output_and_does_not_extend_final_bound(self):
        def hang(clock, args, options):
            clock.now += options['timeout']
            raise subprocess.TimeoutExpired(args, options['timeout'], output=b'partial-abort', stderr=b'partial-error')

        with tempfile.TemporaryDirectory() as name:
            directory = Path(name)
            result, calls = self.supervisor(directory, abort=hang)
            self.assertEqual(1, len(calls))
            self.assertEqual(b'partial-abort', (directory / 'abort-transport.stdout').read_bytes())
            self.assertEqual(b'partial-error', (directory / 'abort-transport.stderr').read_bytes())
            self.assertEqual('TIMEOUT', json.loads((directory / 'abort-transport.status.json').read_text())['status'])
            self.assertLessEqual(result['elapsed_seconds'], launcher.HARD_AFTER + 0.21)
            self.assertEqual('STOP_CLEANUP_UNVERIFIED', launcher.final_result(result, True)['outcome'])

    def test_hung_preflight_command_is_bounded_and_retains_stdout_stderr(self):
        def hang(args, **options):
            self.assertEqual(launcher.PREFLIGHT_TIMEOUT, options['timeout'])
            raise subprocess.TimeoutExpired(args, options['timeout'], output=b'partial-git', stderr=b'partial-stderr')

        with tempfile.TemporaryDirectory() as name:
            directory = Path(name)
            with self.assertRaises(launcher.CommandTimeout):
                launcher.command(['fake-git'], directory, 'preflight-test', run_fn=hang)
            self.assertEqual(b'partial-git', (directory / 'preflight-test.stdout').read_bytes())
            self.assertEqual(b'partial-stderr', (directory / 'preflight-test.stderr').read_bytes())
            status = json.loads((directory / 'preflight-test.status.json').read_text())
            self.assertFalse(status['inner_process_cleanup_proven'])

    def test_timeout_retains_non_utf8_partial_bytes_exactly(self):
        def hang(args, **options):
            raise subprocess.TimeoutExpired(args, options['timeout'], output=b'\xffpartial', stderr=b'\xfestderr')

        with tempfile.TemporaryDirectory() as name:
            directory = Path(name)
            with self.assertRaises(launcher.CommandTimeout):
                launcher.command(['fake'], directory, 'raw-timeout', run_fn=hang)
            self.assertEqual(b'\xffpartial', (directory / 'raw-timeout.stdout').read_bytes())
            self.assertEqual(b'\xfestderr', (directory / 'raw-timeout.stderr').read_bytes())

    def test_hung_server_audit_cannot_turn_clean_driver_into_cleanup_pass(self):
        def hang(args, **options):
            self.assertEqual(launcher.AUDIT_TIMEOUT, options['timeout'])
            raise subprocess.TimeoutExpired(args, options['timeout'], output=b'clients\t0\n', stderr=b'audit-stalled')

        with tempfile.TemporaryDirectory() as name:
            directory = Path(name)
            identity = {'role': 'share', 'connection_id': 19, 'thread_id': 29}
            (directory / 'worker-share-ready.json').write_text(json.dumps(identity))
            (directory / 'worker-share-spawn.json').write_text('{"role":"share","pid":123}')
            audit = launcher.cleanup_audit(directory, run_fn=hang)
            self.assertFalse(audit)
            status = json.loads((directory / 'cleanup-audit.json').read_text())
            self.assertEqual('STOP_CLEANUP_UNVERIFIED', status['status'])
            self.assertEqual(b'clients\t0\n', (directory / 'cleanup-audit.stdout').read_bytes())
            self.assertEqual(b'audit-stalled', (directory / 'cleanup-audit.stderr').read_bytes())
            supervision = {'termination_proven': True, 'driver_exit': 0, 'transport_errors': [], 'watchdog_cancelled': False}
            final = launcher.final_result(supervision, audit)
            self.assertEqual('STOP_CLEANUP_UNVERIFIED', final['outcome'])
            self.assertFalse(final['lease_returnable'])

    def test_no_ready_identity_refuses_audit_without_native_command(self):
        with tempfile.TemporaryDirectory() as name:
            def forbidden(*args, **kwargs):
                raise AssertionError('No owned identity must issue no audit SQL')
            self.assertFalse(launcher.cleanup_audit(Path(name), run_fn=forbidden))

    def test_normal_eof_allows_only_a_short_bounded_exit_race_wait(self):
        class ExitRace(Process):
            def wait(inner, timeout):
                self.assertGreater(timeout, 0)
                self.assertLessEqual(timeout, 1)
                inner.code = 0
                return 0

        with tempfile.TemporaryDirectory() as name:
            result, calls = self.supervisor(Path(name), process=ExitRace(), reads=1, data=b'')
            self.assertFalse(calls)
            self.assertTrue(result['termination_proven'])
            self.assertFalse(result['hard_deadline_reached'])

    def test_normal_eof_does_not_waive_server_audit(self):
        with tempfile.TemporaryDirectory() as name:
            result, calls = self.supervisor(Path(name), process=Process(0), reads=1, data=b'')
            self.assertFalse(calls)
            self.assertTrue(result['termination_proven'])
            self.assertEqual('STOP_CLEANUP_UNVERIFIED', launcher.final_result(result, False)['outcome'])
            self.assertEqual('COMPLETED_MECHANISM_ONLY', launcher.final_result(result, True)['outcome'])


if __name__ == '__main__':
    unittest.main()
