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
        file_put_contents($this->base.'/outside.wav', 'RIFF');
        mkdir($this->root.'/Studio/Dir.wav');
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
        $resolved = $files->resolve('master_wav', '/Studio/Masters/a.wav');
        $this->assertSame('ok', $resolved['state']);
        $this->assertSame($this->root.'/Studio/Masters/a.wav', $resolved['path']);
        $this->assertSame(4, $resolved['bytes']);
        $this->assertTrue($files->unchanged($resolved));
        file_put_contents($resolved['path'], 'RIFFmore');
        $this->assertFalse($files->unchanged($resolved));
        // The extension check is case-insensitive but a role's own extensions only.
        copy($this->root.'/Studio/Masters/a.wav', $this->root.'/Studio/Masters/UP.WAV');
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
            'empty file' => ['master_wav', '/Studio/Masters/empty.wav', ['state' => 'refused', 'code' => 'empty_file']],
            'unknown role' => ['preview_wav', '/Studio/Masters/a.wav', ['state' => 'refused', 'code' => 'unknown_role']],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_every_other_listing_is_a_typed_outcome_and_never_a_guess(string $role, string $listed, array $expected): void
    {
        $this->assertSame($expected, BeatStarsMediaFiles::open($this->root)->resolve($role, $listed));
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
