<?php
require '/workspace/VA-Studio-support-read-closure/vendor/autoload.php';
class SupportClosureSessionBoundaryTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('S',32)), 'support-attachments.fixture_enabled'=>true]);
        $this->artisan('migrate',['--force'=>true])->assertExitCode(0);
        $this->fakePrivateMediaStorage();
        $this->withoutVite();
        $this->app->bind(\App\Domain\Media\MalwareScanner::class, \Tests\Support\TestOnlyMediaScanner::class);
    }
    public function test_actual_final_download_session_owner_revocation_is_not_old_request_authority(): void
    {
        $secret=str_repeat('b',64);
        $this->withSession(['_inquiry_owner'=>['context'=>'guest','secret'=>$secret]]);
        $owner=hash_hmac('sha256',"vasey-inquiry-owner-v1\0guest\0".$secret,(string)config('app.key'));
        $f=\Tests\Support\InquiryConversationFixtures::create($owner);
        $base='/private-support/inquiries/'.$f['inquiry']->public_id.'/attachments';
        $id=$this->call('POST',$base.'/upload',[],[],[],['CONTENT_TYPE'=>'application/octet-stream','HTTP_ACCEPT'=>'application/json','HTTP_X_ATTACHMENT_NAME'=>base64_encode('synthetic.txt'),'HTTP_X_SOURCE_VERSION'=>'0','HTTP_X_REQUEST_KEY'=>(string)\Illuminate\Support\Str::uuid()],'Synthetic old session original')->assertCreated()->json('attachment.attachmentId');
        $this->postJson($base.'/'.$id.'/process',['sourceVersion'=>0,'attempt'=>0])->assertOk();
        $commits=0;
        $this->app['events']->listen(\Illuminate\Database\Events\TransactionCommitted::class,function()use(&$commits){if(++$commits===2){request()->session()->put('_inquiry_owner',['context'=>'guest','secret'=>str_repeat('c',64)]);}});
        $response=$this->call('POST',$base.'/'.$id.'/download',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],'{}');
        $this->assertSame(2,$commits);
        $this->assertSame(str_repeat('c',64),session()->get('_inquiry_owner.secret'));
        $response->assertStatus(403);
    }
}
