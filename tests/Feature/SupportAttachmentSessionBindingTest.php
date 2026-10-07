<?php

namespace Tests\Feature;

use App\Domain\Media\MalwareScanner;
use App\Models\User;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

class SupportAttachmentSessionBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('S', 32)), 'support-attachments.fixture_enabled' => true]);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fakePrivateMediaStorage();
        $this->withoutVite();
        $this->app->bind(MalwareScanner::class, TestOnlyMediaScanner::class);
    }

    #[DataProvider('revocations')]
    public function test_actual_final_download_request_revocation_cannot_release_old_bytes(string $kind): void
    {
        $secret = str_repeat('b', 64);
        $this->withSession(['_inquiry_owner' => ['context' => 'guest', 'secret' => $secret]]);
        $owner = hash_hmac('sha256', "vasey-inquiry-owner-v1\0guest\0".$secret, (string) config('app.key'));
        $f = InquiryConversationFixtures::create($owner);
        $base = '/private-support/inquiries/'.$f['inquiry']->public_id.'/attachments';
        $id = $this->call('POST', $base.'/upload', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ATTACHMENT_NAME' => base64_encode('synthetic.txt'), 'HTTP_X_SOURCE_VERSION' => '0', 'HTTP_X_REQUEST_KEY' => (string) Str::uuid()], 'Synthetic old session original')->assertCreated()->json('attachment.attachmentId');
        $this->postJson($base.'/'.$id.'/process', ['sourceVersion' => 0, 'attempt' => 0])->assertOk();
        $before = (array) DB::table('support_attachments')->sole();
        $commits = 0;
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$commits, $kind) {
            if (++$commits === 2) {
                match ($kind) {
                    'owner' => request()->session()->put('_inquiry_owner', ['context' => 'guest', 'secret' => str_repeat('c', 64)]),
                    'session' => request()->session()->migrate(true),
                    'flush' => request()->session()->flush(),
                    'resolver' => request()->setUserResolver(static fn () => null),
                    'guard-user' => Auth::guard('web')->setUser(User::factory()->create()),
                    'guards' => Auth::forgetGuards(),
                };
            }
        });
        $response = $this->call('POST', $base.'/'.$id.'/download', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}');
        $this->assertSame(2, $commits);
        $this->assertSame($before, (array) DB::table('support_attachments')->sole());
        $response->assertStatus(403);
    }

    public static function revocations(): array
    {
        return array_map(static fn ($kind) => [$kind], ['owner', 'session', 'flush', 'resolver', 'guard-user', 'guards']);
    }
}
