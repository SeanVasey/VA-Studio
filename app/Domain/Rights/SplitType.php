<?php

namespace App\Domain\Rights;

/** Closed set of split types. Each type is its own set of shares per track. */
enum SplitType: string
{
    case Publishing = 'publishing';
    case Master = 'master';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(self::values(), array_map(fn (self $type): string => $type->label(), self::cases()));
    }
}
