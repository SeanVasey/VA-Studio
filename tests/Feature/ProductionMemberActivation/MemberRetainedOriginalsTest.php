<?php

namespace Tests\Feature\ProductionMemberActivation;

use App\Domain\Grants\Member\MemberActivatedOriginal;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalBudget;
use App\Domain\Grants\Member\MemberOriginalFiles;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use App\Domain\Grants\Member\MemberRetainedOriginalObservation;
use App\Domain\Grants\Member\MemberRetainedOriginals;
use App\Support\CanonicalJson;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberOriginalRendererFixtures;
use Tests\TestCase;

/** Native read-only synthetic envelope checks. No PDF rendering, activation, host or DB graph claim. */
class MemberRetainedOriginalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        chmod($this->root(), 0700);
        DB::connection()->getPdo();
    }

    private function root(): string
    {
        return rtrim(config('filesystems.disks.local.root'), '/');
    }

    private function bytes(): string
    {
        return "%PDF-1.7\nNONBINDING synthetic retained envelope\n%%EOF\n";
    }

    private function parts(?string $bytes = null): array
    {
        $bytes ??= $this->bytes();
        $source = MemberOriginalRendererFixtures::sourceDocument();
        $artifact = ['schema_version' => 'member-original-artifact-v1', 'family' => $source['family'],
            'purpose' => $source['purpose'], 'provenance' => $source['provenance'],
            'artifact_id' => 'c5ed2671-e901-4dcf-a607-8dcbcd95219e', 'origin_id' => $source['origin_id'],
            'ordinal' => 0, 'role' => 'license_pdf', 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes),
            'storage_policy_hash' => CanonicalJson::hash($source['definition']['document']['storage_policy']),
            'render_source_hash' => CanonicalJson::hash($source),
            'profile_hash' => CanonicalJson::hash($source['profile']['document']['render_profile']),
            'derived_relative_path' => 'contracts/production-member-v1/'.$source['provenance'].'/'.$source['origin_id'].'/'.CanonicalJson::hash($source).'/original.pdf',
            'created_at' => '2026-10-13 11:00:00'];
        $manifest = ['schema_version' => 'member-original-artifact-manifest-v1', 'family' => $source['family'],
            'purpose' => $source['purpose'], 'origin_id' => $source['origin_id'],
            'originals' => [['role' => 'license_pdf', 'sha256' => $artifact['sha256'], 'bytes' => $artifact['bytes'],
                'storage_policy_hash' => $artifact['storage_policy_hash']]]];
        $identities = ['origin_id' => $source['origin_id'], 'redemption_id' => $source['redemption']['id'],
            'definition_id' => $source['definition']['id'], 'consume_event_id' => 'a75ba61f-f382-468a-9b22-54408e44b453',
            'activation_id' => '71c6ca48-0576-4ec9-9d54-94917e481e8f', 'artifact_manifest_hash' => CanonicalJson::hash($manifest),
            'activation_hash' => hash('sha256', 'NONBINDING synthetic activation document digest')];

        return [$source, $artifact, $identities];
    }

    private function original(?string $bytes = null): MemberActivatedOriginal
    {
        return MemberActivatedOriginal::from(...$this->parts($bytes));
    }

    private function place(MemberActivatedOriginal $original, ?string $bytes = null): string
    {
        $path = $this->root().'/'.$original->artifact()['derived_relative_path'];
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, $bytes ?? $this->bytes());
        chmod($path, 0400);

        return $path;
    }

    private function failure(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid retained-original operation succeeded.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    public function test_retained_read_observes_existing_bytes_without_page_count_or_current_approval(): void
    {
        config(['member-grants.enabled' => false, 'member-grants.approved_profile_hash' => null]);
        $original = $this->original();
        $path = $this->place($original);
        $before = lstat($path);
        $reader = new MemberRetainedOriginals(new MemberOriginalFiles);
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $observation = $reader->observe($original, $idle, $budget);
        $this->assertSame($original->artifact()['profile_hash'], $observation->profileHash);
        $this->assertNotSame(CanonicalJson::hash($original->source()->document()['profile']['document']), $observation->profileHash);
        $this->assertSame($before['ino'], $observation->inode);
        $this->assertSame($before['dev'], $observation->device);
        $this->assertFalse(property_exists($observation, 'pageCount'));
        $this->assertSame($observation->observationStartedNs + 60_000_000_000, $budget->deadlineNs());
        $reader->verify($original, $observation, $idle, $budget);
        $reader->assertCurrent($original, $observation, $budget);
        $this->assertSame($observation->observationStartedNs + 60_000_000_000, $budget->deadlineNs());
        clearstatcache(true, $path);
        $after = lstat($path);
        foreach (['ino', 'dev', 'mtime', 'ctime', 'mode', 'nlink', 'size'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertSame($this->bytes(), file_get_contents($path));
        $this->assertSame(['original.pdf'], array_values(array_diff(scandir(dirname($path)), ['.', '..'])));
    }

    public function test_package_detaches_nested_source_and_scalar_references_and_returned_values(): void
    {
        [$source, $artifact, $ids] = $this->parts();
        $text = $source['profile']['document']['original_terms']['authored_source'];
        $source['profile']['document']['original_terms']['authored_source'] = &$text;
        $hash = $artifact['sha256'];
        $artifact['sha256'] = &$hash;
        $activation = $ids['activation_id'];
        $ids['activation_id'] = &$activation;
        $original = MemberActivatedOriginal::from($source, $artifact, $ids);
        $expectedSource = $original->source()->document();
        $expectedArtifact = $original->artifact();
        $expectedIds = $original->identities();
        $text = 'changed caller';
        $hash = str_repeat('f', 64);
        $activation = '12b7e805-8566-464b-b383-b09c401da2a8';
        $copy = $original->artifact();
        $copy['sha256'] = 'changed result';
        $copy = $original->identities();
        $copy['activation_id'] = 'changed result';
        $copy = $original->source()->document();
        $copy['profile']['document']['original_terms']['authored_source'] = 'changed result';
        $this->assertSame($expectedSource, $original->source()->document());
        $this->assertSame($expectedArtifact, $original->artifact());
        $this->assertSame($expectedIds, $original->identities());
    }

    public static function invalidPackages(): array
    {
        return array_map(fn ($case) => [$case], ['extra', 'missing', 'string_bytes', 'boolean_ordinal', 'oversize',
            'bad_uuid', 'bad_date', 'path', 'source', 'full_profile', 'storage', 'origin', 'definition', 'manifest']);
    }

    #[DataProvider('invalidPackages')]
    public function test_package_rejects_mismatched_or_coercible_retained_values(string $case): void
    {
        [$source, $artifact, $ids] = $this->parts();
        match ($case) {
            'extra' => $artifact['pageCount'] = 1,
            'missing' => null,
            'string_bytes' => $artifact['bytes'] = (string) $artifact['bytes'],
            'boolean_ordinal' => $artifact['ordinal'] = false,
            'oversize' => $artifact['bytes'] = MemberOriginalFiles::MAX_BYTES + 1,
            'bad_uuid' => $artifact['artifact_id'] = 1,
            'bad_date' => $artifact['created_at'] = '2026-02-30 11:00:00',
            'path' => $artifact['derived_relative_path'] = '../original.pdf',
            'source' => $artifact['render_source_hash'] = str_repeat('f', 64),
            'full_profile' => $artifact['profile_hash'] = CanonicalJson::hash($source['profile']['document']),
            'storage' => $artifact['storage_policy_hash'] = str_repeat('f', 64),
            'origin' => $ids['redemption_id'] = $artifact['artifact_id'],
            'definition' => $ids['definition_id'] = $artifact['artifact_id'],
            'manifest' => $ids['artifact_manifest_hash'] = str_repeat('f', 64),
        };
        if ($case === 'missing') {
            unset($artifact['created_at']);
        }
        $this->failure('retained_original_value', fn () => MemberActivatedOriginal::from($source, $artifact, $ids));
    }

    public static function changedFiles(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'changed', 'truncated', 'symlink', 'hardlink', 'writable', 'replacement']);
    }

    #[DataProvider('changedFiles')]
    public function test_changed_native_original_is_refused_without_repair_and_retires_observation(string $case): void
    {
        $original = $this->original();
        $path = $this->place($original);
        $reader = new MemberRetainedOriginals(new MemberOriginalFiles);
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $observation = $reader->observe($original, $idle, $budget);
        if ($case === 'missing') {
            unlink($path);
        } elseif ($case === 'changed' || $case === 'truncated') {
            chmod($path, 0600);
            file_put_contents($path, $case === 'truncated' ? '%PDF-' : str_replace('synthetic', 'SYNTHETIC', $this->bytes()));
            chmod($path, 0400);
        } elseif ($case === 'symlink' || $case === 'replacement') {
            rename($path, $path.'.old');
            if ($case === 'symlink') {
                symlink($path.'.old', $path);
            } else {
                file_put_contents($path, $this->bytes());
                chmod($path, 0400);
            }
        } elseif ($case === 'hardlink') {
            link($path, $path.'.extra');
        } else {
            chmod($path, 0600);
        }
        $before = is_file($path) ? file_get_contents($path) : null;
        $this->failure('original_unavailable', fn () => $reader->verify($original, $observation, $idle, $budget));
        $this->failure('foreign_retained_observation', fn () => $reader->assertCurrent($original, $observation, $budget));
        $this->assertSame($before, is_file($path) ? file_get_contents($path) : null);
        $this->assertSame([], glob(dirname($path).'/.candidate-*.tmp'));
    }

    public static function absentPaths(): array
    {
        return [['root'], ['directory'], ['file']];
    }

    #[DataProvider('absentPaths')]
    public function test_absent_historical_path_is_never_created(string $missing): void
    {
        $original = $this->original();
        $root = $this->root();
        $path = $this->place($original);
        $absent = match ($missing) {
            'root' => $root, 'directory' => dirname($path), 'file' => $path,
        };
        if ($missing === 'file') {
            unlink($absent);
        } else {
            (new Filesystem)->deleteDirectory($absent);
        }
        $reader = new MemberRetainedOriginals(new MemberOriginalFiles);
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $this->failure('original_unavailable', fn () => $reader->observe($original, $idle, $budget));
        $this->assertFileDoesNotExist($absent);
    }

    public function test_pdf_framing_is_checked_even_when_retained_size_and_digest_agree(): void
    {
        $bytes = str_repeat('not a PDF; ', 10);
        $original = $this->original($bytes);
        $path = $this->place($original, $bytes);
        $reader = new MemberRetainedOriginals(new MemberOriginalFiles);
        $this->failure('original_unavailable', fn () => $reader->observe($original, MemberOriginalIoFrame::captureIdle(), MemberOriginalBudget::start()));
        $this->assertSame($bytes, file_get_contents($path));
    }

    public function test_only_exact_adapter_original_observation_and_budget_are_registered(): void
    {
        $original = $this->original();
        $this->place($original);
        $files = new MemberOriginalFiles;
        $reader = new MemberRetainedOriginals($files);
        $idle = MemberOriginalIoFrame::captureIdle();
        $budget = MemberOriginalBudget::start();
        $observation = $reader->observe($original, $idle, $budget);
        $forged = new MemberRetainedOriginalObservation(...array_values(get_object_vars($observation)));
        foreach ([$forged, clone $observation, $files->readRetained($original, $idle, $budget)] as $unregistered) {
            $this->failure('foreign_retained_observation', fn () => $reader->assertCurrent($original, $unregistered, $budget));
        }
        $this->failure('foreign_retained_observation', fn () => (new MemberRetainedOriginals($files))->assertCurrent($original, $observation, $budget));
        $this->failure('foreign_retained_observation', fn () => $reader->assertCurrent($this->original(), $observation, $budget));
        $this->failure('foreign_retained_observation', fn () => $reader->assertCurrent($original, $observation, MemberOriginalBudget::start()));
        $this->failure('private_retained_observer', fn () => clone $reader);
        $this->failure('private_retained_observer', fn () => serialize($reader));
        $reader->assertCurrent($original, $observation, $budget);
    }

    public function test_terminal_check_uses_no_container_or_filesystem_and_preserves_shorter_original_budget(): void
    {
        $original = $this->original();
        $path = $this->place($original);
        $reader = new MemberRetainedOriginals(new MemberOriginalFiles);
        $budget = MemberOriginalBudget::start();
        $deadline = hrtime(true) + 30_000_000_000;
        $budget->shortenTo($deadline);
        $observation = $reader->observe($original, MemberOriginalIoFrame::captureIdle(), $budget);
        $this->assertSame($deadline, $budget->deadlineNs());
        unlink($path);
        $container = Container::getInstance();
        Container::setInstance(new Container);
        try {
            $reader->assertCurrent($original, $observation, $budget);
            $this->assertSame($deadline, $budget->deadlineNs());
        } finally {
            Container::setInstance($container);
        }
        $budget->shortenTo(hrtime(true) - 1);
        $this->failure('workflow_deadline', fn () => $reader->assertCurrent($original, $observation, $budget));
    }

    public function test_read_callback_that_changes_configuration_is_refused_without_modifying_original(): void
    {
        $original = $this->original();
        $path = $this->place($original);
        $reader = new MemberRetainedOriginals(new MemberOriginalFiles);
        $idle = MemberOriginalIoFrame::captureIdle();
        $GLOBALS['member_retained_after_native_read'] = static fn () => config(['filesystems.disks.local.serve' => true]);
        try {
            $this->failure('original_unavailable', fn () => $reader->observe($original, $idle, MemberOriginalBudget::start()));
        } finally {
            unset($GLOBALS['member_retained_after_native_read']);
        }
        $this->assertSame($this->bytes(), file_get_contents($path));
    }
}

namespace App\Domain\Grants\Member;

/** Read real native bytes first; one explicit fixture callback exercises the existing idle barrier. */
function stream_get_contents($stream, ?int $length = null, int $offset = -1): string|false
{
    $bytes = \stream_get_contents($stream, $length, $offset);
    $callback = $GLOBALS['member_retained_after_native_read'] ?? null;
    if ($callback !== null) {
        unset($GLOBALS['member_retained_after_native_read']);
        $callback();
    }

    return $bytes;
}
