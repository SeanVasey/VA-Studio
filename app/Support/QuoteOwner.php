<?php

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;

/** An opaque quote owner, isolated from the session cookie and customer identity. */
final class QuoteOwner
{
    public function forRequest(Request $request): string
    {
        $context = $request->user() === null ? 'guest' : 'user:'.$request->user()->getAuthIdentifier();
        $owner = $request->session()->get('_quote_owner');

        // Rotate across authentication changes, including a return to a guest session.
        if (! is_array($owner) || ($owner['context'] ?? null) !== $context
            || ! is_string($owner['secret'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $owner['secret'])) {
            $owner = ['context' => $context, 'secret' => bin2hex(random_bytes(32))];
            $request->session()->put('_quote_owner', $owner);
        }

        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Quote ownership requires a configured application key.');
        }

        return hash_hmac('sha256', "vasey-quote-owner-v1\0".$context."\0".$owner['secret'], $key);
    }
}
