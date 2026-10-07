<?php

namespace App\Domain\Services\Projects;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ServiceProjectInput
{
    public static function brief(array $body): array
    {
        self::keys($body, ['requestKey', 'serviceVersionId', 'serviceHash', 'summary', 'answers']);
        self::key($body['requestKey']);
        self::integer($body['serviceVersionId'], 1, PHP_INT_MAX);
        self::hash($body['serviceHash']);
        self::text($body['summary'], 4000);
        if (! is_array($body['answers']) || ! array_is_list($body['answers']) || count($body['answers']) > 20) {
            self::reject();
        }
        foreach ($body['answers'] as $answer) {
            self::text($answer, 2000);
        }

        return $body;
    }

    public static function command(array $body, bool $staff): array
    {
        if (! isset($body['action']) || ! is_string($body['action'])) {
            self::reject();
        }
        $actions = $staff ? ['author_quote', 'begin_milestone', 'ready_milestone', 'cancel']
            : ['accept_quote', 'decline_quote', 'approve_milestone', 'request_revision', 'withdraw', 'request_cancellation'];
        if (! in_array($body['action'], $actions, true)) {
            self::reject();
        }
        $extra = match ($body['action']) {
            'author_quote' => ['quote'],
            'accept_quote', 'decline_quote' => ['quoteId', 'quoteHash'],
            'begin_milestone', 'ready_milestone', 'approve_milestone', 'request_revision' => ['milestoneId', 'reason'],
            default => ['reason'],
        };
        self::keys($body, ['requestKey', 'expectedVersion', 'action', ...$extra]);
        self::key($body['requestKey']);
        self::integer($body['expectedVersion'], 0, 999);
        if (array_key_exists('quote', $body)) {
            if (! is_array($body['quote'])) {
                self::reject();
            }
            $body['quote'] = self::quote($body['quote']);
        }
        if (array_key_exists('quoteId', $body)) {
            self::key($body['quoteId']);
            self::hash($body['quoteHash']);
        }
        if (array_key_exists('milestoneId', $body)) {
            self::identity($body['milestoneId']);
        }
        if (array_key_exists('reason', $body)) {
            self::text($body['reason'], 2000);
        }

        return $body;
    }

    public static function quote(array $body): array
    {
        self::keys($body, ['title', 'scope', 'currency', 'totalMinor', 'depositMinor', 'revisionAllowance', 'cancellation', 'milestones']);
        self::text($body['title'], 180);
        self::text($body['scope'], 4000);
        self::text($body['cancellation'], 2000);
        if (! is_string($body['currency']) || ! preg_match('/\A[A-Z]{3}\z/D', $body['currency'])) {
            self::reject();
        }
        self::integer($body['totalMinor'], 1, 2147483647);
        self::integer($body['depositMinor'], 0, $body['totalMinor']);
        self::integer($body['revisionAllowance'], 0, 20);
        if (! is_array($body['milestones']) || ! array_is_list($body['milestones']) || count($body['milestones']) < 1 || count($body['milestones']) > 10) {
            self::reject();
        }
        $ids = [];
        foreach ($body['milestones'] as $milestone) {
            if (! is_array($milestone)) {
                self::reject();
            }
            self::keys($milestone, ['id', 'label', 'scope']);
            self::identity($milestone['id']);
            self::text($milestone['label'], 180);
            self::text($milestone['scope'], 2000);
            $ids[] = $milestone['id'];
        }
        if (count($ids) !== count(array_unique($ids))) {
            self::reject();
        }

        return $body;
    }

    public static function key(mixed $value): void
    {
        if (! is_string($value) || ! Str::isUuid($value) || strtolower($value) !== $value) {
            self::reject();
        }
    }

    public static function hash(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/\A[a-f0-9]{64}\z/D', $value)) {
            self::reject();
        }
    }

    private static function identity(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/\A[a-z0-9][a-z0-9_-]{0,39}\z/D', $value)) {
            self::reject();
        }
    }

    private static function integer(mixed $value, int $min, int $max): void
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            self::reject();
        }
    }

    private static function text(mixed $value, int $limit): void
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || $value === '' || trim($value) !== $value
            || mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            self::reject();
        }
    }

    private static function keys(array $body, array $keys): void
    {
        if (count($body) !== count($keys) || array_diff(array_keys($body), $keys) !== []) {
            self::reject();
        }
    }

    private static function reject(): never
    {
        throw ValidationException::withMessages(['serviceProject' => 'Supply only the displayed fields, with explicit plain text and integer minor-unit amounts.']);
    }
}
