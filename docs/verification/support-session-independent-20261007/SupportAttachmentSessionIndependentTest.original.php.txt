<?php

namespace Tests\Feature;

use App\Domain\Media\MalwareScanner;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

/** Actual registered HTTP and actual provider query; no fabricated actor or context. */
class SupportAttachmentSessionIndependentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('S', 32)), 'support-attachments.fixture_enabled' => true]);
        if (DB::getDriverName() === 'mysql') {
            if (getenv('ATTACHMENT_NATIVE_ISOLATED') !== '1' || DB::getDatabaseName() !== 'vaseyaudio_support_closure') {
                $this->markTestSkipped('Explicit dedicated synthetic reviewer database required.');
            }
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        } else {
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $this->fakePrivateMediaStorage();
        $this->withoutVite();
        $this->app->bind(MalwareScanner::class, TestOnlyMediaScanner::class);
    }

    public function test_context_provider_callback_cannot_adopt_a_changed_owner_after_actor_was_minted(): void
    {
        $secret = str_repeat('b', 64);
        $this->withSession(['_inquiry_owner' => ['context' => 'guest', 'secret' => $secret]]);
        $owner = hash_hmac('sha256', "vasey-inquiry-owner-v1\0guest\0".$secret, (string) config('app.key'));
        $fixture = InquiryConversationFixtures::create($owner);
        $base = '/private-support/inquiries/'.$fixture['inquiry']->public_id.'/attachments';
        $id = $this->call('POST', $base.'/upload', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ATTACHMENT_NAME' => base64_encode('synthetic.txt'), 'HTTP_X_SOURCE_VERSION' => '0', 'HTTP_X_REQUEST_KEY' => (string) Str::uuid()], 'Synthetic captured owner original')->assertCreated()->json('attachment.attachmentId');
        $this->postJson($base.'/'.$id.'/process', ['sourceVersion' => 0, 'attempt' => 0])->assertOk();
        $before = (array) DB::table('support_attachments')->sole();
        $user = User::factory()->create();
        $this->withSession([Auth::guard('customer')->getName() => $user->id]);
        Auth::forgetGuards();
        $callbacks = 0;
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$callbacks, $user): void {
            if (str_starts_with(strtolower($event->sql), 'select') && str_contains(strtolower($event->sql), 'users') && in_array($user->id, $event->bindings, true)) {
                $callbacks++;
                request()->session()->put('_inquiry_owner', ['context' => 'guest', 'secret' => str_repeat('c', 64)]);
            }
        });
        $response = $this->call('POST', $base.'/'.$id.'/download', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}');
        $this->assertSame(1, $callbacks);
        $this->assertSame(str_repeat('c', 64), session()->get('_inquiry_owner.secret'));
        $this->assertSame($before, (array) DB::table('support_attachments')->sole());
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_final_auth_manager_user_resolver_change_cannot_release_old_request_owner_bytes(): void
    {
        $this->lateAuthChange('resolver');
    }

    public function test_final_auth_manager_replacement_cannot_release_old_request_owner_bytes(): void
    {
        $this->lateAuthChange('manager');
    }

    public function test_final_default_guard_change_cannot_release_old_request_owner_bytes(): void
    {
        $this->lateAuthChange('default');
    }

    private function lateAuthChange(string $kind): void
    {
        $secret = str_repeat('b', 64);
        $this->withSession(['_inquiry_owner' => ['context' => 'guest', 'secret' => $secret]]);
        $owner = hash_hmac('sha256', "vasey-inquiry-owner-v1\0guest\0".$secret, (string) config('app.key'));
        $fixture = InquiryConversationFixtures::create($owner);
        $base = '/private-support/inquiries/'.$fixture['inquiry']->public_id.'/attachments';
        $id = $this->call('POST', $base.'/upload', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ATTACHMENT_NAME' => base64_encode('synthetic.txt'), 'HTTP_X_SOURCE_VERSION' => '0', 'HTTP_X_REQUEST_KEY' => (string) Str::uuid()], 'Synthetic prior request owner original')->assertCreated()->json('attachment.attachmentId');
        $this->postJson($base.'/'.$id.'/process', ['sourceVersion' => 0, 'attempt' => 0])->assertOk();
        $before = (array) DB::table('support_attachments')->sole();
        $user = User::factory()->create();
        if ($kind === 'default') {
            $this->withSession([Auth::guard('customer')->getName() => $user->id]);
        }
        $commits = 0;
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$commits, $user, $kind): void {
            if (++$commits === 2) {
                if ($kind === 'resolver') {
                    Auth::resolveUsersUsing(static fn () => $user);
                } elseif ($kind === 'default') {
                    config(['auth.defaults.guard' => 'customer']);
                } else {
                    $manager = new AuthManager($this->app);
                    $manager->guard('web')->setUser($user);
                    Auth::swap($manager);
                }
            }
        });
        $response = $this->call('POST', $base.'/'.$id.'/download', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}');
        $this->assertSame(2, $commits);
        $this->assertSame($user->id, request()->user()?->getAuthIdentifier());
        $this->assertSame($before, (array) DB::table('support_attachments')->sole());
        $response->assertStatus(403);
    }
}
