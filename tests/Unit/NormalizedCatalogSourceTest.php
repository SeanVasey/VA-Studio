<?php

namespace Tests\Unit;

use App\Domain\Migration\CatalogOnboarding\NormalizedSourceSnapshot;
use App\Domain\Migration\CatalogOnboarding\PrivateSourceFiles;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\NormalizedCatalogFixtures;

class NormalizedCatalogSourceTest extends TestCase
{
    public function test_normalization_retains_typed_source_ids_original_values_and_unknown_obligations(): void
    {
        $snapshot = NormalizedCatalogFixtures::snapshot();
        $snapshot['records'][0]['visibility'] = 'sold';
        $snapshot['records'][0] = NormalizedCatalogFixtures::seal($snapshot['records'][0]);
        $snapshot['records'][1]['visibility'] = 'unknown';
        $snapshot['records'][1] = NormalizedCatalogFixtures::seal($snapshot['records'][1]);
        $parsed = (new NormalizedSourceSnapshot)->decode(CanonicalJson::encode($snapshot)."\n");
        $this->assertSame(CanonicalJson::encode($snapshot), CanonicalJson::encode($parsed));
        $this->assertSame('001', $parsed['records'][0]['source_id']);
        $this->assertSame('0095', $parsed['records'][0]['raw_metadata']['bpm']);
        $this->assertSame(95, $parsed['records'][0]['metadata']['bpm']);
        $this->assertSame(['sold', 'unknown'], array_column($parsed['records'], 'visibility'));
        $keys = new NormalizedSourceSnapshot;
        $this->assertNotSame($keys->recordKey('a/b', 'c'), $keys->recordKey('a', 'b/c'));
        $this->assertNotSame($keys->recordKey('a', '001'), $keys->recordKey('a', '1'));
    }

    public static function invalid(): array
    {
        return array_map(static fn (string $case): array => [$case], ['duplicate-key', 'float', 'numeric-id', 'extra-rights',
            'wrong-record-hash', 'bad-date', 'future-watermark', 'raw-boolean', 'normalized-string-bpm', 'asset-digest',
            'unbound-artifact', 'traversal', 'unknown-schema', 'untagged-synthetic', 'bad-tags', 'oversized']);
    }

    #[DataProvider('invalid')]
    public function test_ambiguous_or_unreviewed_source_fields_fail_closed(string $case): void
    {
        $snapshot = NormalizedCatalogFixtures::snapshot(1);
        $raw = null;
        switch ($case) {
            case 'duplicate-key': $raw = str_replace('"schema_version":1', '"schema_version":1,"schema_version":1', CanonicalJson::encode($snapshot));
                break;
            case 'float': $raw = str_replace('"bpm":95', '"bpm":95.0', CanonicalJson::encode($snapshot));
                break;
            case 'numeric-id': $snapshot['records'][0]['source_id'] = 1;
                break;
            case 'extra-rights': $snapshot['records'][0]['rights'] = 'cleared';
                break;
            case 'wrong-record-hash': $snapshot['records'][0]['source_record_sha256'] = str_repeat('0', 64);
                break;
            case 'bad-date': $snapshot['acquired_at'] = '2026-02-30T12:00:00Z';
                break;
            case 'future-watermark': $snapshot['source_as_of'] = '2027-01-01T00:00:00Z';
                break;
            case 'raw-boolean': $snapshot['records'][0]['raw_metadata']['artist'] = true;
                break;
            case 'normalized-string-bpm': $snapshot['records'][0]['metadata']['bpm'] = '95';
                break;
            case 'asset-digest': $snapshot['records'][0]['assets'] = [['source_asset_id' => 'asset-1', 'artifact_id' => 'synthetic-raw',
                'role' => 'master_wav', 'original_name' => 'SYNTHETIC.wav', 'sha256' => str_repeat('0', 64), 'bytes' => strlen(NormalizedCatalogFixtures::ARTIFACT)]];
                break;
            case 'unbound-artifact': $snapshot['records'][0]['artifact_ids'] = ['absent'];
                break;
            case 'traversal': $snapshot['artifacts'][0]['relative_path'] = '../escape.csv';
                break;
            case 'unknown-schema': $snapshot['purpose'] = 'uninspected-provider-export';
                break;
            case 'untagged-synthetic': $snapshot['records'][0]['metadata']['title'] = 'Untagged';
                break;
            case 'bad-tags': $snapshot['records'][0]['metadata']['tags'] = ['duplicate', 'duplicate'];
                break;
            case 'oversized': $raw = str_repeat(' ', 1048577);
                break;
        }
        if (! in_array($case, ['wrong-record-hash', 'extra-rights'], true)) {
            $snapshot['records'][0] = NormalizedCatalogFixtures::seal($snapshot['records'][0]);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('catalog_source_invalid');
        (new NormalizedSourceSnapshot)->decode($raw ?? CanonicalJson::encode($snapshot));
    }

    public function test_private_artifacts_are_hashed_without_mutation_and_changed_bytes_are_refused(): void
    {
        $this->withSource(function (string $source): void {
            $files = new PrivateSourceFiles;
            $before = file_get_contents($source.'/catalog.json');
            $proof = $files->snapshot($source);
            $files->unchanged($proof);
            $this->assertSame(hash('sha256', NormalizedCatalogFixtures::ARTIFACT), $proof['artifacts']['synthetic-raw']['sha256']);
            $this->assertSame($before, file_get_contents($source.'/catalog.json'));
            file_put_contents($source.'/raw/source.csv', str_repeat('X', strlen(NormalizedCatalogFixtures::ARTIFACT)));
            $this->expectException(InvalidArgumentException::class);
            $files->unchanged($proof);
        });
    }

    public static function unsafeFiles(): array
    {
        return array_map(static fn (string $case): array => [$case], ['symlink', 'hardlink', 'public-file', 'public-child', 'writable-parent', 'writable-ancestor', 'false-size', 'false-hash', 'git-ancestor']);
    }

    #[DataProvider('unsafeFiles')]
    public function test_private_source_admission_refuses_unsafe_paths_and_false_artifact_claims(string $case): void
    {
        $this->withSource(function (string $source, string $base) use ($case): void {
            $artifact = $source.'/raw/source.csv';
            $original = file_get_contents($artifact);
            $manifest = file_get_contents($source.'/catalog.json');
            switch ($case) {
                case 'symlink': rename($artifact, $base.'/outside.csv');
                    symlink($base.'/outside.csv', $artifact);
                    break;
                case 'hardlink': link($artifact, $base.'/linked.csv');
                    break;
                case 'public-file': chmod($artifact, 0644);
                    break;
                case 'public-child': chmod($source.'/raw', 0755);
                    break;
                case 'writable-parent': chmod(dirname($source), 0777);
                    break;
                case 'writable-ancestor': chmod($base, 0777);
                    break;
                case 'git-ancestor': mkdir($base.'/.git', 0700);
                    break;
                case 'false-size':
                case 'false-hash':
                    $snapshot = NormalizedCatalogFixtures::snapshot();
                    $snapshot['artifacts'][0][$case === 'false-size' ? 'bytes' : 'sha256'] = $case === 'false-size' ? 1 : str_repeat('0', 64);
                    file_put_contents($source.'/catalog.json', CanonicalJson::encode($snapshot));
                    break;
            }
            try {
                (new PrivateSourceFiles)->snapshot($source);
                $this->fail('Unsafe source accepted: '.$case);
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('catalog_source_files_invalid', $exception->getMessage());
            }
            $this->assertSame($original, file_get_contents($artifact));
            if (! in_array($case, ['false-size', 'false-hash'], true)) {
                $this->assertSame($manifest, file_get_contents($source.'/catalog.json'));
            }
        });
    }

    private function withSource(callable $callback): void
    {
        $base = sys_get_temp_dir().'/vasey-normalized-'.bin2hex(random_bytes(12));
        mkdir($base, 0700);
        mkdir($base.'/parent', 0700);
        $source = $base.'/parent/source';
        mkdir($source, 0700);
        mkdir($source.'/raw', 0700);
        file_put_contents($source.'/catalog.json', CanonicalJson::encode(NormalizedCatalogFixtures::snapshot())."\n");
        file_put_contents($source.'/raw/source.csv', NormalizedCatalogFixtures::ARTIFACT);
        chmod($source.'/catalog.json', 0600);
        chmod($source.'/raw/source.csv', 0600);
        try {
            $callback($source, $base);
        } finally {
            // Only this test's explicit synthetic disposable fixture is removed, never an operator source.
            $remove = function (string $path) use (&$remove): void {
                if (is_link($path) || ! is_dir($path)) {
                    unlink($path);

                    return;
                }
                foreach (scandir($path) as $name) {
                    if ($name !== '.' && $name !== '..') {
                        $remove($path.'/'.$name);
                    }
                }
                rmdir($path);
            };
            $remove($base);
        }
    }
}
