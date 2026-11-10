<?php

namespace Tests\Unit\Migration;

use App\Domain\Migration\BeatStarsImport\BeatStarsImportRefusal;
use App\Domain\Migration\BeatStarsImport\BeatStarsMediaFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact-match resolution of listed paths under the import root: synthetic files in a private temporary directory only. */
class BeatStarsMediaFilesTest extends TestCase
{
    private string $base;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = realpath(sys_get_temp_dir()).'/vasey-bs-files-'.bin2hex(random_bytes(10));
        mkdir($this->base, 0700);
        $this->root = $this->base.'/files';
        mkdir($this->root, 0750);
        mkdir($this->root.'/Studio/Masters', 0755, true);
        file_put_contents($this->root.'/Studio/Masters/a.wav', 'RIFF');
        file_put_contents($this->root.'/Studio/Masters/empty.wav', '');
        file_put_contents($this->root.'/Studio/Masters/solo.wav', 'RIFF');
        file_put_contents($this->base.'/outside.wav', 'RIFF');
        mkdir($this->root.'/Studio/Dir.wav');
        link($this->root.'/Studio/Masters/a.wav', $this->root.'/Studio/Masters/hard.wav');
        $big = fopen($this->root.'/Studio/Masters/big.png', 'wb');
        ftruncate($big, 20 * 1024 * 1024 + 1);
        fclose($big);
        symlink($this->base.'/outside.wav', $this->root.'/Studio/Masters/link.wav');
        symlink($this->root.'/Studio/Masters/a.wav', $this->root.'/Studio/Masters/inside-link.wav');
        symlink($this->base, $this->root.'/Studio/Escape');
        symlink($this->root.'/Studio/Masters', $this->root.'/Studio/Alias');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->base));
        parent::tearDown();
    }

    public function test_an_exact_regular_file_under_the_root_resolves_with_its_identity(): void
    {
        $files = BeatStarsMediaFiles::open($this->root);
        $resolved = $files->resolve('master_wav', '/Studio/Masters/solo.wav');
        $this->assertSame('ok', $resolved['state']);
        $this->assertSame($this->root.'/Studio/Masters/solo.wav', $resolved['path']);
        $this->assertSame(4, $resolved['bytes']);
        $this->assertTrue($files->unchanged($resolved));
        file_put_contents($resolved['path'], 'RIFFmore');
        $this->assertFalse($files->unchanged($resolved));
        // The extension check is case-insensitive but a role's own extensions only.
        copy($this->root.'/Studio/Masters/solo.wav', $this->root.'/Studio/Masters/UP.WAV');
        $this->assertSame('ok', $files->resolve('master_wav', '/Studio/Masters/UP.WAV')['state']);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, string>}> */
    public static function outcomes(): array
    {
        return [
            'blank cell' => ['master_wav', '', ['state' => 'not_listed']],
            'whitespace cell' => ['master_wav', '   ', ['state' => 'not_listed']],
            'absent file' => ['master_wav', '/Studio/Masters/none.wav', ['state' => 'missing']],
            'absent directory' => ['master_wav', '/Nowhere/a.wav', ['state' => 'missing']],
            'a file used as a directory' => ['master_wav', '/Studio/Masters/a.wav/x.wav', ['state' => 'missing']],
            'relative path' => ['master_wav', 'Studio/Masters/a.wav', ['state' => 'refused', 'code' => 'path_not_absolute']],
            'parent component' => ['master_wav', '/Studio/../outside.wav', ['state' => 'refused', 'code' => 'path_traversal']],
            'parent escaping the root' => ['master_wav', '/../outside.wav', ['state' => 'refused', 'code' => 'path_traversal']],
            'current component' => ['master_wav', '/Studio/./Masters/a.wav', ['state' => 'refused', 'code' => 'path_traversal']],
            'doubled separator' => ['master_wav', '/Studio//Masters/a.wav', ['state' => 'refused', 'code' => 'path_traversal']],
            'trailing separator' => ['master_wav', '/Studio/Masters/', ['state' => 'refused', 'code' => 'path_traversal']],
            'padded path' => ['master_wav', ' /Studio/Masters/a.wav', ['state' => 'refused', 'code' => 'path_invalid']],
            'backslash' => ['master_wav', '\\Studio\\Masters\\a.wav', ['state' => 'refused', 'code' => 'path_invalid']],
            'control character' => ['master_wav', "/Studio/Masters/a\n.wav", ['state' => 'refused', 'code' => 'path_invalid']],
            'NUL byte' => ['master_wav', "/Studio/Masters/a.wav\0.png", ['state' => 'refused', 'code' => 'path_invalid']],
            'wrong extension for the role' => ['master_wav', '/Studio/Masters/a.zip', ['state' => 'refused', 'code' => 'wrong_extension']],
            'stems role wants a zip' => ['stems_zip', '/Studio/Masters/a.wav', ['state' => 'refused', 'code' => 'wrong_extension']],
            'artwork role rejects a wav' => ['artwork', '/Studio/Masters/a.wav', ['state' => 'refused', 'code' => 'wrong_extension']],
            'double extension' => ['master_wav', '/Studio/Masters/a.wav.exe', ['state' => 'refused', 'code' => 'wrong_extension']],
            'symlinked file to outside the root' => ['master_wav', '/Studio/Masters/link.wav', ['state' => 'refused', 'code' => 'symlink']],
            'symlinked file inside the root' => ['master_wav', '/Studio/Masters/inside-link.wav', ['state' => 'refused', 'code' => 'symlink']],
            'symlinked directory to outside the root' => ['master_wav', '/Studio/Escape/outside.wav', ['state' => 'refused', 'code' => 'symlink']],
            'symlinked directory inside the root' => ['master_wav', '/Studio/Alias/a.wav', ['state' => 'refused', 'code' => 'symlink']],
            'directory named like a file' => ['master_wav', '/Studio/Dir.wav', ['state' => 'refused', 'code' => 'not_regular_file']],
            'hard link to another name' => ['master_wav', '/Studio/Masters/hard.wav', ['state' => 'refused', 'code' => 'hardlink']],
            'original of a hard link' => ['master_wav', '/Studio/Masters/a.wav', ['state' => 'refused', 'code' => 'hardlink']],
            'artwork over the intake cap' => ['artwork', '/Studio/Masters/big.png', ['state' => 'refused', 'code' => 'too_large']],
            'empty file' => ['master_wav', '/Studio/Masters/empty.wav', ['state' => 'refused', 'code' => 'empty_file']],
            'unknown role' => ['preview_wav', '/Studio/Masters/a.wav', ['state' => 'refused', 'code' => 'unknown_role']],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_every_other_listing_is_a_typed_outcome_and_never_a_guess(string $role, string $listed, array $expected): void
    {
        $this->assertSame($expected, BeatStarsMediaFiles::open($this->root)->resolve($role, $listed));
    }

    /**
     * Sparse files fake the sizes: no data blocks are written, so a 1 GiB file costs the filesystem nothing.
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: array<string, mixed>}>
     */
    public static function intakeCaps(): array
    {
        $mib = 1024 * 1024;

        return [
            'stems ZIP at exactly 1 GiB is in the cap' => ['stems_zip', 'stems.zip', 1024 * $mib, ['state' => 'ok']],
            'stems ZIP one byte over 1 GiB is too large' => ['stems_zip', 'stems.zip', 1024 * $mib + 1, ['state' => 'refused', 'code' => 'too_large']],
            'stems ZIP at the old 200 MiB cap is in the cap' => ['stems_zip', 'stems.zip', 200 * $mib, ['state' => 'ok']],
            'master WAV at exactly 200 MiB is in the cap' => ['master_wav', 'master.wav', 200 * $mib, ['state' => 'ok']],
            'master WAV one byte over 200 MiB is still too large' => ['master_wav', 'master.wav', 200 * $mib + 1, ['state' => 'refused', 'code' => 'too_large']],
            'master WAV at 1 GiB is still too large' => ['master_wav', 'master.wav', 1024 * $mib, ['state' => 'refused', 'code' => 'too_large']],
            'artwork at exactly 20 MiB is in the cap' => ['artwork', 'art.png', 20 * $mib, ['state' => 'ok']],
            'artwork one byte over 20 MiB is still too large' => ['artwork', 'art.png', 20 * $mib + 1, ['state' => 'refused', 'code' => 'too_large']],
            'high-resolution artwork one byte over 20 MiB is still too large' => ['artwork_high_res', 'art-hi.png', 20 * $mib + 1, ['state' => 'refused', 'code' => 'too_large']],
            'preview MP3 one byte over 200 MiB is still too large' => ['preview_mp3', 'preview.mp3', 200 * $mib + 1, ['state' => 'refused', 'code' => 'too_large']],
        ];
    }

    #[DataProvider('intakeCaps')]
    public function test_the_intake_cap_is_per_role_and_only_the_stems_zip_reaches_one_gibibyte(string $role, string $file, int $bytes, array $expected): void
    {
        $handle = fopen($this->root.'/Studio/Masters/'.$file, 'wb');
        ftruncate($handle, $bytes);
        fclose($handle);

        $resolved = BeatStarsMediaFiles::open($this->root)->resolve($role, '/Studio/Masters/'.$file);

        $this->assertSame($expected['state'], $resolved['state']);
        $this->assertSame($expected['code'] ?? null, $resolved['code'] ?? null);
        if ($expected['state'] === 'ok') {
            $this->assertSame($bytes, $resolved['bytes']);
        }
    }

    public function test_the_root_must_be_canonical_private_to_the_user_and_not_a_symlink(): void
    {
        foreach ([$this->root.'/', $this->root.'/../files', $this->base.'/missing', '/', 'relative/files', $this->root.'/Studio/Masters/a.wav'] as $bad) {
            try {
                BeatStarsMediaFiles::open($bad);
                $this->fail('Expected a refusal for '.$bad);
            } catch (BeatStarsImportRefusal $refusal) {
                $this->assertStringContainsString('media root', $refusal->getMessage());
            }
        }
        symlink($this->root, $this->base.'/link-root');
        chmod($this->root, 0775);
        foreach ([$this->base.'/link-root', $this->root] as $bad) {
            try {
                BeatStarsMediaFiles::open($bad);
                $this->fail('Expected a refusal for '.$bad);
            } catch (BeatStarsImportRefusal) {
                $this->addToAssertionCount(1);
            }
        }
        chmod($this->root, 0750);
        $this->assertInstanceOf(BeatStarsMediaFiles::class, BeatStarsMediaFiles::open($this->root));
    }
}
