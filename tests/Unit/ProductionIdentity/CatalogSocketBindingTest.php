<?php

namespace Tests\Unit\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\CatalogSocketBinding;
use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CatalogSocketBindingTest extends TestCase
{
    private const HEADER = "  sl  local_address rem_address st tx_queue rx_queue tr tm->when retrnsmt uid timeout inode\n";

    private function row(string $local = '0200000A:C350', string $remote = '010200C0:0CEA', string $inode = '12345', string $state = '01'): string
    {
        return "0: $local $remote $state 00000000:00000000 00:00000000 00000000 1000 0 $inode 1 0000000000000000 100 0 0 10 -1\n";
    }

    private function writer(): array
    {
        return ['socket' => '', 'host' => '192.0.2.1', 'port' => 3306];
    }

    private function context(): array
    {
        return ['server_port' => 3306, 'client_endpoint' => '10.0.0.2:50000'];
    }

    private function proof(array $writer, array $context, array $owned, string $tcp, string $tcp6 = self::HEADER): array
    {
        return CatalogSocketBinding::prove($writer, $context, '192.0.2.1 via TCP/IP', $owned, $tcp, $tcp6);
    }

    public function test_literal_ipv4_and_ipv6_native_words_bind_the_unique_owned_primary_peer(): void
    {
        $little = pack('S', 1) === "\x01\0";
        $row4 = $little ? $this->row() : $this->row('0A000002:C350', 'C0000201:0CEA');
        $actual = $this->proof($this->writer(), $this->context(), ['12345'], self::HEADER.$row4);
        self::assertSame(['inode' => '12345', 'state' => '01', 'local_ip' => '10.0.0.2', 'local_port' => 50000, 'remote_ip' => '192.0.2.1', 'remote_port' => 3306], $actual);
        $row6 = $little ? $this->row('00000000000000000000000001000000:C350', 'B80D0120000000000000000001000000:0CEA')
            : $this->row('00000000000000000000000000000001:C350', '20010DB8000000000000000000000001:0CEA');
        $actual = CatalogSocketBinding::prove(['socket' => '', 'host' => '2001:db8:0:0:0:0:0:1', 'port' => 3306], ['server_port' => 3306, 'client_endpoint' => '[::1]:50000'], '2001:db8::1 via TCP/IP', ['12345'], self::HEADER, self::HEADER.$row6);
        self::assertSame('::1', $actual['local_ip']);
        self::assertSame('2001:db8::1', $actual['remote_ip']);
    }

    public function test_absent_ipv6_table_contributes_no_row_and_cannot_bind_an_ipv6_primary(): void
    {
        $little = pack('S', 1) === "\x01\0";
        $row4 = $little ? $this->row() : $this->row('0A000002:C350', 'C0000201:0CEA');
        $actual = $this->proof($this->writer(), $this->context(), ['12345'], self::HEADER.$row4, CatalogSocketBinding::ABSENT_IPV6_TABLE);
        self::assertSame('192.0.2.1', $actual['remote_ip']);
        $this->expectException(LogicException::class);
        CatalogSocketBinding::prove(['socket' => '', 'host' => '2001:db8:0:0:0:0:0:1', 'port' => 3306], ['server_port' => 3306, 'client_endpoint' => '[::1]:50000'], '2001:db8::1 via TCP/IP', ['12345'], self::HEADER, CatalogSocketBinding::ABSENT_IPV6_TABLE);
    }

    public function test_snapshot_substitutes_the_absent_table_only_on_a_kernel_without_ipv6(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux' || ! is_readable('/proc/self/net/tcp')) {
            self::markTestSkipped('Linux procfs host control.');
        }
        // Host-conditional: CI runners with IPv6 cover the read path, a host booted without IPv6 covers the substitution.
        $absent = ! file_exists('/proc/self/net/tcp6') && ! file_exists('/proc/self/net/if_inet6');
        [$inodes, $tcp, $tcp6] = (new ReflectionMethod(CatalogSocketBinding::class, 'snapshot'))->invoke(null);
        self::assertTrue(array_is_list($inodes));
        self::assertMatchesRegularExpression('/\A\s*sl\s+local_address\s/', $tcp);
        if ($absent) {
            self::assertSame(CatalogSocketBinding::ABSENT_IPV6_TABLE, $tcp6);
        } else {
            self::assertNotSame(CatalogSocketBinding::ABSENT_IPV6_TABLE, $tcp6);
            self::assertMatchesRegularExpression('/\A\s*sl\s+local_address\s/', $tcp6);
        }
    }

    public function test_target_matching_other_owned_socket_never_selects_it_instead_of_primary(): void
    {
        // Primary is forwarded33089; a DIFFERENT own socket matches requested3306. Filtering target first is unsafe.
        $tcp = self::HEADER.$this->row(remote: '010200C0:8141').$this->row(local: '0200000A:C351', inode: '23456');
        $this->expectException(LogicException::class);
        $this->proof($this->writer(), $this->context(), ['12345', '23456'], $tcp);
    }

    public function test_owned_socket_sharing_the_primary_source_port_with_another_peer_is_not_ambiguous(): void
    {
        // Linux connect() gives sockets with different peers the same ephemeral port (e.g. a loopback SMTP client).
        $little = pack('S', 1) === "\x01\0";
        $primary = $little ? $this->row() : $this->row('0A000002:C350', 'C0000201:0CEA');
        $other = $little ? $this->row(remote: '0100007F:0019', inode: '23456') : $this->row('0A000002:C350', '7F000001:0019', '23456');
        foreach ([$primary.$other, $other.$primary] as $rows) {
            $actual = $this->proof($this->writer(), $this->context(), ['12345', '23456'], self::HEADER.$rows);
            self::assertSame('12345', $actual['inode']);
            self::assertSame(3306, $actual['remote_port']);
        }
        // Without the primary's own 4-tuple the shared source port proves nothing.
        $this->expectException(LogicException::class);
        $this->proof($this->writer(), $this->context(), ['23456'], self::HEADER.$other);
    }

    public function test_a_torn_snapshot_is_retried_with_fresh_snapshots_and_never_more_than_three_times(): void
    {
        $retrying = new ReflectionMethod(CatalogSocketBinding::class, 'retrying');
        $args = [['writer'], ['context'], 'status'];
        $snapshots = 0;
        $snapshot = function () use (&$snapshots): array {
            $snapshots++;

            return [['12345'], 'tcp '.$snapshots, ''];
        };

        // Every attempt refuses: exactly three fresh snapshots, then the last refusal propagates.
        $seen = [];
        try {
            $retrying->invoke(null, $snapshot, function (array $writer, array $context, string $status, array $inodes, string $tcp) use (&$seen): array {
                $seen[] = $tcp;
                throw new LogicException('refusal '.$tcp);
            }, ...$args);
            self::fail('a proof that always refuses must not be accepted');
        } catch (LogicException $error) {
            self::assertSame('refusal tcp 3', $error->getMessage());
        }
        self::assertSame(['tcp 1', 'tcp 2', 'tcp 3'], $seen);
        self::assertSame(3, $snapshots);

        // A torn first snapshot recovers on the second, and the proof of that fresh snapshot is what is returned.
        $snapshots = 0;
        $result = $retrying->invoke(null, $snapshot, function (array $writer, array $context, string $status, array $inodes, string $tcp): array {
            if ($tcp === 'tcp 1') {
                throw new LogicException('torn');
            }

            return ['tcp' => $tcp];
        }, ...$args);
        self::assertSame(['tcp' => 'tcp 2'], $result);
        self::assertSame(2, $snapshots);

        // A snapshot failure (taint, provider) is never retried and never reaches the proof.
        $calls = 0;
        $proofs = 0;
        try {
            $retrying->invoke(null, function () use (&$calls): array {
                $calls++;
                throw new LogicException('snapshot refused');
            }, function () use (&$proofs): array {
                $proofs++;

                return [];
            }, ...$args);
            self::fail('a snapshot failure must propagate');
        } catch (LogicException $error) {
            self::assertSame('snapshot refused', $error->getMessage());
        }
        self::assertSame(1, $calls);
        self::assertSame(0, $proofs);

        // Only the proof's refusal type is retried: an Error from the proof propagates on the first attempt.
        $snapshots = 0;
        try {
            $retrying->invoke(null, $snapshot, function (): array {
                throw new \TypeError('not a refusal');
            }, ...$args);
            self::fail('an Error must propagate');
        } catch (\TypeError) {
        }
        self::assertSame(1, $snapshots);

        // Nor is any other Exception: only the refusal's LogicException is retried.
        $snapshots = 0;
        try {
            $retrying->invoke(null, $snapshot, function (): array {
                throw new \RuntimeException('not a refusal either');
            }, ...$args);
            self::fail('a RuntimeException must propagate');
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $snapshots);
    }

    public function test_a_refused_proof_never_exposes_the_writer_secret_in_its_trace(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $secret = 'SyntheticWriterSecret-'.bin2hex(random_bytes(8));
            $retrying = new ReflectionMethod(CatalogSocketBinding::class, 'retrying');
            // The real proof, as attest() wires it: an incomplete writer descriptor refuses on every attempt.
            $snapshots = 0;
            $snapshot = static function () use (&$snapshots): array {
                $snapshots++;

                return [['12345'], self::HEADER, self::HEADER];
            };
            try {
                $retrying->invoke(null, $snapshot, CatalogSocketBinding::prove(...), ['password' => $secret], [], 'status');
                self::fail('the proof refuses');
            } catch (LogicException $error) {
                // Every frame of the binding (retrying, prove, requested, refuse), including any closure it holds.
                // The test's own ReflectionMethod::invoke frame is the caller here, not production code.
                $frames = array_values(array_filter($error->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? null) === CatalogSocketBinding::class));
                self::assertContains('retrying', array_column($frames, 'function'));
                self::assertStringNotContainsString($secret, print_r($frames, true));
                self::assertStringNotContainsString($secret, var_export($frames, true));
            }
            self::assertSame(3, $snapshots);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function test_attest_hands_the_writer_to_the_retry_loop_as_a_redacted_argument_never_inside_a_closure(): void
    {
        // attest() needs a native mysqlnd PDO, so pin its wiring at the source: SensitiveParameter redacts parameters in
        // exception traces, not values captured by a closure, so attest must not wrap the writer in one.
        $method = new ReflectionMethod(CatalogSocketBinding::class, 'attest');
        $lines = file($method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringContainsString('return self::retrying(self::snapshot(...), self::prove(...), $writer, $context, $status);', $body);
        self::assertStringContainsString('if (! self::supportedPhp(\\PHP_VERSION, \\PHP_MAJOR_VERSION, \\PHP_MINOR_VERSION) ||', $body);
        self::assertDoesNotMatchRegularExpression('/\bfn\s*\(|\bfunction\s*\(|\buse\s*\(/', $body);
        $retrying = new ReflectionMethod(CatalogSocketBinding::class, 'retrying');
        self::assertNotEmpty($retrying->getParameters()[2]->getAttributes(\SensitiveParameter::class));
    }

    public function test_php_gate_admits_the_8_4_series_from_8_4_27_and_refuses_other_series(): void
    {
        $gate = new ReflectionMethod(CatalogSocketBinding::class, 'supportedPhp');
        $cases = ['8.4.26' => false, '8.4.27-dev' => false, '8.4.27' => true, '8.4.28' => true, '8.4.40' => true,
            '8.3.99' => false, '8.5.0' => false, '8.5.27' => false, '9.0.0' => false,
            // Pre-releases of 8.4.27 refuse; distribution suffixes and pre-releases of later 8.4 patches are admitted.
            '8.4.27RC1' => false, '8.4.27-1ubuntu1' => true, '8.4.28-dev' => true, '8.4.28RC1' => true];
        foreach ($cases as $version => $admitted) {
            [$major, $minor] = array_map('intval', explode('.', $version));
            self::assertSame($admitted, $gate->invoke(null, $version, $major, $minor), $version);
        }
    }

    public function test_every_qualified_function_the_binding_calls_is_proved_native(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(CatalogSocketBinding::class))->getFileName());
        $start = strpos($source, 'private static function internalFunctions(): void');
        self::assertIsInt($start);
        preg_match('/foreach \(\[(.*?)\] as \$name\)/s', substr($source, $start), $list);
        preg_match_all("/'([a-z_0-9]+)'/", $list[1] ?? '', $listed);
        preg_match_all('/(?<![\w\\\\])\\\\([a-z_][a-z_0-9]*)\(/', $source, $called);
        self::assertGreaterThan(20, count(array_unique($called[1])));
        $missing = array_values(array_diff(array_unique($called[1]), $listed[1]));
        self::assertSame([], $missing, 'Qualified native calls missing from internalFunctions().');
        self::assertContains('version_compare', $listed[1]);
    }

    public function test_only_a_read_past_the_cap_is_transient_and_any_other_read_anomaly_refuses(): void
    {
        $capped = new ReflectionMethod(CatalogSocketBinding::class, 'capped');
        $full = str_repeat('x', 1048576);
        self::assertSame($full, $capped->invoke(null, $full, true));
        self::assertNull($capped->invoke(null, $full.'x', false));
        self::assertNull($capped->invoke(null, $full.'x', true));
        foreach ([[self::HEADER, false], [false, true], [null, true]] as [$text, $eof]) {
            try {
                $capped->invoke(null, $text, $eof);
                self::fail('A failed or short read was admitted.');
            } catch (LogicException $error) {
                self::assertSame('Provable owned direct TCP metadata target required.', $error->getMessage());
            }
        }
    }

    /** A test table read in place of /proc/self/net/tcp{,6}, classified by the production cap. */
    private function reader(string $tcp, bool $eof = true): \Closure
    {
        $capped = new ReflectionMethod(CatalogSocketBinding::class, 'capped');

        return static fn (bool $ipv6): ?string => $capped->invoke(null, $ipv6 ? self::HEADER : $tcp, $eof);
    }

    private function oversized(): string
    {
        return self::HEADER.str_repeat($this->row(), intdiv(1048576, strlen($this->row())) + 1);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_an_oversized_table_refuses_only_that_attempt_and_a_later_attempt_succeeds(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux' || ! is_readable('/proc/self/net/tcp')) {
            self::markTestSkipped('Linux procfs host control.');
        }
        $snapshot = new ReflectionMethod(CatalogSocketBinding::class, 'snapshot');
        $retrying = new ReflectionMethod(CatalogSocketBinding::class, 'retrying');
        $snapshots = 0;
        $proofs = 0;
        try {
            // The real snapshot with only the table read replaced: no proof, and no second snapshot, in this attempt.
            $retrying->invoke(null, function () use ($snapshot, &$snapshots): array {
                $snapshots++;

                return $snapshot->invoke(null, $this->reader($this->oversized()));
            }, function () use (&$proofs): array {
                $proofs++;

                return [];
            }, [], [], 'status');
            self::fail('An oversized table was admitted.');
        } catch (LogicException $error) {
            self::assertSame('Provable owned direct TCP metadata target required.', $error->getMessage());
            self::assertStringNotContainsString('0200000A', $error->getMessage());
        }
        self::assertSame([1, 0], [$snapshots, $proofs]);

        // Not tainted: the next attempt reads a fresh table, both an injected normal one and the native procfs table.
        CatalogSocketBinding::requireUntainted();
        [$inodes, $tcp] = $snapshot->invoke(null, $this->reader(self::HEADER.$this->row()));
        self::assertTrue(array_is_list($inodes));
        self::assertSame(self::HEADER.$this->row(), $tcp);
        [, $tcp] = $snapshot->invoke(null);
        self::assertMatchesRegularExpression('/\A\s*sl\s+local_address\s/', $tcp);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_short_or_failed_table_read_still_taints_the_worker_permanently(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux' || ! is_readable('/proc/self/net/tcp')) {
            self::markTestSkipped('Linux procfs host control.');
        }
        $snapshot = new ReflectionMethod(CatalogSocketBinding::class, 'snapshot');
        try {
            // A read that stopped before EOF under the cap is suspicious, unlike one that ran past the cap.
            $snapshot->invoke(null, $this->reader(self::HEADER.$this->row(), false));
            self::fail('A short table read was admitted.');
        } catch (LogicException $error) {
            self::assertSame('Provable owned direct TCP metadata target required.', $error->getMessage());
        }
        foreach ([fn () => CatalogSocketBinding::requireUntainted(), fn () => $snapshot->invoke(null, $this->reader(self::HEADER.$this->row())),
            fn () => $snapshot->invoke(null)] as $later) {
            try {
                $later();
                self::fail('A tainted worker admitted a later attempt.');
            } catch (LogicException $error) {
                self::assertSame('Provable owned direct TCP metadata target required.', $error->getMessage());
            }
        }
    }

    public function test_unowned_ambiguous_missing_nonestablished_and_malformed_snapshots_refuse(): void
    {
        $cases = [
            [[], self::HEADER.$this->row()],
            [['99999'], self::HEADER.$this->row()],
            [['12345', '23456'], self::HEADER.$this->row(remote: '020200C0:0CEA').$this->row(remote: '010200C0:8141', inode: '23456')],
            [['12345', '23456'], self::HEADER.$this->row().$this->row(inode: '23456')],
            [['12345'], self::HEADER.$this->row(state: '02')],
            [['12345'], self::HEADER.$this->row().$this->row()],
            [['12345'], "malformed\n".$this->row()],
            [['12345'], self::HEADER.str_replace('0200000A', 'nothex00', $this->row())],
            [['12345'], self::HEADER.$this->row(remote: '010200C0:FFFF')],
        ];
        foreach ($cases as [$owned, $table]) {
            try {
                $this->proof($this->writer(), $this->context(), $owned, $table);
                self::fail('An unprovable peer was admitted.');
            } catch (LogicException $error) {
                self::assertSame('Provable owned direct TCP metadata target required.', $error->getMessage());
            }
        }
    }

    public function test_hostname_unix_status_source_identity_and_declared_port_mismatches_refuse(): void
    {
        foreach ([['host', 'foreign.example'], ['socket', '/tmp/unix.sock'], ['port', 3307]] as [$key, $value]) {
            $bad = $this->writer();
            $bad[$key] = $value;
            try {
                $this->proof($bad, $this->context(), ['12345'], self::HEADER.$this->row());
                self::fail('Wrong profile accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
        foreach (['localhost:50000', '10.0.0.3:50000', '10.0.0.2:0', '10.0.0.2:65536'] as $endpoint) {
            try {
                $this->proof($this->writer(), ['server_port' => 3306, 'client_endpoint' => $endpoint], ['12345'], self::HEADER.$this->row());
                self::fail('Wrong source accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }
}
