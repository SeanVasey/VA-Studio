<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\ReadTrackPublicationManifest;
use App\Domain\Rights\CollaboratorSplitReport;
use App\Domain\Rights\CollaboratorSplits;
use App\Domain\Rights\Models\Collaborator;
use App\Domain\Rights\Models\TrackCollaboratorSplit;
use App\Filament\Resources\CollaboratorResource\Pages\ManageCollaborators;
use App\Filament\Resources\TrackCollaboratorSplitResource\Pages\ManageTrackCollaboratorSplits;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** BL-05 (FP-035). Every name, reference and share below is synthetic test data, not a real credit or agreed split. */
class CollaboratorSplitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function track(string $title = 'Synthetic split track'): Track
    {
        return Track::create(['title' => $title, 'slug' => 'synthetic-split-'.Str::uuid()]);
    }

    private function collaborator(User $actor, string $name = 'Synthetic Collaborator A', string $role = 'producer'): Collaborator
    {
        return app(CollaboratorSplits::class)->saveCollaborator(null,
            ['display_name' => $name, 'role' => $role, 'source_reference' => 'SYNTHETIC-TEST-REF'], $actor);
    }

    private function split(User $actor, Track $track, Collaborator $collaborator, string $type, int $bps): TrackCollaboratorSplit
    {
        return app(CollaboratorSplits::class)->addSplit(['track_id' => $track->id, 'collaborator_id' => $collaborator->id,
            'split_type' => $type, 'share_bps' => $bps], $actor);
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['collaborators', 'track_collaborator_splits', 'audit_events']);
    }

    private function rejects(callable $operation, string $field): void
    {
        $before = $this->evidence();
        try {
            $operation();
            $this->fail('An invalid collaborator split operation was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertSame($before, $this->evidence(), 'A refused operation must write nothing.');
    }

    private function refusedByDatabase(callable $write, string ...$messages): void
    {
        $before = $this->evidence();
        try {
            DB::transaction($write); // A savepoint keeps the surrounding test transaction usable on every engine.
            $this->fail('The database accepted an invalid collaborator split row.');
        } catch (QueryException $exception) {
            $this->assertNotSame([], array_filter($messages, fn (string $message): bool => str_contains($exception->getMessage(), $message)), $exception->getMessage());
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_every_change_records_exactly_one_audit_event_and_a_no_op_records_none(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(CollaboratorSplits::class);
        $collaborator = $this->collaborator($actor);
        $this->assertSame(['rights.collaborator.created'], AuditEvent::orderBy('id')->pluck('action')->all());
        $created = AuditEvent::sole();
        $this->assertSame($actor->id, $created->actor_id);
        $this->assertSame([Collaborator::class, $collaborator->id], [$created->subject_type, $created->subject_id]);
        $this->assertSame(['display_name', 'role', 'source_reference'], $created->context['changed_fields']);
        $this->assertNull($created->context['before_hash']);
        $this->assertStringNotContainsString('Synthetic Collaborator', json_encode($created->context));

        $same = ['display_name' => 'Synthetic Collaborator A', 'role' => 'producer', 'source_reference' => 'SYNTHETIC-TEST-REF'];
        $command->saveCollaborator($collaborator, $same, $actor);
        $this->assertSame(1, AuditEvent::count(), 'An unchanged save is not a change.');
        $command->saveCollaborator($collaborator, ['role' => 'writer'] + $same, $actor);
        $updated = AuditEvent::where('action', 'rights.collaborator.updated')->sole();
        $this->assertSame(['role'], $updated->context['changed_fields']);
        $this->assertNotSame($updated->context['before_hash'], $updated->context['after_hash']);
        $this->assertSame('writer', $collaborator->fresh()->role->value);

        $track = $this->track();
        $split = $this->split($actor, $track, $collaborator, 'master', 4000);
        $added = AuditEvent::where('action', 'rights.collaborator_split.added')->sole();
        $this->assertSame(['schema_version' => 1, 'track_id' => $track->id, 'collaborator_id' => $collaborator->id, 'split_type' => 'master',
            'before_share_bps' => null, 'after_share_bps' => 4000, 'set_total_bps' => 4000, 'set_complete' => false], $added->context);
        $this->assertSame([TrackCollaboratorSplit::class, $split->id, $actor->id], [$added->subject_type, $added->subject_id, $added->actor_id]);

        $command->updateShare($split, 4000, 4000, $actor);
        $this->assertSame(0, AuditEvent::where('action', 'rights.collaborator_split.updated')->count(), 'An unchanged share is not a change.');
        $command->updateShare($split, 4000, 10000, $actor);
        $changed = AuditEvent::where('action', 'rights.collaborator_split.updated')->sole();
        $this->assertSame([4000, 10000, 10000, true], [$changed->context['before_share_bps'], $changed->context['after_share_bps'],
            $changed->context['set_total_bps'], $changed->context['set_complete']]);

        $command->removeSplit($split, 10000, $actor);
        $removed = AuditEvent::where('action', 'rights.collaborator_split.removed')->sole();
        $this->assertSame([10000, null, 0, false], [$removed->context['before_share_bps'], $removed->context['after_share_bps'],
            $removed->context['set_total_bps'], $removed->context['set_complete']]);
        $this->assertSame($split->id, $removed->subject_id);
        $this->assertDatabaseCount('track_collaborator_splits', 0);
        $this->assertSame(5, AuditEvent::count(), 'Five changes, five events.');
    }

    public function test_a_set_is_complete_only_at_exactly_10000_basis_points_per_split_type(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track();
        $other = $this->track('Synthetic other track');
        $report = app(CollaboratorSplitReport::class);
        $empty = ['status' => 'incomplete', 'total_bps' => 0, 'collaborators' => 0];
        $this->assertSame(['schema_version' => 1, 'complete' => false, 'types' => ['publishing' => $empty, 'master' => $empty]], $report->forTrack($track->id));

        $a = $this->collaborator($actor);
        $b = $this->collaborator($actor, 'Synthetic Collaborator B', 'writer');
        $first = $this->split($actor, $track, $a, 'master', 6000);
        $this->split($actor, $track, $b, 'master', 3999);
        $this->assertSame(['status' => 'incomplete', 'total_bps' => 9999, 'collaborators' => 2], $report->forTrack($track->id)['types']['master']);
        app(CollaboratorSplits::class)->updateShare($first, 6000, 6001, $actor);
        $state = $report->forTrack($track->id);
        $this->assertSame(['status' => 'complete', 'total_bps' => 10000, 'collaborators' => 2], $state['types']['master']);
        $this->assertFalse($state['complete'], 'Publishing is its own set and is still incomplete.');
        $this->assertCount(1, $report->advisories($track->id));
        $this->assertStringContainsString('publishing collaborator splits are incomplete (0 of 10000', $report->advisories($track->id)[0]);

        // The same collaborator may hold a share of each type; the types never add together.
        $this->split($actor, $track, $a, 'publishing', 10000);
        $this->assertTrue($report->forTrack($track->id)['complete']);
        $this->assertSame([], $report->advisories($track->id));
        $this->assertFalse($report->forTrack($other->id)['complete'], 'Another track has its own sets.');
    }

    public function test_the_service_refuses_invalid_values_duplicates_stale_shares_and_totals_above_10000(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(CollaboratorSplits::class);
        $track = $this->track();
        $a = $this->collaborator($actor);
        $b = $this->collaborator($actor, 'Synthetic Collaborator B', 'performer');
        $split = $this->split($actor, $track, $a, 'master', 7000);
        $valid = ['track_id' => $track->id, 'collaborator_id' => $b->id, 'split_type' => 'master', 'share_bps' => 3000];

        foreach ([0, -1, 10001, 2500.5, '25%', '0100', null, true, [2500]] as $share) {
            $this->rejects(fn () => $command->addSplit(['share_bps' => $share] + $valid, $actor), 'share_bps');
            $this->rejects(fn () => $command->updateShare($split, 7000, $share, $actor), 'share_bps');
        }
        $this->rejects(fn () => $command->addSplit(['share_bps' => 3001] + $valid, $actor), 'share_bps'); // 7000 + 3001
        $this->rejects(fn () => $command->addSplit(['collaborator_id' => $a->id] + $valid, $actor), 'collaborator_id'); // duplicate
        $this->rejects(fn () => $command->addSplit(['split_type' => 'sync'] + $valid, $actor), 'split_type');
        $this->rejects(fn () => $command->addSplit(['track_id' => $track->id + 999] + $valid, $actor), 'track_id');
        $this->rejects(fn () => $command->addSplit(['collaborator_id' => $b->id + 999] + $valid, $actor), 'collaborator_id');
        $this->rejects(fn () => $command->addSplit($valid + ['payout_account' => 'refused'], $actor), 'share_bps');
        $this->rejects(fn () => $command->addSplit(array_diff_key($valid, ['split_type' => true]), $actor), 'share_bps');

        $second = $command->addSplit($valid, $actor); // 7000 + 3000 is exactly complete
        $this->rejects(fn () => $command->updateShare($second, 3000, 3001, $actor), 'share_bps');
        $this->rejects(fn () => $command->updateShare($second, 2999, 2000, $actor), 'share_bps'); // stale expected share
        $this->rejects(fn () => $command->removeSplit($second, 2999, $actor), 'share_bps');
        $command->removeSplit($second, 3000, $actor);
        $this->rejects(fn () => $command->updateShare($second, 3000, 1000, $actor), 'share_bps'); // already removed
        $this->rejects(fn () => $command->removeSplit($second, 3000, $actor), 'share_bps');

        $person = ['display_name' => 'Synthetic Collaborator C', 'role' => 'writer', 'source_reference' => null];
        $this->rejects(fn () => $command->saveCollaborator(null, ['display_name' => '   '] + $person, $actor), 'display_name');
        $this->rejects(fn () => $command->saveCollaborator(null, ['display_name' => str_repeat('n', 256)] + $person, $actor), 'display_name');
        $this->rejects(fn () => $command->saveCollaborator(null, ['display_name' => "Line\nbreak"] + $person, $actor), 'display_name');
        $this->rejects(fn () => $command->saveCollaborator(null, ['role' => 'publisher'] + $person, $actor), 'role');
        $this->rejects(fn () => $command->saveCollaborator(null, $person + ['tax_id' => 'refused'], $actor), 'display_name');
        $this->rejects(fn () => $command->saveCollaborator(null, ['source_reference' => 'reach synthetic@example.test'] + $person, $actor), 'source_reference');
        $this->rejects(fn () => $command->saveCollaborator(null, ['display_name' => 'synthetic@example.test'] + $person, $actor), 'display_name');
        $this->assertNull($command->saveCollaborator(null, ['source_reference' => '  '] + $person, $actor)->source_reference);
    }

    public function test_the_database_itself_refuses_invalid_rows_when_the_service_is_bypassed(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track();
        $a = $this->collaborator($actor);
        $b = $this->collaborator($actor, 'Synthetic Collaborator B', 'writer');
        $split = $this->split($actor, $track, $a, 'master', 7000);
        $row = ['track_id' => $track->id, 'collaborator_id' => $b->id, 'split_type' => 'master', 'share_bps' => 3000,
            'created_at' => now(), 'updated_at' => now()];
        $insert = fn (array $overrides) => fn () => DB::table('track_collaborator_splits')->insert($overrides + $row);
        $update = fn (array $values) => fn () => DB::table('track_collaborator_splits')->where('id', $split->id)->update($values);
        $invalid = 'Collaborator split record is invalid or its identity is immutable';
        $over = 'Collaborator splits for one track and type cannot exceed 10000 basis points';

        $this->refusedByDatabase($insert(['share_bps' => 0]), $invalid);
        // 10001 is both out of range and above the total; SQLite does not define which guard reports first.
        $this->refusedByDatabase($insert(['share_bps' => 10001, 'split_type' => 'publishing']), $invalid, $over);
        $this->refusedByDatabase($insert(['split_type' => 'sync']), $invalid);
        $this->refusedByDatabase($insert(['split_type' => 'MASTER']), $invalid);
        $this->refusedByDatabase($insert(['share_bps' => 3001]), $over);
        $this->refusedByDatabase($insert(['collaborator_id' => $a->id, 'share_bps' => 1]), 'track_collaborator_splits_member_unique', 'UNIQUE constraint failed: track_collaborator_splits.track_id');
        $this->refusedByDatabase($update(['share_bps' => 0]), $invalid);
        $this->refusedByDatabase($update(['share_bps' => 10001]), $invalid, $over);
        $this->refusedByDatabase($update(['split_type' => 'publishing']), $invalid);
        $this->refusedByDatabase($update(['track_id' => $this->track('Synthetic second track')->id]), $invalid);
        $this->refusedByDatabase($update(['collaborator_id' => $b->id]), $invalid);

        DB::table('track_collaborator_splits')->insert($row); // exactly 10000 is accepted
        $this->refusedByDatabase($update(['share_bps' => 7001]), $over);
        DB::table('track_collaborator_splits')->where('id', $split->id)->update(['share_bps' => 10]); // lowering is accepted
        $this->assertSame(3010, (int) DB::table('track_collaborator_splits')->where('track_id', $track->id)->sum('share_bps'));

        $person = ['display_name' => 'Synthetic Collaborator C', 'role' => 'writer', 'created_at' => now(), 'updated_at' => now()];
        $this->refusedByDatabase(fn () => DB::table('collaborators')->insert(['role' => 'publisher'] + $person), $invalid);
        $this->refusedByDatabase(fn () => DB::table('collaborators')->insert(['display_name' => '  '] + $person), $invalid);
        $this->refusedByDatabase(fn () => DB::table('collaborators')->where('id', $a->id)->update(['role' => 'payee']), $invalid);
        // A referenced collaborator or track cannot be deleted out from under its shares.
        foreach (['collaborators' => $a->id, 'tracks' => $track->id] as $table => $id) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
                $this->fail('A referenced '.$table.' row was deleted.');
            } catch (QueryException) {
            }
        }
        $this->assertSame(['id', 'display_name', 'role', 'source_reference', 'created_at', 'updated_at'], DB::getSchemaBuilder()->getColumnListing('collaborators'),
            'The collaborator record holds no payment, tax or contact column.');
        $this->assertSame(['id', 'track_id', 'collaborator_id', 'split_type', 'share_bps', 'created_at', 'updated_at'],
            DB::getSchemaBuilder()->getColumnListing('track_collaborator_splits'));
    }

    public static function withdrawals(): array
    {
        return ['staff role' => ['is_admin', false], 'email verification' => ['email_verified_at', null]];
    }

    #[DataProvider('withdrawals')]
    public function test_non_staff_are_forbidden_by_the_service_and_by_both_editor_pages(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track();
        $collaborator = $this->collaborator($actor);
        $split = $this->split($actor, $track, $collaborator, 'master', 5000);
        $this->actingAs($actor);
        $add = Livewire::test(ManageTrackCollaboratorSplits::class)->mountAction('create')
            ->setActionData(['track_id' => $track->id, 'collaborator_id' => $collaborator->id, 'split_type' => 'publishing', 'share_bps' => 100]);
        $edit = Livewire::test(ManageTrackCollaboratorSplits::class)->mountTableAction('edit', $split)->setTableActionData(['share_bps' => 100]);
        $remove = Livewire::test(ManageTrackCollaboratorSplits::class)->mountTableAction('remove', $split);
        $person = Livewire::test(ManageCollaborators::class)->mountAction('create')->setActionData(['display_name' => 'Denied', 'role' => 'writer']);

        User::whereKey($actor->id)->update([$field => $value]);
        $customer = User::factory()->create();
        $before = $this->evidence();
        $command = app(CollaboratorSplits::class);
        foreach ([$actor, $customer] as $denied) {
            foreach ([
                fn () => $command->saveCollaborator(null, ['display_name' => 'Denied', 'role' => 'writer', 'source_reference' => null], $denied),
                fn () => $command->saveCollaborator($collaborator, ['display_name' => 'Denied', 'role' => 'writer', 'source_reference' => null], $denied),
                fn () => $command->addSplit(['track_id' => $track->id, 'collaborator_id' => $collaborator->id, 'split_type' => 'publishing', 'share_bps' => 100], $denied),
                fn () => $command->updateShare($split, 5000, 100, $denied),
                fn () => $command->removeSplit($split, 5000, $denied),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('A user without current staff authority changed collaborator splits.');
                } catch (AuthorizationException) {
                }
            }
        }
        $add->callMountedAction()->assertForbidden();
        $edit->callMountedTableAction()->assertForbidden();
        $remove->callMountedTableAction()->assertForbidden();
        $person->callMountedAction()->assertForbidden();
        $this->get('/admin/collaborators')->assertForbidden();
        $this->get('/admin/track-collaborator-splits')->assertForbidden();
        $this->actingAs($customer)->get('/admin/collaborators')->assertForbidden();
        $this->actingAs($customer)->get('/admin/track-collaborator-splits')->assertForbidden();
        $this->assertSame($before, $this->evidence());
    }

    public function test_enrolled_mfa_is_required_by_the_service_the_pages_and_every_mounted_action(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->track();
        $collaborator = $this->collaborator($actor);
        $split = $this->split($actor, $track, $collaborator, 'master', 5000);
        $this->actingAs($actor);
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $before = $this->evidence();
            $command = app(CollaboratorSplits::class);
            $operations = [
                fn () => $command->saveCollaborator(null, ['display_name' => 'Denied', 'role' => 'writer', 'source_reference' => null], $actor),
                fn () => $command->addSplit(['track_id' => $track->id, 'collaborator_id' => $collaborator->id, 'split_type' => 'publishing', 'share_bps' => 100], $actor),
                fn () => $command->updateShare($split, 5000, 100, $actor),
                fn () => $command->removeSplit($split, 5000, $actor),
            ];
            // Staff without an enrolled factor: refused everywhere.
            foreach ($operations as $operation) {
                try {
                    $operation();
                    $this->fail('Staff without enrolled MFA changed collaborator splits.');
                } catch (AuthorizationException $exception) {
                    $this->assertSame('Admin multi-factor authentication is required.', $exception->getMessage());
                }
            }
            Livewire::test(ManageCollaborators::class)->assertForbidden();
            Livewire::test(ManageTrackCollaboratorSplits::class)->assertForbidden();
            $this->assertSame($before, $this->evidence());

            // Enrolled: allowed. Enrollment removed after a form was opened: the submit is refused.
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            Livewire::test(ManageCollaborators::class)->assertOk();
            $add = Livewire::test(ManageTrackCollaboratorSplits::class)->mountAction('create')
                ->setActionData(['track_id' => $track->id, 'collaborator_id' => $collaborator->id, 'split_type' => 'publishing', 'share_bps' => 100]);
            $edit = Livewire::test(ManageTrackCollaboratorSplits::class)->mountTableAction('edit', $split)->setTableActionData(['share_bps' => 100]);
            $remove = Livewire::test(ManageTrackCollaboratorSplits::class)->mountTableAction('remove', $split);
            $person = Livewire::test(ManageCollaborators::class)->mountAction('create')->setActionData(['display_name' => 'Denied', 'role' => 'writer']);
            User::findOrFail($actor->id)->saveAppAuthenticationSecret(null);
            $add->callMountedAction()->assertForbidden();
            $edit->callMountedTableAction()->assertForbidden();
            $remove->callMountedTableAction()->assertForbidden();
            $person->callMountedAction()->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_the_filament_editor_writes_through_the_service_and_shows_its_refusals(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $track = $this->track();
        Livewire::test(ManageCollaborators::class)->callAction('create', data: ['display_name' => 'Synthetic UI Collaborator', 'role' => 'producer',
            'source_reference' => 'SYNTHETIC-UI-REF'])->assertHasNoActionErrors();
        $collaborator = Collaborator::sole();
        Livewire::test(ManageCollaborators::class)->callTableAction('edit', $collaborator, data: ['role' => 'performer'])->assertHasNoTableActionErrors();
        $this->assertSame('performer', $collaborator->fresh()->role->value);
        Livewire::test(ManageCollaborators::class)->callAction('create', data: ['display_name' => 'synthetic@example.test', 'role' => 'writer'])
            ->assertHasActionErrors(['display_name']);

        $data = ['track_id' => $track->id, 'collaborator_id' => $collaborator->id, 'split_type' => 'master', 'share_bps' => 6000];
        Livewire::test(ManageTrackCollaboratorSplits::class)->callAction('create', data: $data)->assertHasNoActionErrors();
        $split = TrackCollaboratorSplit::sole();
        $this->assertSame(6000, $split->share_bps);
        Livewire::test(ManageTrackCollaboratorSplits::class)->callAction('create', data: $data)->assertHasActionErrors(['collaborator_id']);
        Livewire::test(ManageTrackCollaboratorSplits::class)->callAction('create', data: ['share_bps' => 10001] + $data)->assertHasActionErrors(['share_bps']);
        Livewire::test(ManageTrackCollaboratorSplits::class)->assertSee('Incomplete')->assertSee('6000');

        // A stale editor never overwrites the share another editor saved meanwhile.
        $stale = Livewire::test(ManageTrackCollaboratorSplits::class)->mountTableAction('edit', $split)->setTableActionData(['share_bps' => 1]);
        $staleRemove = Livewire::test(ManageTrackCollaboratorSplits::class)->mountTableAction('remove', $split)->assertSet('expectedShareBps', 6000);
        Livewire::test(ManageTrackCollaboratorSplits::class)->callTableAction('edit', $split, data: ['share_bps' => 10000])->assertHasNoTableActionErrors();
        $stale->callMountedTableAction()->assertHasTableActionErrors(['share_bps']);
        $staleRemove->callMountedTableAction();
        $this->assertSame(10000, $split->fresh()->share_bps);
        Livewire::test(ManageTrackCollaboratorSplits::class)->assertSee('Complete');

        Livewire::test(ManageTrackCollaboratorSplits::class)->callTableAction('remove', $split->fresh())->assertHasNoTableActionErrors();
        $this->assertDatabaseCount('track_collaborator_splits', 0);
        $this->assertSame(['rights.collaborator.created', 'rights.collaborator.updated', 'rights.collaborator_split.added',
            'rights.collaborator_split.updated', 'rights.collaborator_split.removed'], AuditEvent::orderBy('id')->pluck('action')->all());
        $this->assertSame([$actor->id], AuditEvent::distinct()->pluck('actor_id')->all());
    }

    public function test_every_write_rolls_back_when_its_audit_event_cannot_be_recorded(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(CollaboratorSplits::class);
        $track = $this->track();
        $collaborator = $this->collaborator($actor);
        $other = $this->collaborator($actor, 'Synthetic Collaborator B', 'writer');
        $split = $this->split($actor, $track, $collaborator, 'master', 5000);
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic split audit failure'));
        foreach ([
            fn () => $command->saveCollaborator(null, ['display_name' => 'Must roll back', 'role' => 'writer', 'source_reference' => null], $actor),
            fn () => $command->saveCollaborator($collaborator, ['display_name' => 'Must roll back', 'role' => 'writer', 'source_reference' => null], $actor),
            fn () => $command->addSplit(['track_id' => $track->id, 'collaborator_id' => $other->id, 'split_type' => 'master', 'share_bps' => 100], $actor),
            fn () => $command->updateShare($split, 5000, 100, $actor),
            fn () => $command->removeSplit($split, 5000, $actor),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A change was kept without its audit event.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic split audit failure', $exception->getMessage());
            }
            $this->assertSame($before, $this->evidence());
        }
    }

    public function test_the_publication_manifest_and_readiness_check_report_incomplete_splits_without_blocking(): void
    {
        $fixture = QuoteFixtures::selection(); // a published track with no recorded splits at all
        $track = $fixture['track'];
        $actor = $fixture['actor'];
        $reader = app(ReadTrackPublicationManifest::class);
        $at = CarbonImmutable::now()->utc();
        $capture = fn () => DB::transaction(fn () => $reader->captureLocked(Track::query()->lockForUpdate()->findOrFail($track->id), $actor, $at));

        $none = $capture();
        $this->assertFalse($none->advisories()['collaborator_splits']['complete']);
        $this->assertSame([], app(PublicationReadiness::class)->blockers($track->fresh()), 'Missing splits never block publication.');
        $this->assertArrayNotHasKey('collaborator_splits', $none->payload());

        $collaborator = $this->collaborator($actor);
        $split = $this->split($actor, $track, $collaborator, 'master', 2500);
        $partial = $capture();
        $this->assertSame(['status' => 'incomplete', 'total_bps' => 2500, 'collaborators' => 1], $partial->advisories()['collaborator_splits']['types']['master']);
        $this->assertSame($none->hash(), $partial->hash(), 'Splits are reported beside the publication identity, never inside its hash.');
        $this->assertSame([], app(PublicationReadiness::class)->blockers($track->fresh()));

        $this->actingAs($actor);
        $advisories = ['Advisory, does not block publication: publishing collaborator splits are incomplete (0 of 10000 basis points recorded).',
            'Advisory, does not block publication: master collaborator splits are incomplete (2500 of 10000 basis points recorded).'];
        $this->assertSame($advisories, app(CollaboratorSplitReport::class)->advisories($track->id));
        Livewire::test(ManageTracks::class)->callTableAction('readiness', $track)
            ->assertNotified(Notification::make()->title('Ready to publish')->body(implode("\n", $advisories))->persistent());

        app(CollaboratorSplits::class)->updateShare($split, 2500, 10000, $actor);
        $this->split($actor, $track, $collaborator, 'publishing', 10000);
        $complete = $capture();
        $this->assertTrue($complete->advisories()['collaborator_splits']['complete']);
        $this->assertSame($none->hash(), $complete->hash());
        $this->assertSame('published', $track->fresh()->status);
    }

    public function test_splits_are_metadata_only_and_no_pricing_accounting_contract_or_payout_code_reads_them(): void
    {
        // The only application files allowed to name the split model, its tables or its report.
        $allowed = [
            'app/Domain/Rights/CollaboratorRole.php', 'app/Domain/Rights/SplitType.php',
            'app/Domain/Rights/CollaboratorSplits.php', 'app/Domain/Rights/CollaboratorSplitReport.php',
            'app/Domain/Rights/Models/Collaborator.php', 'app/Domain/Rights/Models/TrackCollaboratorSplit.php',
            'app/Filament/Resources/CollaboratorResource.php', 'app/Filament/Resources/CollaboratorResource/Pages/ManageCollaborators.php',
            'app/Filament/Resources/TrackCollaboratorSplitResource.php',
            'app/Filament/Resources/TrackCollaboratorSplitResource/Pages/ManageTrackCollaboratorSplits.php',
            // Report-only readers: the publication manifest advisory and the staff "Check readiness" notice.
            'app/Domain/Catalog/ReadTrackPublicationManifest.php', 'app/Filament/Resources/TrackResource.php',
            // Classification of the two tables as staff catalog data.
            'app/Domain/Customers/DataRights/RetentionSchedule.php',
        ];
        $found = [];
        foreach ((new Finder)->files()->in([app_path(), base_path('routes'), base_path('config'), resource_path()])->name(['*.php', '*.ts', '*.tsx']) as $file) {
            if (preg_match('/Collaborator|SplitType|track_collaborator_splits|[\'"`]collaborators[\'"`]/', $file->getContents())) {
                $found[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
        sort($found);
        sort($allowed);
        $this->assertSame($allowed, $found, 'A new reader of collaborator splits needs a deliberate review: splits feed no pricing, accounting, contract or payout code.');
    }
}
