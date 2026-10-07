<?php

namespace Tests\Feature;

use App\Domain\Grants\Free\FreeGrantDefinitions;
use App\Domain\Grants\Free\Models\FreeDefinition;
use App\Filament\Resources\FreeDefinitionResource\Pages\ManageFreeDefinitions;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

final class FreeGrantAdministrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->fakePrivateMediaStorage();
    }

    public function test_mounted_operator_actions_author_separately_review_and_version_admission_without_client_supplied_authority(): void
    {
        $f = FreeGrantFixtures::source();
        $this->actingAs($f['author']);
        $input = $f['input'];
        unset($input['requestKey'],$input['freePurpose']);
        $page = Livewire::test(ManageFreeDefinitions::class)->mountAction('authorFree')->setActionData($input)->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('free_definitions', 1);
        $this->assertDatabaseCount('free_reviews', 0);
        $this->assertDatabaseCount('free_origins', 0);
        $record = FreeDefinition::sole();
        $before = DB::table('free_definitions')->get()->toJson();
        $review = ['reference' => 'SYNTHETIC-FILAMENT-FREE-REVIEW', 'freeScopeConfirmed' => true, 'scopeBindingConfirmed' => true, 'assetManifestConfirmed' => true];
        $page->mountTableAction('approveFreeScope', $record)->setActionData($review)->callMountedAction()->assertHasActionErrors();
        $this->assertDatabaseCount('free_reviews', 0);
        $this->actingAs($f['reviewer']);
        Livewire::test(ManageFreeDefinitions::class)->mountTableAction('approveFreeScope', $record)->setActionData($review)->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('free_reviews', 1);
        $this->actingAs($f['author']);
        $page = Livewire::test(ManageFreeDefinitions::class)->mountTableAction('openAdmission', $record)->setActionData(['reason' => 'Explicit mounted opening'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertTrue((new FreeGrantDefinitions)->readStaff($record->public_id, $f['author'])['open']);
        $page->mountTableAction('closeAdmission', $record)->setActionData(['reason' => 'Explicit mounted closure'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertFalse((new FreeGrantDefinitions)->readStaff($record->public_id, $f['author'])['open']);
        $this->assertSame($before, DB::table('free_definitions')->get()->toJson());
        $this->assertDatabaseCount('free_availability', 2);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_mounted_server_capture_is_locked_against_forged_definition_or_request_authority(): void
    {
        $f = FreeGrantFixtures::source();
        $d = FreeGrantFixtures::publish($f);
        $this->actingAs($f['author']);
        $record = FreeDefinition::sole();
        $page = Livewire::test(ManageFreeDefinitions::class)->mountTableAction('closeAdmission', $record);
        $before = DB::table('free_availability')->get()->toJson();
        try {
            $page->set('captured.version', 999);
            $this->fail('Browser cannot rewrite the mounted capture.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertSame($before, DB::table('free_availability')->get()->toJson());
        }
    }
}
