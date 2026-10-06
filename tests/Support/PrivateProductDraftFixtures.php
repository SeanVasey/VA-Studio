<?php

namespace Tests\Support;

use App\Domain\Merch\MerchDrafts;
use App\Domain\Merch\Models\MerchDraft;
use App\Domain\Merch\Models\MerchDraftVersion;
use App\Domain\Services\Models\ServiceDraft;
use App\Domain\Services\Models\ServiceDraftVersion;
use App\Domain\Services\ServiceDrafts;

final class PrivateProductDraftFixtures
{
    public static function families(): array
    {
        return ['service' => ['service'], 'merch' => ['merch']];
    }

    public static function unresolved(string $reason = 'Synthetic fixture: actual owner policy has not been supplied.'): array
    {
        return ['status' => 'unresolved', 'text' => null, 'reason' => $reason];
    }

    public static function payload(string $kind, array $changes = []): array
    {
        $common = ['title' => 'Synthetic private '.$kind, 'description' => 'Private authored <script> text is retained as plain text.'];
        $specific = $kind === 'service'
            ? ['brief_questions' => ['What is the intended project?', 'Which source files are available?'], 'scope' => self::unresolved(),
                'deposit' => self::unresolved(), 'revisions' => self::unresolved(), 'cancellation' => self::unresolved()]
            : ['variants' => [self::variant('small'), self::variant('large')], 'source' => self::unresolved(), 'shipping' => self::unresolved(), 'returns' => self::unresolved()];

        return array_replace([...$common, ...$specific], $changes);
    }

    public static function variant(string $id): array
    {
        return ['id' => $id, 'label' => 'Synthetic '.$id, 'size' => $id, 'color' => '', 'source_reference' => null,
            'availability' => self::unresolved('Synthetic fixture: supplier stock has not been observed.')];
    }

    public static function classes(string $kind): array
    {
        return $kind === 'service' ? [ServiceDrafts::class, ServiceDraft::class, ServiceDraftVersion::class]
            : [MerchDrafts::class, MerchDraft::class, MerchDraftVersion::class];
    }
}
