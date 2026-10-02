<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Audit evidence is append-only.'));
    }

    public static function record(string $action, Model $subject, array $context = [], ?int $actorId = null): void
    {
        static::create(['actor_id' => $actorId ?? auth()->id(), 'action' => $action, 'subject_type' => $subject::class, 'subject_id' => $subject->getKey(), 'context' => $context]);
    }

    /** Explicit attribution, including anonymous/system null; never consult ambient authentication. */
    public static function recordAttributed(string $action, Model $subject, array $context, ?int $actorId): void
    {
        static::create(['actor_id' => $actorId, 'action' => $action, 'subject_type' => $subject::class, 'subject_id' => $subject->getKey(), 'context' => $context]);
    }
}
