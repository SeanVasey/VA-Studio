<?php

namespace App\Domain\Rights;

/** Closed set of collaborator roles. A person with two roles is recorded as two collaborators. */
enum CollaboratorRole: string
{
    case Producer = 'producer';
    case Writer = 'writer';
    case Performer = 'performer';

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
        return array_combine(self::values(), array_map(fn (self $role): string => $role->label(), self::cases()));
    }
}
