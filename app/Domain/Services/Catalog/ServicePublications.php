<?php

namespace App\Domain\Services\Catalog;

use App\Domain\Services\Models\ServiceDraft;
use App\Domain\Services\Models\ServiceDraftVersion;
use App\Domain\Services\Models\ServicePublication;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\Money\MinorUnits;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of public service page approvals. An approval pins the draft's latest immutable version and its
 * content hash, plus an optional staff-entered price; the draft itself has no price and is never changed here. Each
 * change records one audit event. Nothing here creates an order, payment, quote or service project.
 */
final class ServicePublications
{
    public const FIELDS = ['service_draft_id', 'slug', 'price_minor', 'currency'];

    /** Slugs that would collide with the static /services/projects route. */
    public const RESERVED_SLUGS = ['projects'];

    /** USD only (Sean, SQ-06): the store prices in US dollars, whose two minor-unit digits keep the displayed amount exact. */
    public const CURRENCIES = ['USD'];

    public function approve(array $data, User $actor): ServicePublication
    {
        return DB::transaction(function () use ($data, $actor): ServicePublication {
            $current = $this->lockActor($actor);
            if (count($data) !== count(self::FIELDS) || array_diff(array_keys($data), self::FIELDS) !== []) {
                $this->reject('service_draft_id', 'Supply only the fields shown in this form.');
            }
            $draftId = $data['service_draft_id'];
            $draft = is_int($draftId) && $draftId > 0 ? ServiceDraft::query()->lockForUpdate()->find($draftId) : null;
            if ($draft === null) {
                $this->reject('service_draft_id', 'Choose a current service draft.');
            }
            $version = ServiceDraftVersion::query()->where('draft_id', $draft->id)->orderByDesc('number')->lockForUpdate()->first();
            if ($version === null || ServiceCatalog::verifiedManifest($version) === null) {
                $this->reject('service_draft_id', 'This draft version cannot be verified. Preserve it for investigation.');
            }
            $slug = $this->slug($data['slug']);
            [$price, $currency] = $this->price($data['price_minor'], $data['currency']);
            $existing = ServicePublication::query()->where('service_draft_id', $draft->id)->lockForUpdate()->first();
            $clash = ServicePublication::query()->where('slug', $slug)->lockForUpdate()->first();
            if ($clash !== null && $clash->id !== $existing?->id) {
                $this->reject('slug', 'Another service page already uses this address.');
            }
            $after = ['service_draft_version_id' => (int) $version->id, 'manifest_sha256' => $version->manifest_sha256, 'slug' => $slug,
                'price_minor' => $price, 'currency' => $currency];
            if ($existing !== null && $existing->withdrawn_at === null
                && array_intersect_key($existing->only(array_keys($after)), $after) === $after) {
                return $existing;
            }
            $now = now()->utc();
            $record = $existing ?? new ServicePublication;
            $before = $existing === null ? null : $existing->only([...array_keys($after), 'withdrawn_at']);
            $record->fill($after + ['service_draft_id' => (int) $draft->id, 'approved_by' => (int) $current->id, 'approved_at' => $now,
                'withdrawn_by' => null, 'withdrawn_at' => null])->save();
            AuditEvent::record('services.publication.approved', $record, [
                'schema_version' => 1, 'draft_id' => (int) $draft->id, 'version_number' => (int) $version->number,
                'manifest_sha256' => $version->manifest_sha256, 'slug' => $slug, 'price_minor' => $price, 'currency' => $currency,
                'previous_version_id' => $before['service_draft_version_id'] ?? null, 'was_withdrawn' => ($before['withdrawn_at'] ?? null) !== null,
            ], $current->id);

            return $record;
        });
    }

    public function withdraw(ServicePublication $publication, User $actor): ServicePublication
    {
        return DB::transaction(function () use ($publication, $actor): ServicePublication {
            $current = $this->lockActor($actor);
            $locked = $publication->exists ? ServicePublication::query()->lockForUpdate()->find($publication->getKey()) : null;
            if ($locked === null) {
                $this->reject('slug', 'This service page is no longer available. Reload and choose a current page.');
            }
            if ($locked->withdrawn_at !== null) {
                return $locked;
            }
            $locked->fill(['withdrawn_by' => (int) $current->id, 'withdrawn_at' => now()->utc()])->save();
            AuditEvent::record('services.publication.withdrawn', $locked, ['schema_version' => 1, 'draft_id' => (int) $locked->service_draft_id,
                'manifest_sha256' => $locked->manifest_sha256, 'slug' => $locked->slug], $current->id);

            return $locked;
        });
    }

    private function slug(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 80 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $value) !== 1
            || in_array($value, self::RESERVED_SLUGS, true)) {
            $this->reject('slug', 'Use lowercase letters, numbers and single hyphens (at most 80 characters); "projects" is reserved.');
        }

        return $value;
    }

    /** @return array{0: ?int, 1: ?string} Both or neither: an amount without a currency (or the reverse) is never stored. */
    private function price(mixed $minor, mixed $currency): array
    {
        if ($minor === null && $currency === null) {
            return [null, null];
        }
        if (! is_int($minor) || $minor < 1 || $minor > MinorUnits::MAX || ! in_array($currency, self::CURRENCIES, true)) {
            $this->reject('price_minor', 'Leave both price fields empty, or enter a whole number of minor units above zero (cents) and one of '.implode(', ', self::CURRENCIES).'.');
        }

        return [$minor, $currency];
    }

    private function lockActor(User $actor): User
    {
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }

        return $current;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
