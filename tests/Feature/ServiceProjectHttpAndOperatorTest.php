<?php

namespace Tests\Feature;

use App\Domain\Services\Projects\ServiceProjects;
use App\Filament\Resources\ServiceProjectResource\Pages\ViewServiceProject;
use App\Http\Middleware\ServiceProjectPrivacy;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

class ServiceProjectHttpAndOperatorTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        $this->withoutVite();
        $this->app->make(Kernel::class)->prependMiddleware(ServiceProjectPrivacy::class);
        if (! Route::has('service-projects.page')) {
            Route::middleware('web')->group(base_path('routes/services.php'));
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function signIn(array $customer): void
    {
        $this->postJson('/account/sign-in', ['email' => $customer['user']->email, 'password' => CustomerFixtures::PASSWORD])->assertOk();
    }

    public function test_mounted_operator_quote_and_milestone_actions_join_actual_buyer_http_acceptance(): void
    {
        $f = F::setup();
        $this->signIn($f['customer']);
        $this->get('/services/projects')->assertOk()->assertSee('ServiceProjects')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->actingAs($f['operator']);
        $page = Livewire::test(ViewServiceProject::class, ['record' => $f['project']['id']])->assertSee('Synthetic buyer brief')
            ->mountAction('author_quote')->setActionData([...F::quote(), 'confirmed' => true])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Scope journey saved');
        $project = app(ServiceProjects::class)->staffShow($f['project']['id'], $f['operator']);
        $this->assertSame('quoted', $project['status']);
        $this->postJson('/services/projects/'.$project['id'].'/commands', F::command($project, 'accept_quote', ['quoteId' => $project['quoteId'], 'quoteHash' => $project['quoteHash']]))->assertOk()->assertJsonPath('project.scopeFrozen', true)->assertJsonPath('project.paymentState', 'not_collected');
        $page->mountAction('begin_milestone')->setActionData(['milestoneId' => 'mix-review', 'reason' => 'Synthetic staff progress'])->callMountedAction()->assertHasNoActionErrors();
        $page->mountAction('ready_milestone')->setActionData(['milestoneId' => 'mix-review', 'reason' => 'Synthetic ready review'])->callMountedAction()->assertHasNoActionErrors();
        $project = app(ServiceProjects::class)->staffShow($f['project']['id'], $f['operator']);
        $this->postJson('/services/projects/'.$project['id'].'/commands', F::command($project, 'approve_milestone', ['milestoneId' => 'mix-review', 'reason' => 'Buyer scope review']))->assertOk()->assertJsonPath('project.status', 'scope_reviewed')->assertJsonPath('project.deliveryAuthorized', false);
        $this->assertDatabaseCount('service_project_events', 5);
    }

    public function test_real_http_bodies_cross_customer_denial_and_privacy_do_not_accept_client_authority_or_unbounded_input(): void
    {
        $f = F::setup();
        $this->signIn($f['customer']);
        $this->get('/services/projects/'.$f['project']['id'], ['Accept' => 'application/json'])->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertJsonMissingPath('project.customer_account_id');
        $before = DB::table('service_projects')->count();
        $body = $f['brief'];
        $body['requestKey'] = (string) Str::uuid();
        $body['owner'] = $f['customer']['principal']->ownerKey;
        $this->postJson('/services/projects', $body)->assertStatus(422)->assertJsonMissingPath('owner');
        $this->withHeader('Origin', 'https://elsewhere.invalid')->postJson('/services/projects', $f['brief'])->assertForbidden();
        $this->flushHeaders();
        $this->get('/services/projects/'.$f['project']['id'].'?scope=other', ['Accept' => 'application/json'])->assertStatus(422);
        $this->postJson('/services/projects', ['body' => str_repeat('x', 65536)])->assertStatus(413);
        $this->call('POST', '/services/projects', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{bad')->assertStatus(422);
        $this->call('POST', '/services/projects', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"summary":"first","summar\\u0079":"second"}')->assertStatus(422);
        $this->assertSame($before, DB::table('service_projects')->count());
        $other = CustomerFixtures::account();
        $this->signIn($other);
        $this->get('/services/projects/'.$f['project']['id'], ['Accept' => 'application/json'])->assertNotFound()->assertDontSee('Synthetic buyer brief')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_two_opened_staff_actions_cannot_overwrite_one_another_and_customer_cannot_mount_staff_resource(): void
    {
        $f = F::setup();
        $this->actingAs($f['operator']);
        $first = Livewire::test(ViewServiceProject::class, ['record' => $f['project']['id']])->mountAction('author_quote');
        $stale = Livewire::test(ViewServiceProject::class, ['record' => $f['project']['id']])->mountAction('author_quote');
        $stale->assertSet('commandVersion', 0);
        $first->setActionData([...F::quote(), 'confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
        $stale->setActionData([...F::quote(['scope' => 'Unsaved stale input']), 'confirmed' => true])->callMountedAction()->assertHasActionErrors(['scope']);
        $this->assertDatabaseCount('service_project_events', 1);
        $this->actingAs($f['customer']['user']);
        Livewire::test(ViewServiceProject::class, ['record' => $f['project']['id']])->assertForbidden();
        $this->assertDatabaseCount('service_project_events', 1);
    }
}
