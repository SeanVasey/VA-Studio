<?php

namespace App\Domain\Inquiries;

use Illuminate\Support\Facades\Validator;

final class InquiryInput
{
    public const FIELDS = ['name', 'email', 'subject', 'message', 'website', 'requestKey'];

    public static function validate(array $body): array
    {
        if (count($body) !== 6 || array_diff(array_keys($body), self::FIELDS) !== [] || count(array_filter($body, 'is_string')) !== 6) {
            throw new InquiryException(422);
        }
        foreach ($body as $value) {
            if (! mb_check_encoding($value, 'UTF-8')) {
                throw new InquiryException(422);
            }
        }
        $validator = Validator::make($body, [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'email' => ['required', 'string', 'max:254', 'email:filter', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'subject' => ['required', 'string', 'max:160', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'message' => ['required', 'string', 'max:8000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
            'website' => ['present'],
            'requestKey' => ['required', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D'],
        ]);
        if ($body['website'] !== '' || $validator->fails()) {
            $messages = ['name' => 'Enter a name of at most 120 characters.', 'email' => 'Enter a valid email address of at most 254 characters.',
                'subject' => 'Enter a subject of at most 160 characters.', 'message' => 'Enter a message of at most 8000 characters.',
                'website' => 'This request cannot be accepted.', 'requestKey' => 'Start a new request before sending.'];
            $failed = $validator->failed();
            if ($body['website'] !== '') {
                $failed['website'] = [];
            }
            throw new InquiryException(422, array_combine(array_keys($failed), array_map(fn (string $field): array => [$messages[$field]], array_keys($failed))));
        }

        return $body;
    }
}
