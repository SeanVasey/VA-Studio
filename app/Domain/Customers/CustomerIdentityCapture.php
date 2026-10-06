<?php

namespace App\Domain\Customers;

use App\Domain\Customers\Models\CustomerIdentityChallenge;
use App\Notifications\CustomerIdentityNotice;
use Illuminate\Support\Facades\Storage;
use LogicException;
use SensitiveParameter;

final class CustomerIdentityCapture
{
    public function store(CustomerIdentityChallenge $challenge, #[SensitiveParameter] string $proof): void
    {
        app(CustomerIdentityPolicy::class)->requireEnabled();
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! isset($parts['host'])) {
            throw new LogicException('A fixed local test origin is required.');
        }
        $url = $origin.'/account/access#'.$challenge->purpose.'.'.$challenge->public_id.'.'.$proof;
        $mail = (new CustomerIdentityNotice($url, $challenge->purpose))->toMail(new \stdClass);
        $payload = json_encode(['testOnly' => true, 'id' => $challenge->public_id, 'purpose' => $challenge->purpose,
            'to' => $challenge->email, 'subject' => $mail->subject, 'url' => $url,
            'expiresAt' => $challenge->expires_at->toISOString()], JSON_THROW_ON_ERROR);
        $disk = Storage::disk('local');
        if (config('filesystems.disks.local.driver') !== 'local' || config('filesystems.disks.local.visibility', 'private') !== 'private'
            || ! $disk->put('customer-identity-capture/'.$challenge->public_id.'.json', $payload, ['visibility' => 'private'])) {
            throw new LogicException('Private test notification capture failed.');
        }
    }
}
