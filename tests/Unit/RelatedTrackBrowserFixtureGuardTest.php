<?php

namespace Tests\Unit;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

require_once __DIR__.'/../browser/prepare-related-tracks.php';

class RelatedTrackBrowserFixtureGuardTest extends TestCase
{
    private string $directory;

    private array $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/vasey-browser-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/app/private', 0700, true);
        chmod($this->directory, 0700);
        $this->env = ['VASEY_BROWSER_DIRECTORY' => $this->directory, 'VASEY_BROWSER_RELATED_MARKER' => str_repeat('a', 64), 'VASEY_BROWSER_RELATED_STAGE' => '1',
            'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8173', 'LARAVEL_STORAGE_PATH' => $this->directory,
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->directory.'/database.sqlite', 'DB_URL' => '',
            'APP_CONFIG_CACHE' => $this->directory.'/config.php', 'APP_ROUTES_CACHE' => $this->directory.'/routes.php',
            'APP_EVENTS_CACHE' => $this->directory.'/events.php', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'FILESYSTEM_DISK' => 'local', 'STRIPE_WEBHOOK_ENABLED' => 'false'];
        $this->write('database.sqlite', 'untouched synthetic guard bytes');
        $this->write('fixtures.json', json_encode(array_fill_keys(['chromium-desktop', 'webkit-mobile'],
            ['editable' => ['title' => 'Synthetic editable', 'slug' => 'editable'], 'retained' => ['title' => 'Synthetic retained', 'slug' => 'retained']]), JSON_THROW_ON_ERROR));
        $this->marker();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_valid_guard_preparation_is_read_only_and_accepts_only_the_two_explicit_project_states(): void
    {
        $before = $this->snapshot();
        $guard = \RelatedTrackBrowserFixture::guard($this->env, ['prepare']);
        $this->assertSame($this->directory, $guard['directory']);
        $this->assertTrue($guard['prepare']);
        $this->assertSame($before, $this->snapshot());
        $this->write('related-track-fixtures.json', '{}');
        foreach (['chromium-desktop', 'webkit-mobile'] as $project) {
            foreach (['published', 'withdrawn'] as $state) {
                $this->assertFalse(\RelatedTrackBrowserFixture::guard($this->env, ['verify', $project, $state])['prepare']);
            }
        }
    }

    public function test_environment_and_argument_substitution_is_refused_before_boot_or_mutation(): void
    {
        foreach (['VASEY_BROWSER_RELATED_STAGE' => '0', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'true', 'APP_URL' => 'https://example.test', 'LARAVEL_STORAGE_PATH' => '/tmp',
            'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => '/tmp/other.sqlite', 'DB_URL' => 'sqlite:///tmp/other.sqlite',
            'APP_CONFIG_CACHE' => '/tmp/cache.php', 'APP_ROUTES_CACHE' => '/tmp/routes.php', 'APP_EVENTS_CACHE' => '/tmp/events.php',
            'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'smtp', 'FILESYSTEM_DISK' => 's3', 'STRIPE_WEBHOOK_ENABLED' => 'true',
            'VASEY_BROWSER_RELATED_MARKER' => str_repeat('A', 64)] as $key => $value) {
            $this->refused(array_replace($this->env, [$key => $value]), ['prepare']);
        }
        foreach ([[], ['prepare', 'extra'], ['verify', 'other', 'published'], ['verify', 'chromium-desktop', 'ready'],
            ['restore'], ['verify', 'chromium-desktop'], ['verify', 'webkit-mobile', 'withdrawn', 'extra']] as $arguments) {
            $this->refused($this->env, $arguments);
        }
        $missingStage = $this->env;
        unset($missingStage['VASEY_BROWSER_RELATED_STAGE']);
        $this->refused($missingStage, ['prepare']);
    }

    public function test_marker_spelling_extra_keys_duplicates_and_foreign_identity_are_refused(): void
    {
        foreach (['marker' => str_repeat('b', 64), 'database' => '/tmp/other.sqlite', 'origin' => 'http://localhost:8173', 'operatorId' => '1', 'extra' => true] as $key => $value) {
            $this->marker([$key => $value]);
            $this->refused($this->env, ['prepare']);
        }
        $this->marker();
        $path = $this->directory.'/related-track-fixture-marker.json';
        $bytes = file_get_contents($path);
        $this->write('related-track-fixture-marker.json', '{"marker":"wrong",'.substr($bytes, 1));
        $this->refused($this->env, ['prepare']);
        $this->write('related-track-fixture-marker.json', str_repeat(' ', 65537));
        $this->refused($this->env, ['prepare']);
    }

    public function test_private_files_caches_symlinks_and_preexisting_manifest_are_refused(): void
    {
        foreach (['database.sqlite', 'fixtures.json', 'related-track-fixture-marker.json'] as $name) {
            chmod($this->directory.'/'.$name, 0644);
            $this->refused($this->env, ['prepare']);
            chmod($this->directory.'/'.$name, 0600);
        }
        foreach (['config.php', 'routes.php', 'events.php'] as $name) {
            $this->write($name, '<?php return [];');
            $this->refused($this->env, ['prepare']);
            unlink($this->directory.'/'.$name);
        }
        rename($this->directory.'/app/private', $this->directory.'/app/owned-private');
        symlink($this->directory.'/app/owned-private', $this->directory.'/app/private');
        $this->refused($this->env, ['prepare']);
        unlink($this->directory.'/app/private');
        rename($this->directory.'/app/owned-private', $this->directory.'/app/private');
        $this->write('related-track-fixtures.json', '{}');
        $this->refused($this->env, ['prepare']);
        unlink($this->directory.'/related-track-fixtures.json');
        symlink($this->directory.'/fixtures.json', $this->directory.'/related-track-fixtures.json');
        $this->refused($this->env, ['verify', 'chromium-desktop', 'published']);
    }

    public function test_a_script_impersonating_clamav_is_not_a_native_executable(): void
    {
        $this->write('clamscan', '#!/bin/sh'."\necho 'ClamAV synthetic/1/Thu Oct 1 00:00:00 2026'\n");
        chmod($this->directory.'/clamscan', 0700);
        $method = new ReflectionMethod(\RelatedTrackBrowserFixture::class, 'elf');
        $this->expectException(RuntimeException::class);
        $method->invoke(null, $this->directory.'/clamscan');
    }

    public function test_failure_diagnostics_never_print_private_exception_or_tool_content(): void
    {
        $secret = 'PRIVATE-CUSTOMER-CONTENT /tmp/private.wav SELECT secret FROM orders';
        foreach ([new RuntimeException($secret), new \TypeError($secret), new \Exception($secret)] as $error) {
            $summary = json_decode(\RelatedTrackBrowserFixture::failureSummary($error), true, 8, JSON_THROW_ON_ERROR);
            $this->assertSame(['phase', 'category', 'code', 'exitStatus', 'fields'], array_keys($summary));
            $this->assertContains($summary['category'], ['runtime', 'type', 'unexpected']);
            $this->assertSame('unclassified', $summary['code']);
            $this->assertNull($summary['exitStatus']);
            $this->assertSame([], $summary['fields']);
            $this->assertStringNotContainsString($secret, json_encode($summary, JSON_THROW_ON_ERROR));
        }
        foreach (['processor_failed', 'invalid_tag', 'invalid_artwork', $secret] as $code) {
            $error = new MediaFailure($code, $secret, 2, $secret, $secret);
            $summary = json_decode(\RelatedTrackBrowserFixture::failureSummary($error), true, 8, JSON_THROW_ON_ERROR);
            $this->assertSame('media', $summary['category']);
            $this->assertSame($code === $secret ? 'unclassified' : $code, $summary['code']);
            $this->assertSame(2, $summary['exitStatus']);
            $this->assertStringNotContainsString($secret, json_encode($summary, JSON_THROW_ON_ERROR));
        }
        foreach ([null, -1, 256] as $exitStatus) {
            $summary = json_decode(\RelatedTrackBrowserFixture::failureSummary(new MediaFailure('processor_failed', $secret, $exitStatus)), true, 8, JSON_THROW_ON_ERROR);
            $this->assertNull($summary['exitStatus']);
        }
    }

    public function test_validation_diagnostics_print_only_allowlisted_root_field_names(): void
    {
        $validator = new Validator(new Translator(new ArrayLoader, 'en'), [], []);
        $validator->errors()->add('structured_terms.private_customer_content', 'PRIVATE /tmp/customer.wav');
        $validator->errors()->add('structured_terms.features.0', 'PRIVATE repeated terms');
        $validator->errors()->add('upload', 'PRIVATE uploaded file');
        $validator->errors()->add('private_unknown_field', 'PRIVATE unknown value');
        $summary = json_decode(\RelatedTrackBrowserFixture::failureSummary(new ValidationException($validator)), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('validation', $summary['category']);
        $this->assertSame('unclassified', $summary['code']);
        $this->assertSame(['structured_terms', 'upload'], $summary['fields']);
        foreach (['PRIVATE', 'private_customer_content', '/tmp/', 'features', 'private_unknown_field'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($summary, JSON_THROW_ON_ERROR));
        }
    }

    public function test_retained_media_digest_preserves_fractional_measurements_and_the_complete_proof(): void
    {
        $method = new ReflectionMethod(\RelatedTrackBrowserFixture::class, 'mediaEvidenceHash');
        $proof = ['asset' => ['duration_seconds' => 1.2, 'waveform' => [0.0, 0.125, 0.9375]],
            'source' => ['sha256' => str_repeat('a', 64)], 'run' => ['evidence' => ['source_scan' => ['status' => 'clean']]]];
        $hash = $method->invoke(null, $proof);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $hash);
        $reordered = ['run' => $proof['run'], 'source' => $proof['source'],
            'asset' => ['waveform' => $proof['asset']['waveform'], 'duration_seconds' => 1.2]];
        $this->assertSame($hash, $method->invoke(null, $reordered));
        foreach ([['asset', 'duration_seconds', 1.21], ['asset', 'waveform', [0.0, 0.126, 0.9375]],
            ['asset', 'waveform', [0.9375, 0.125, 0.0]], ['source', 'sha256', str_repeat('b', 64)],
            ['run', 'evidence', ['source_scan' => ['status' => 'unconfirmed']]]] as [$section, $field, $value]) {
            $changed = $proof;
            $changed[$section][$field] = $value;
            $this->assertNotSame($hash, $method->invoke(null, $changed));
        }
        $integer = $proof;
        $integer['asset']['waveform'][0] = 0;
        $this->assertNotSame($hash, $method->invoke(null, $integer));
        foreach ([INF, -INF, NAN, new \stdClass] as $invalid) {
            try {
                $method->invoke(null, ['invalid' => $invalid]);
                $this->fail('Non-finite or non-JSON media evidence was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_missing_duplicated_foreign_subject_and_wrong_actor_audits_are_refused(): void
    {
        $evidence = ['operatorId' => 1, 'reviewerId' => 3, 'license' => ['id' => 1], 'tracks' => [], 'audits' => []];
        $add = function (string $action, string $type, int $id, ?int $actor) use (&$evidence): void {
            $evidence['audits'][] = ['action' => $action, 'subjectType' => $type, 'subjectId' => $id, 'actorId' => $actor];
        };
        $add('access.operator.created', User::class, 3, null);
        foreach (['draft_created', 'review_requested', 'approved', 'published'] as $operation) {
            $add('rights.license.'.$operation, LicenseVersion::class, 1, $operation === 'approved' ? 3 : 1);
        }
        foreach (range(5, 8) as $track) {
            $sources = [];
            foreach (['created', 'published'] as $operation) {
                $add('catalog.track.'.$operation, Track::class, $track, 1);
            }
            $add('rights.declaration.verified', RightsDeclaration::class, $track, 1);
            foreach (['draft_saved', 'revision_published'] as $operation) {
                $add('catalog.offer.'.$operation, Offer::class, $track, 1);
            }
            foreach ([1, 2] as $position) {
                $id = $track * 10 + $position;
                $sources[] = ['id' => $id, 'runId' => $id];
                $add('media.upload.quarantined', MediaAsset::class, $id, 1);
                foreach (['queued', 'completed'] as $operation) {
                    $add('media.processing.'.$operation, MediaProcessingRun::class, $id, 1);
                }
            }
            $evidence['tracks'][] = ['trackId' => $track, 'rightsId' => $track, 'offerId' => $track, 'sources' => $sources];
        }
        $method = new ReflectionMethod(\RelatedTrackBrowserFixture::class, 'auditCensus');
        $method->invoke(null, $evidence);
        $this->assertCount(49, $evidence['audits']);
        $cases = [];
        $missing = $evidence;
        array_pop($missing['audits']);
        $cases[] = $missing;
        $duplicate = $evidence;
        $duplicate['audits'][48] = $duplicate['audits'][47];
        $cases[] = $duplicate;
        $wrongActor = $evidence;
        $wrongActor['audits'][3]['actorId'] = 1;
        $cases[] = $wrongActor;
        $foreign = $evidence;
        $foreign['audits'][1]['subjectId'] = 999;
        $cases[] = $foreign;
        foreach ($cases as $case) {
            try {
                $method->invoke(null, $case);
                $this->fail('Incomplete or foreign command evidence was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function refused(array $env, array $arguments): void
    {
        $before = $this->snapshot();
        try {
            \RelatedTrackBrowserFixture::guard($env, $arguments);
            $this->fail('The hostile fixture boundary was accepted.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        return [file_get_contents($this->directory.'/database.sqlite'), file_get_contents($this->directory.'/fixtures.json')];
    }

    private function marker(array $changes = []): void
    {
        $this->write('related-track-fixture-marker.json', json_encode(array_replace(['marker' => $this->env['VASEY_BROWSER_RELATED_MARKER'],
            'database' => $this->directory.'/database.sqlite', 'origin' => 'http://127.0.0.1:8173', 'operatorId' => 1], $changes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function write(string $name, string $bytes): void
    {
        file_put_contents($this->directory.'/'.$name, $bytes);
        chmod($this->directory.'/'.$name, 0600);
    }
}
