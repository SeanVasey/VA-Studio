<?php

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;

final class InquiryOwner
{
    public function forRequest(Request $request): string
    {
        $context = $request->user() === null ? 'guest' : 'user:'.$request->user()->getAuthIdentifier();
        $owner = $request->session()->get('_inquiry_owner');
        if (! is_array($owner) || ($owner['context'] ?? null) !== $context
            || ! is_string($owner['secret'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $owner['secret'])) {
            $owner = ['context' => $context, 'secret' => bin2hex(random_bytes(32))];
            $request->session()->put('_inquiry_owner', $owner);
        }
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Inquiry ownership requires an application key.');
        }

        return hash_hmac('sha256', "vasey-inquiry-owner-v1\0".$context."\0".$owner['secret'], $key);
    }
}
