<?php

namespace Tests\Feature;

use App\Domain\Media\MalwareScanner;
use App\Domain\SupportAttachments\AttachmentSchema;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\ServiceProjectFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

/** Boot the actual root routes/provider/privacy; no per-test route or authority registration. */
final class SupportAttachmentRegistrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('S', 32)), 'support-attachments.fixture_enabled' => true]);
        $this->fakePrivateMediaStorage();
        $this->withoutVite();
        $this->app->bind(MalwareScanner::class, TestOnlyMediaScanner::class);
    }

    public function test_visitor_page_has_fresh_scope_and_executable_page_csp_but_json_and_original_are_sandboxed(): void
    {
        $base = $this->inquiry();
        $first = $this->get($base.'/view')->assertOk();
        $this->private($first);
        $first->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString("script-src 'self'", $first->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('sandbox', $first->headers->get('Content-Security-Policy'));
        $page = $first->viewData('page');
        $this->assertSame('PrivateSupportAttachments', $page['component']);
        $this->assertSame('inquiry', $page['props']['sourceKind']);
        $this->assertSame('visitor', $page['props']['audience']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $page['props']['renderScope']);
        $this->assertNotSame($page['props']['renderScope'], $this->get($base.'/view')->assertOk()->viewData('page')['props']['renderScope']);
        $this->sandbox($this->privateGet($base)->assertOk()->assertJsonPath('canIntake', true));
        $bytes = "Synthetic registered original.\n";
        $id = $this->upload($base, $bytes)->assertCreated()->json('attachment.attachmentId');
        $this->postJson($base.'/'.$id.'/process', ['sourceVersion' => 0, 'attempt' => 0])->assertOk();
        $stream = $this->call('POST', $base.'/'.$id.'/download', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertOk();
        $this->sandbox($stream);
        $this->assertSame($bytes, $stream->streamedContent());
        $stream->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_registered_project_provider_admits_only_current_owner_or_current_mfa_operator(): void
    {
        $f = ServiceProjectFixtures::setup();
        $id = $f['project']['id'];
        $base = '/private-support/projects/'.$id.'/attachments';
        $this->postJson('/account/sign-in', ['email' => $f['customer']['user']->email, 'password' => CustomerFixtures::PASSWORD])->assertOk();
        $this->get($base.'/view')->assertOk();
        $this->sandbox($this->privateGet($base)->assertOk());
        $other = CustomerFixtures::account();
        $this->postJson('/account/sign-in', ['email' => $other['user']->email, 'password' => CustomerFixtures::PASSWORD])->assertOk();
        $this->sandbox($this->get($base.'/view')->assertNotFound()->assertDontSee('Synthetic buyer brief', false));
        $this->actingAs($f['operator']);
        $operator = '/private-support/operator/projects/'.$id.'/attachments';
        $page = $this->get($operator.'/view')->assertOk();
        $this->assertSame('operator', $page->viewData('page')['props']['audience']);
        $this->sandbox($this->privateGet($operator)->assertOk());
        DB::table('users')->where('id', $f['operator']->id)->update(['is_admin' => false]);
        $this->sandbox($this->get($operator.'/view')->assertForbidden());
    }

    public function test_visitor_rotation_and_default_off_disable_page_and_api_without_disclosing_source(): void
    {
        $base = $this->inquiry();
        $this->withSession(['_inquiry_owner' => ['context' => 'guest', 'secret' => str_repeat('c', 64)]]);
        $this->sandbox($this->get($base.'/view')->assertNotFound()->assertDontSee('Synthetic private conversation', false));
        $this->sandbox($this->privateGet($base)->assertNotFound());
        config(['support-attachments.fixture_enabled' => false]);
        $this->withSession(['_inquiry_owner' => ['context' => 'guest', 'secret' => str_repeat('b', 64)]]);
        $this->sandbox($this->get($base.'/view')->assertStatus(503));
        $this->assertDatabaseCount('support_attachments', 0);
    }

    public function test_real_csrf_precedes_upload_and_private_errors_never_echo_filename_or_file(): void
    {
        $base = $this->inquiry();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->withSession(['_token' => str_repeat('t', 40)]);
        $this->sandbox($this->upload($base, 'Synthetic secret bytes')->assertStatus(419)->assertDontSee('Synthetic secret bytes', false));
        $this->assertDatabaseCount('support_attachments', 0);
        $this->upload($base, 'Synthetic confirmed bytes', ['HTTP_X_CSRF_TOKEN' => str_repeat('t', 40)])->assertCreated();
        $this->assertDatabaseCount('support_attachments', 1);
        $this->assertFalse(session()->has('_old_input'));
    }

    public function test_unknown_routes_methods_query_and_foreign_origin_are_private_before_controller(): void
    {
        config(['app.debug' => true]);
        $this->sandbox($this->privateGet('/private-support/missing')->assertNotFound()->assertDontSee('trace', false));
        $this->sandbox($this->putJson('/private-support/missing', ['private' => 'synthetic'])->assertStatus(405)->assertDontSee('synthetic', false));
        $this->sandbox($this->privateGet('/private-support/missing?email=synthetic@example.test')->assertStatus(422)->assertDontSee('synthetic@example.test', false));
        $this->sandbox($this->privateGet('/private-support/missing', ['Origin' => 'https://elsewhere.invalid'])->assertForbidden());
    }

    public function test_precontroller_failure_logs_only_exception_class_and_sandboxes_debug_response(): void
    {
        Route::middleware('web')->get('/private-support/registered-failure', fn () => throw new \RuntimeException('Synthetic private filename and bytes'));
        config(['app.debug' => true]);
        Log::shouldReceive('error')->once()->with('Private attachment request failed.', ['exception_class' => \RuntimeException::class]);
        $this->sandbox($this->get('/private-support/registered-failure')->assertStatus(503)->assertDontSee('Synthetic private', false)->assertDontSee('trace', false));
    }

    public function test_final_download_commit_callback_cannot_withdraw_policy_and_still_return_original_bytes(): void
    {
        $base = $this->inquiry();
        $id = $this->upload($base, 'Synthetic terminal download original')->assertCreated()->json('attachment.attachmentId');
        $this->postJson($base.'/'.$id.'/process', ['sourceVersion' => 0, 'attempt' => 0])->assertOk();
        $before = DB::table('support_attachments')->get()->toArray();
        $commits = 0;
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$commits): void {
            if (++$commits === 2) {
                config(['support-attachments.fixture_enabled' => false]);
            }
        });
        $response = $this->call('POST', $base.'/'.$id.'/download', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}');
        $this->assertSame(2, $commits);
        $this->assertFalse(config('support-attachments.fixture_enabled'));
        $this->sandbox($response->assertStatus(503)->assertDontSee('Synthetic terminal download original', false));
        $this->assertEquals($before, DB::table('support_attachments')->get()->toArray());
    }

    public function test_empty_migration_hole_before_a_retained_later_guard_is_not_an_owned_prefix(): void
    {
        DB::connection()->getPdo()->exec('DROP TRIGGER support_attachments_update');
        $this->assertDatabaseCount('support_attachments', 0);
        $refused = false;
        try {
            (new AttachmentSchema)->up();
        } catch (\LogicException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'A missing earlier guard with a retained later guard is not a contiguous owned DDL prefix.');
    }

    public function test_reserved_guard_name_cannot_mask_a_second_foreign_table(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE support_attachments_update (marker INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO support_attachments_update (marker) VALUES (9123)');
        $refused = false;
        try {
            (new AttachmentSchema)->assertOwned();
        } catch (\LogicException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'Reserved guard admission must inspect every dictionary row, not only the first matching trigger.');
        $this->assertSame(9123, (int) $pdo->query('SELECT marker FROM '.(DB::getDriverName() === 'sqlite' ? 'main.' : '').'support_attachments_update')->fetchColumn());
    }

    private function privateGet(string $path, array $headers = [])
    {
        return $this->get($path, ['Accept' => 'application/json', ...$headers]);
    }

    private function inquiry(): string
    {
        $secret = str_repeat('b', 64);
        $this->withSession(['_inquiry_owner' => ['context' => 'guest', 'secret' => $secret]]);
        $owner = hash_hmac('sha256', "vasey-inquiry-owner-v1\0guest\0".$secret, (string) config('app.key'));
        $f = InquiryConversationFixtures::create($owner);

        return '/private-support/inquiries/'.$f['inquiry']->public_id.'/attachments';
    }

    private function upload(string $base, string $bytes, array $extra = [])
    {
        return $this->call('POST', $base.'/upload', [], [], [], array_replace(['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ATTACHMENT_NAME' => base64_encode('synthetic.txt'), 'HTTP_X_SOURCE_VERSION' => '0', 'HTTP_X_REQUEST_KEY' => (string) Str::uuid()], $extra), $bytes);
    }

    private function private($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
    }

    private function sandbox($response): void
    {
        $this->private($response);
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }
}
