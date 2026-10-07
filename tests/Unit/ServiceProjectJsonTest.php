<?php

namespace Tests\Unit;

use App\Domain\Services\Projects\ServiceProjectException;
use App\Domain\Services\Projects\ServiceProjectJson;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceProjectJsonTest extends TestCase
{
    public static function ambiguousBodies(): array
    {
        return ['top-level duplicate' => ['{"requestKey":"first","requestKey":"second"}'],
            'escaped top-level alias' => ['{"requestKey":"first","request\\u004bey":"second"}'],
            'nested authored scope alias' => ['{"quote":{"scope":"first","sc\\u006fpe":"second"}}'],
            'milestone duplicate' => ['{"quote":{"milestones":[{"id":"first","id":"second"}]}}'],
            'malformed' => ['{bad'], 'list root' => ['[]'], 'excess depth' => ['{"a":'.str_repeat('[', 17).'0'.str_repeat(']', 17).'}']];
    }

    #[DataProvider('ambiguousBodies')]
    public function test_ambiguous_or_unbounded_json_is_closed_before_any_domain_command(string $raw): void
    {
        $this->expectException(ServiceProjectException::class);
        ServiceProjectJson::body($raw);
    }

    public function test_repeated_keys_in_distinct_objects_and_json_words_inside_text_remain_valid(): void
    {
        $raw = json_encode(['answers' => [['id' => 'one'], ['id' => 'two']], 'summary' => 'A quote: "scope" and commas, braces {} are text.'], JSON_THROW_ON_ERROR);
        $value = ServiceProjectJson::body($raw);
        $this->assertSame(['one', 'two'], array_column($value['answers'], 'id'));
    }
}
