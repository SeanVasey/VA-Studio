<?php

namespace Tests\Unit;

use App\Domain\Migration\CatalogOnboarding\ProtectedCatalogReport;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProtectedCatalogReportTest extends TestCase
{
    private const KEY = 'NONBINDING synthetic signing key 0123456789';

    public function test_private_report_authentication_is_canonical_and_never_overwrites_a_retained_review(): void
    {
        $this->withDirectory(function (string $directory): void {
            $reports = new ProtectedCatalogReport;
            $path = $directory.'/review.json';
            $review = ['schema_version' => 1, 'records' => ['SYNTHETIC private review'], 'database_writes' => 0];
            $proof = $reports->write($path, $review, self::KEY);
            $this->assertSame(0600, fileperms($path) & 07777);
            $this->assertSame(CanonicalJson::encode($review), CanonicalJson::encode($reports->read($path, self::KEY)['review']));
            $reports->unchanged($proof);
            $before = file_get_contents($path);
            try {
                $reports->write($path, ['replacement' => true], self::KEY);
                $this->fail('Retained report overwritten.');
            } catch (InvalidArgumentException) {
            }
            $this->assertSame($before, file_get_contents($path));
        });
    }

    public static function altered(): array
    {
        return array_map(static fn (string $case): array => [$case], ['key', 'short-key', 'payload', 'hmac', 'extra-key',
            'noncanonical', 'permissions', 'hardlink', 'symlink', 'replaced-inode']);
    }

    #[DataProvider('altered')]
    public function test_changed_or_unsafe_report_cannot_authorize_an_apply(string $case): void
    {
        $this->withDirectory(function (string $directory) use ($case): void {
            $reports = new ProtectedCatalogReport;
            $path = $directory.'/review.json';
            $proof = $reports->write($path, ['record' => 'SYNTHETIC private review'], self::KEY);
            $envelope = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            $key = self::KEY;
            switch ($case) {
                case 'key': $key = str_repeat('x', 32);
                    break;
                case 'short-key': $key = 'short';
                    break;
                case 'payload': $envelope['review']['record'] = 'SYNTHETIC forged record';
                    break;
                case 'hmac': $envelope['hmac_sha256'] = str_repeat('0', 64);
                    break;
                case 'extra-key': $envelope['not_reviewed'] = true;
                    break;
                case 'permissions': chmod($path, 0644);
                    break;
                case 'hardlink': link($path, $directory.'/alias');
                    break;
                case 'symlink': rename($path, $directory.'/target');
                    symlink($directory.'/target', $path);
                    break;
                case 'replaced-inode':
                    file_put_contents($directory.'/new', file_get_contents($path));
                    chmod($directory.'/new', 0600);
                    rename($directory.'/new', $path);
                    break;
            }
            if (in_array($case, ['payload', 'hmac', 'extra-key'], true)) {
                file_put_contents($path, CanonicalJson::encode($envelope)."\n");
            }
            if ($case === 'noncanonical') {
                file_put_contents($path, json_encode($envelope, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            }
            $this->expectException(InvalidArgumentException::class);
            if ($case === 'replaced-inode') {
                $reports->unchanged($proof);
            } else {
                $reports->read($path, $key);
            }
        });
    }

    public function test_write_refuses_public_directory_and_short_key_without_creating_a_report(): void
    {
        $this->withDirectory(function (string $directory): void {
            $path = $directory.'/review.json';
            foreach (['directory', 'key'] as $case) {
                chmod($directory, $case === 'directory' ? 0755 : 0700);
                try {
                    (new ProtectedCatalogReport)->write($path, ['synthetic' => true], $case === 'key' ? 'short' : self::KEY);
                    $this->fail('Unsafe report write admitted.');
                } catch (InvalidArgumentException) {
                }
                $this->assertFileDoesNotExist($path);
            }
        });
    }

    private function withDirectory(callable $operation): void
    {
        $directory = sys_get_temp_dir().'/vasey-report-fixture-'.bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        mkdir($directory.'/private', 0700);
        try {
            $operation($directory.'/private');
        } finally {
            foreach (glob($directory.'/private/*') as $path) {
                unlink($path);
            }
            rmdir($directory.'/private');
            rmdir($directory);
        }
    }
}
