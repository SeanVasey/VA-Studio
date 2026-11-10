<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\StagingSpecimenSignature;
use App\Domain\Contracts\Production\VaseyLicenseSignature;
use App\Support\Diagnostics\CutoverLedgerChecks as Checks;
use App\Support\Diagnostics\CutoverMarkers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DD-27 / PCL-06: the staging specimen signature image. Text only, deterministic, private disk only, accepted by the
 * licence signature loader, named as a specimen by `vasey:cutover-check`, and never made in production.
 */
class StagingSpecimenSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['contracts.vasey_license_v1.signature_path' => null, 'contracts.vasey_license_v1.signature_sha256' => null]);
    }

    private function run_(array $options = []): array
    {
        $exit = Artisan::call('vasey:staging-specimen-signature', $options);

        return [$exit, Artisan::output()];
    }

    private function installed(): string
    {
        return Storage::disk('local')->path(StagingSpecimenSignature::PATH);
    }

    private function cutoverRow(string $profile): array
    {
        Artisan::call('vasey:cutover-check', ['--json' => true, '--expect' => $profile]);
        $rows = array_column(json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR)['rows'], null, 'id');

        return $rows['PCL-06'];
    }

    public function test_it_installs_a_private_owner_only_png_and_prints_the_two_environment_lines(): void
    {
        [$exit, $output] = $this->run_();
        $this->assertSame(0, $exit, $output);
        $path = $this->installed();
        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertStringStartsWith(Storage::disk('local')->path(''), $path);
        $bytes = (string) file_get_contents($path);
        $this->assertStringContainsString('VASEY_LICENSE_SIGNATURE_PATH='.StagingSpecimenSignature::PATH, $output);
        $this->assertStringContainsString('VASEY_LICENSE_SIGNATURE_SHA256='.hash('sha256', $bytes), $output);
        $this->assertStringContainsString('STAGING ONLY', $output);
        $this->assertStringContainsString('does not select this specimen yet', $output);
        // Nothing else is left beside it, and nothing was written outside private storage or under public/.
        $this->assertSame([basename($path)], array_values(array_diff(scandir(dirname($path)), ['.', '..'])));
        $this->assertFileDoesNotExist(public_path(StagingSpecimenSignature::PATH));
    }

    public function test_the_bytes_are_deterministic_and_carry_no_timestamp_or_other_metadata(): void
    {
        $first = StagingSpecimenSignature::png();
        $second = StagingSpecimenSignature::png();
        $this->assertSame(hash('sha256', $first), hash('sha256', $second));

        [$exit, $output] = $this->run_();
        $this->assertSame(0, $exit, $output);
        $installed = (string) file_get_contents($this->installed());
        $this->assertSame($first, $installed);
        unlink($this->installed());
        [$exit] = $this->run_();
        $this->assertSame(0, $exit);
        $this->assertSame($installed, file_get_contents($this->installed()));

        // The only chunks are IHDR, GD's fixed pHYs, one fixed tEXt, IDAT and IEND: no tIME, eXIf or software tag.
        $types = [];
        for ($offset = 8; $offset < strlen($installed);) {
            $length = unpack('N', substr($installed, $offset, 4))[1];
            $types[] = substr($installed, $offset + 4, 4);
            $offset += 12 + $length;
        }
        $this->assertSame('IHDR', $types[0]);
        $this->assertSame([], array_values(array_diff($types, ['IHDR', 'pHYs', 'tEXt', 'IDAT', 'IEND'])));
        $this->assertSame(1, count(array_keys($types, 'tEXt', true)));
        $this->assertSame('IEND', end($types));
        $this->assertStringContainsString(CutoverMarkers::SPECIMEN_SIGNATURE_MARKER, $installed);
        $this->assertSame(CutoverMarkers::SPECIMEN_SIGNATURE_MARKER, StagingSpecimenSignature::MARKER);
    }

    public function test_the_picture_is_opaque_black_on_white_text_and_within_the_loader_limits(): void
    {
        $bytes = StagingSpecimenSignature::png();
        $this->assertLessThan(VaseyLicenseSignature::MAX_BYTES, strlen($bytes));
        $image = imagecreatefromstring($bytes);
        $this->assertNotFalse($image);
        $this->assertSame(StagingSpecimenSignature::WIDTH, imagesx($image));
        $this->assertSame(StagingSpecimenSignature::HEIGHT, imagesy($image));
        $dark = 0;
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $this->assertSame(0, ($rgba >> 24) & 0x7F, 'every pixel is opaque');
                $dark += (($rgba >> 16) & 0xFF) < 128 ? 1 : 0;
            }
        }
        // Text covers a small share of the canvas: a drawn flourish or a filled mark would cover far more or none.
        $share = $dark / (imagesx($image) * imagesy($image));
        $this->assertGreaterThan(0.02, $share);
        $this->assertLessThan(0.30, $share);
        $this->assertSame(0xFFFFFF, imagecolorat($image, 0, 0) & 0xFFFFFF);
    }

    public function test_the_licence_signature_loader_accepts_the_installed_image(): void
    {
        [$exit, $output] = $this->run_();
        $this->assertSame(0, $exit, $output);
        $sha256 = hash_file('sha256', $this->installed());
        config(['contracts.vasey_license_v1.signature_path' => StagingSpecimenSignature::PATH, 'contracts.vasey_license_v1.signature_sha256' => $sha256]);
        $read = VaseyLicenseSignature::fromConfig()->read();
        $this->assertSame(StagingSpecimenSignature::WIDTH, $read['width']);
        $this->assertSame(StagingSpecimenSignature::HEIGHT, $read['height']);
        $this->assertSame($sha256, hash('sha256', $read['bytes']));

        [$exit, $output] = $this->run_();
        $this->assertStringContainsString('Already installed and unchanged', $output);
        $this->assertStringContainsString('already selects this specimen', $output);

        // A wrong pin still refuses, so the specimen is no looser than a real signature.
        config(['contracts.vasey_license_v1.signature_sha256' => str_repeat('0', 64)]);
        $this->expectException(ContractIssuanceException::class);
        VaseyLicenseSignature::fromConfig()->read();
    }

    public function test_the_cutover_check_names_the_specimen_and_production_fails_on_it(): void
    {
        $this->run_();
        $sha256 = hash_file('sha256', $this->installed());
        config(['contracts.vasey_license_v1.signature_path' => StagingSpecimenSignature::PATH, 'contracts.vasey_license_v1.signature_sha256' => $sha256]);
        $staging = $this->cutoverRow(Checks::STAGING);
        $this->assertSame('PASS', $staging['status']);
        $this->assertStringContainsString('the staging specimen is configured', json_encode($staging));
        $production = $this->cutoverRow(Checks::PRODUCTION);
        $this->assertSame('FAIL', $production['status']);
        $this->assertStringContainsString('staging specimen', json_encode($production));

        // Renamed to look approved, the marker inside the file still names it.
        Storage::disk('local')->put('contracts/signature.png', (string) file_get_contents($this->installed()));
        config(['contracts.vasey_license_v1.signature_path' => 'contracts/signature.png']);
        $renamed = $this->cutoverRow(Checks::PRODUCTION);
        $this->assertSame('FAIL', $renamed['status']);
        $this->assertStringContainsString('staging specimen', json_encode($renamed));
    }

    public function test_production_and_an_ambiguous_environment_refuse_generation(): void
    {
        foreach ([['production', 'production'], ['production', 'staging'], ['staging', 'production'], ['staging-eu', 'staging-eu']] as [$configured, $running]) {
            config(['app.env' => $configured]);
            $this->app->instance('env', $running);
            [$exit, $output] = $this->run_();
            $this->assertSame(1, $exit, $configured.'/'.$running);
            $this->assertStringContainsString('Refused: The staging specimen signature is generated only', $output);
            $this->assertFileDoesNotExist($this->installed());
            $this->assertFalse(StagingSpecimenSignature::admitted());
        }
        foreach (['local', 'testing', 'staging'] as $environment) {
            config(['app.env' => $environment]);
            $this->app->instance('env', $environment);
            $this->assertTrue(StagingSpecimenSignature::admitted(), $environment);
        }
    }

    public function test_check_installs_nothing(): void
    {
        [$exit, $output] = $this->run_(['--check' => true]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('nothing was installed', $output);
        $this->assertFileDoesNotExist($this->installed());
    }

    public function test_it_never_replaces_a_different_file_or_overrides_another_configured_signature(): void
    {
        Storage::disk('local')->put(StagingSpecimenSignature::PATH, 'another file');
        [$exit, $output] = $this->run_();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('A different file is already at', $output);
        $this->assertSame('another file', file_get_contents($this->installed()));
        Storage::disk('local')->delete(StagingSpecimenSignature::PATH);

        Storage::disk('local')->put('contracts/sean.png', 'real');
        config(['contracts.vasey_license_v1.signature_path' => 'contracts/sean.png']);
        [$exit, $output] = $this->run_();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Another signature image is installed and configured', $output);
        $this->assertFileDoesNotExist($this->installed());
    }
}
