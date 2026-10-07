<?php

use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentFiles;
use App\Domain\SupportAttachments\AttachmentMutationAuthority;
use App\Domain\SupportAttachments\AttachmentRegistry;
use App\Domain\SupportAttachments\AttachmentRows;
use App\Domain\SupportAttachments\AttachmentSourceProof;
use App\Domain\SupportAttachments\FixtureAttachmentPolicy;
use App\Domain\SupportAttachments\InquiryAttachmentAuthority;
use App\Domain\SupportAttachments\SupportAttachments;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\TestOnlyMediaScanner;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$source, $sync, $root, $role] = array_slice($argv, 1);
config(['support-attachments.fixture_enabled' => true, 'inquiries.enabled' => true, 'filesystems.disks.local.root' => $root]);
$authority = new class($sync, $role) implements AttachmentMutationAuthority
{
    private InquiryAttachmentAuthority $inner;

    private bool $held = false;

    public function __construct(private string $sync, private string $role)
    {
        $this->inner = new InquiryAttachmentAuthority;
    }

    public function lock(string $id, ?int $version, string $purpose, AttachmentActor $actor, AttachmentRows $rows): AttachmentSourceProof
    {
        $proof = $this->inner->lock($id, $version, $purpose, $actor, $rows);
        if ($this->role === 'first' && ! $this->held) {
            $this->held = true;
            file_put_contents($this->sync.'/held', 'held');
            $deadline = microtime(true) + 10;
            while (! file_exists($this->sync.'/release')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Synthetic native reservation synchronization expired.');
                } usleep(10000);
            }
        }

        return $proof;
    }

    public function authorizeMutation(AttachmentSourceProof $proof, int $version, string $purpose, AttachmentRows $rows): void
    {
        $this->inner->authorizeMutation($proof, $version, $purpose, $rows);
    }

    public function proveCurrent(AttachmentSourceProof $proof, AttachmentRows $rows): void
    {
        $this->inner->proveCurrent($proof, $rows);
    }
};
$service = new SupportAttachments(new AttachmentRegistry(['inquiry' => $authority], ['original_inquiry_session_v1' => new FixtureAttachmentPolicy]), new AttachmentFiles, app(TestOnlyMediaScanner::class));
try {
    $key = $role === 'first' ? '11111111-1111-4111-8111-111111111111' : '22222222-2222-4222-8222-222222222222';
    $result = $service->intake('inquiry', $source, 0, AttachmentActor::visitor(InquiryConversationFixtures::OWNER), $key, 'synthetic.txt', $sync.'/synthetic.txt');
    echo json_encode(['status' => 200, 'state' => $result['attachment']['state']], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (AttachmentException $error) {
    echo json_encode(['status' => $error->status], JSON_THROW_ON_ERROR).PHP_EOL;
}
