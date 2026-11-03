<?php

namespace Tests\Unit\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\CatalogSocketBinding;
use LogicException;
use PHPUnit\Framework\TestCase;

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

    public function test_target_matching_other_owned_socket_never_selects_it_instead_of_primary(): void
    {
        // Primary is forwarded33089; a DIFFERENT own socket matches requested3306. Filtering target first is unsafe.
        $tcp = self::HEADER.$this->row(remote: '010200C0:8141').$this->row(local: '0200000A:C351', inode: '23456');
        $this->expectException(LogicException::class);
        $this->proof($this->writer(), $this->context(), ['12345', '23456'], $tcp);
    }

    public function test_unowned_ambiguous_missing_nonestablished_and_malformed_snapshots_refuse(): void
    {
        $cases = [
            [[], self::HEADER.$this->row()],
            [['99999'], self::HEADER.$this->row()],
            [['12345', '23456'], self::HEADER.$this->row().$this->row(remote: '020200C0:0CEA', inode: '23456')],
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
