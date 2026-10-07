<?php

namespace Tests\Feature;

use App\Domain\Media\MalwareScanner;
use App\Domain\SupportAttachments\AttachmentRegistry;
use App\Domain\SupportAttachments\FixtureAttachmentPolicy;
use App\Domain\SupportAttachments\InquiryAttachmentAuthority;
use App\Http\Middleware\SupportAttachmentPrivacy;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryConversationFixtures as Fixture;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

class SupportAttachmentHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('S', 32))]);
        $this->fakePrivateMediaStorage();
        config(['support-attachments.fixture_enabled' => true]);
        $secret = str_repeat('b', 64);
        $this->withSession(['_inquiry_owner' => ['context' => 'guest', 'secret' => $secret]]);
        $owner = hash_hmac('sha256', "vasey-inquiry-owner-v1\0guest\0".$secret, (string) config('app.key'));
        $fixture = Fixture::create($owner);
        $this->base = '/private-support/inquiries/'.$fixture['inquiry']->public_id.'/attachments';
        $this->app->instance(AttachmentRegistry::class, new AttachmentRegistry(['inquiry' => new InquiryAttachmentAuthority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]));
        $this->app->bind(MalwareScanner::class, TestOnlyMediaScanner::class);
        $this->app->make(Kernel::class)->prependMiddlewareToGroup('web', SupportAttachmentPrivacy::class);
        Route::middleware('web')->group(base_path('routes/support-attachments.php'));
    }

    public function test_real_private_routes_intake_quarantine_scan_and_download_exact_original(): void
    {
        $list = $this->get($this->base)->assertOk()->assertJsonPath('sourceVersion', 0)->assertJsonPath('canIntake', true);
        $this->private($list);
        $bytes = "SYNTHETIC PRIVATE HTTP FILE.\n";
        $upload = $this->upload($bytes)->assertCreated()->assertJsonPath('attachment.state', 'quarantined');
        $this->private($upload);
        $id = $upload->json('attachment.attachmentId');
        $scan = $this->postJson($this->base.'/'.$id.'/process', ['sourceVersion' => 0, 'attempt' => 0])->assertOk()->assertJsonPath('attachment.state', 'ready');
        $this->private($scan);
        $download = $this->call('POST', $this->base.'/'.$id.'/download', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertOk();
        $this->private($download);
        $this->assertSame($bytes, $download->streamedContent());
        $download->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
    }

    public function test_duplicate_escaped_command_keys_and_unrecognized_fields_are_rejected(): void
    {
        $upload = $this->upload('Synthetic text file.')->assertCreated();
        $id = $upload->json('attachment.attachmentId');
        foreach (['{"sourceVersion":0,"source\\u0056ersion":0,"attempt":0}', '{"sourceVersion":0,"attempt":0,"ownerHash":"forged"}', '{"sourceVersion":0,"attempt":"0"}'] as $body) {
            $response = $this->call('POST', $this->base.'/'.$id.'/process', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertStatus(422);
            $this->private($response);
        }
        $this->assertSame('quarantined', DB::table('support_attachments')->first()->state);
    }

    public function test_queries_ranges_foreign_origins_method_override_and_encoded_bytes_fail_closed(): void
    {
        $this->private($this->get($this->base.'?x=private')->assertStatus(422));
        $this->private($this->get($this->base, ['Range' => 'bytes=0-1'])->assertStatus(422));
        $this->private($this->get($this->base, ['Origin' => 'https://foreign.invalid'])->assertStatus(403));
        $this->private($this->get($this->base, ['X-HTTP-Method-Override' => 'POST'])->assertStatus(405));
        $this->private($this->upload('Synthetic text file.', ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertStatus(415));
        $this->assertDatabaseCount('support_attachments', 0);
    }

    public function test_missing_body_oversized_body_duplicate_upload_headers_and_invalid_name_refuse_before_manifest(): void
    {
        $this->private($this->upload('')->assertStatus(422));
        $this->private($this->upload(str_repeat('s', 5242881))->assertStatus(413));
        $this->private($this->upload('Synthetic text file.', ['HTTP_X_SOURCE_VERSION' => '0, 1'])->assertStatus(422));
        $this->private($this->upload('Synthetic text file.', ['HTTP_X_ATTACHMENT_NAME' => base64_encode("bad\nname.txt")])->assertStatus(422));
        $this->assertDatabaseCount('support_attachments', 0);
    }

    public function test_session_rotation_denies_retained_read_and_csrf_is_enforced_outside_testing(): void
    {
        $this->upload('Synthetic text file.')->assertCreated();
        $this->withSession(['_inquiry_owner' => ['context' => 'guest', 'secret' => str_repeat('c', 64)]]);
        $this->private($this->get($this->base)->assertStatus(404));
        app()->detectEnvironment(fn () => 'production');
        $response = $this->call('POST', $this->base.'/upload', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'Synthetic text file.');
        $this->private($response->assertStatus(419));
        $this->assertDatabaseCount('support_attachments', 1);
    }

    private function upload(string $bytes, array $extra = [])
    {
        return $this->call('POST', $this->base.'/upload', [], [], [], array_replace(['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ATTACHMENT_NAME' => base64_encode('synthetic.txt'), 'HTTP_X_SOURCE_VERSION' => '0', 'HTTP_X_REQUEST_KEY' => (string) Str::uuid()], $extra), $bytes);
    }

    private function private($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }
}
