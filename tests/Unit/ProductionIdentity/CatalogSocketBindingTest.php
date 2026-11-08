<?php

namespace Tests\Unit\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\CatalogSocketBinding;
use LogicException;
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
        $snapshots = 0;
        $snapshot = function () use (&$snapshots): array {
            $snapshots++;

            return [['12345'], 'tcp '.$snapshots, ''];
        };

        // Every attempt refuses: exactly three fresh snapshots, then the last refusal propagates.
        $seen = [];
        try {
            $retrying->invoke(null, $snapshot, function (array $inodes, string $tcp) use (&$seen): array {
                $seen[] = $tcp;
                throw new LogicException('refusal '.$tcp);
            });
            self::fail('a proof that always refuses must not be accepted');
        } catch (LogicException $error) {
            self::assertSame('refusal tcp 3', $error->getMessage());
        }
        self::assertSame(['tcp 1', 'tcp 2', 'tcp 3'], $seen);
        self::assertSame(3, $snapshots);

        // A torn first snapshot recovers on the second, and the proof of that fresh snapshot is what is returned.
        $snapshots = 0;
        $result = $retrying->invoke(null, $snapshot, function (array $inodes, string $tcp): array {
            if ($tcp === 'tcp 1') {
                throw new LogicException('torn');
            }

            return ['tcp' => $tcp];
        });
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
            });
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
            });
            self::fail('an Error must propagate');
        } catch (\TypeError) {
        }
        self::assertSame(1, $snapshots);
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
