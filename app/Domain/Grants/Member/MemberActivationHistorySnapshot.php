<?php

namespace App\Domain\Grants\Member;

/** Detached retained values. Only an exact reader registration supplies a live closure. */
final class MemberActivationHistorySnapshot implements \JsonSerializable
{
    private readonly array $retainedScope;

    public function __construct(private readonly MemberActivationHistory $history, array $scope)
    {
        MemberGrantDocumentValues::keys($scope, ['period', 'events', 'redemptions']);
        MemberGrantException::require(is_array($scope['period']) && is_array($scope['events'])
            && array_is_list($scope['events']) && is_array($scope['redemptions']) && array_is_list($scope['redemptions']), 'history_snapshot');
        $this->retainedScope = ['period' => self::row($scope['period']),
            'events' => array_map(self::row(...), $scope['events']),
            'redemptions' => array_map(self::row(...), $scope['redemptions'])];
    }

    public function value(): MemberActivationHistory
    {
        return $this->history;
    }

    public function scope(): array
    {
        return $this->retainedScope;
    }

    private static function row(array $row): array
    {
        $copy = [];
        foreach ($row as $key => $value) {
            MemberGrantException::require(is_string($key) && ($value === null || is_string($value) || is_int($value)), 'history_snapshot');
            $copy[$key] = $value === null ? null : (string) $value;
        }
        ksort($copy, SORT_STRING);

        return $copy;
    }

    public function __clone(): void
    {
        throw new MemberGrantException('history_snapshot');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('history_snapshot');
    }

    public function __unserialize(array $data): never
    {
        throw new MemberGrantException('history_snapshot');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('history_snapshot');
    }

    public function __debugInfo(): array
    {
        return ['member_history_snapshot' => true];
    }
}
