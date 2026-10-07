<?php

namespace App\Domain\Catalog\Discovery;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Commerce\Inventory\SelectionInventory;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use Throwable;

/** One bounded current discovery decision. No HTTP route, persisted manifest or grants. */
final class CurrentEligibleTrackSnapshot
{
    public const LIMIT = 48;

    public const SECONDS = 10;

    public function capture(?string $continuation = null): EligibleTrackSnapshot
    {
        $cursor = $continuation === null ? null : $this->decode($continuation);
        if ($cursor !== null && (array_keys($cursor) !== ['version', 'epoch', 'after'] || $cursor['version'] !== 1
            || ! is_int($cursor['epoch']) || ! is_int($cursor['after']) || $cursor['after'] < 1)) {
            throw new LogicException('Invalid discovery continuation.');
        }

        return $this->captureCurrent($cursor, null);
    }

    /** Consumers revalidate retained evidence; an old discovery result cannot authorize public output. */
    public function currentPaths(EligibleTrackSnapshot $snapshot): array
    {
        $evidence = $this->decode($snapshot->evidence());
        if (array_keys($evidence) !== ['version', 'epoch', 'ids', 'paths', 'expires_at', 'configuration'] || $evidence['version'] !== 1
            || ! is_int($evidence['epoch']) || ! is_array($evidence['ids']) || ! array_is_list($evidence['ids'])
            || count($evidence['ids']) > self::LIMIT || ! is_array($evidence['paths']) || $evidence['paths'] !== $snapshot->paths()
            || ! is_string($evidence['expires_at']) || ! is_string($evidence['configuration'])
            || ! hash_equals($this->configuration(), $evidence['configuration'])
            || CarbonImmutable::parse($evidence['expires_at'])->lessThanOrEqualTo(CarbonImmutable::instance(now()))) {
            throw new LogicException('Discovery capture is no longer current.');
        }
        foreach ($evidence['ids'] as $id) {
            if (! is_int($id) || $id < 1) {
                throw new LogicException('Invalid discovery evidence.');
            }
        }
        $current = $this->captureCurrent(['version' => 1, 'epoch' => $evidence['epoch'], 'after' => 0], $evidence['ids'], CarbonImmutable::parse($evidence['expires_at']));
        if ($current->paths() !== $snapshot->paths()) {
            throw new LogicException('Discovery eligibility changed.');
        }

        return $current->paths();
    }

    private function captureCurrent(?array $cursor, ?array $ids, ?CarbonImmutable $retainedDeadline = null): EligibleTrackSnapshot
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Discovery capture requires a standalone transaction.');
        }
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        $epochs = new DiscoveryEpoch;
        $epochs->assertInstalled($pdo, $driver);
        // Apply only to the next owned transaction; do not alter the session default.
        if ($driver === 'mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return $connection->transaction(function () use ($connection, $pdo, $driver, $epochs, $cursor, $ids, $retainedDeadline): EligibleTrackSnapshot {
            $epochs->current($pdo);
            if ($driver === 'sqlite') {
                // Acquire SQLite's database writer fence without advancing the generation.
                $pdo->exec('UPDATE '.DiscoveryEpoch::TABLE.' SET epoch = epoch WHERE id = 1');
            }
            $epoch = $epochs->current($pdo);
            if ($cursor !== null && $cursor['epoch'] !== $epoch) {
                throw new LogicException('Discovery generation changed; restart capture.');
            }
            $configuration = $this->configuration();
            $at = CarbonImmutable::instance(now())->utc();
            $expires = $at->addSeconds(self::SECONDS);
            if ($retainedDeadline !== null && $retainedDeadline->lessThan($expires)) {
                $expires = $retainedDeadline;
            }
            $query = Track::query()->where('status', 'published')->orderBy('id');
            $query = $ids === null ? $query->where('id', '>', $cursor['after'] ?? 0) : $query->whereIn('id', $ids);
            $tracks = $query->limit(self::LIMIT + 1)->get();
            $more = $tracks->count() > self::LIMIT;
            $tracks = $tracks->take(self::LIMIT);
            // Include future/expired blockers: a presently excluded offer can cross a
            // license boundary during capture without any writer changing the epoch.
            foreach (['effective_from', 'effective_until'] as $field) {
                $boundary = DB::table('offers')->join('offer_revisions as revisions', 'revisions.id', '=', 'offers.current_revision_id')
                    ->join('license_versions as licenses', 'licenses.id', '=', 'revisions.license_version_id')
                    ->whereIn('offers.track_id', $tracks->pluck('id')->all())->where('offers.is_active', true)
                    ->where('licenses.'.$field, '>', $at)->min('licenses.'.$field);
                if ($boundary !== null && CarbonImmutable::parse($boundary, 'UTC')->lessThan($expires)) {
                    $expires = CarbonImmutable::parse($boundary, 'UTC');
                }
            }
            $paths = [];
            foreach ($tracks as $track) {
                // Keep shared eligibility semantics, including every active offer's evidence.
                if (app(PublicationReadiness::class)->blockers($track, $at) !== []) {
                    continue;
                }
                $available = $track->offers->contains(fn ($offer): bool => app(SelectionInventory::class)->available($offer->current_revision_id, null, $at));
                if ($available) {
                    if (! is_string($track->slug) || strlen($track->slug) > 255 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $track->slug) !== 1) {
                        throw new LogicException('Invalid discovery track identity.');
                    }
                    $path = route('tracks.show', $track->slug, false);
                    if (! str_starts_with($path, '/tracks/') || str_contains($path, '?') || str_contains($path, '#')) {
                        throw new LogicException('Invalid discovery destination.');
                    }
                    $paths[] = $path;
                }
            }
            // A held claim's expiry can change browse eligibility without a database write.
            $expiry = DB::table('inventory_reservations as reservations')->join('inventory_claims as claims', 'claims.inventory_reservation_id', '=', 'reservations.id')
                ->join('rights_scope_offers as links', 'links.rights_scope_id', '=', 'claims.rights_scope_id')
                ->join('offer_revisions as revisions', 'revisions.id', '=', 'links.offer_revision_id')
                ->whereIn('revisions.track_id', $tracks->pluck('id')->all())->where('reservations.state', 'held')
                ->where('reservations.expires_at', '>', $at)->min('reservations.expires_at');
            if ($expiry !== null && CarbonImmutable::parse($expiry, 'UTC')->lessThan($expires)) {
                $expires = CarbonImmutable::parse($expiry, 'UTC');
            }
            $next = $more && $ids === null ? Crypt::encryptString(json_encode(['version' => 1, 'epoch' => $epoch, 'after' => (int) $tracks->last()->id], JSON_THROW_ON_ERROR)) : null;
            $evidence = Crypt::encryptString(json_encode(['version' => 1, 'epoch' => $epoch, 'ids' => $tracks->pluck('id')->all(), 'paths' => $paths,
                'expires_at' => $expires->toISOString(), 'configuration' => $configuration], JSON_THROW_ON_ERROR));
            // The final fence follows all graph reads, never precedes actor/track/scope waits.
            // Raw captured PDO prevents query listeners from acting after the last proof.
            if (DB::connection() !== $connection || $connection->getPdo() !== $pdo || $connection->transactionLevel() !== 1 || ! $pdo->inTransaction()) {
                throw new LogicException('Discovery transaction changed.');
            }
            $epochs->assertInstalled($pdo, $driver);
            if ($epochs->current($pdo, true) !== $epoch || ! hash_equals($configuration, $this->configuration())
                || CarbonImmutable::instance(now())->utc()->greaterThanOrEqualTo($expires)) {
                throw new LogicException('Discovery evidence changed during capture.');
            }

            return new EligibleTrackSnapshot($paths, $next, $evidence);
        });
    }

    private function configuration(): string
    {
        return CanonicalJson::hash(['environment' => app()->environment(), 'media' => config('media'), 'commerce' => config('commerce'),
            'filesystems' => config('filesystems'), 'key' => hash('sha256', (string) config('app.key'))]);
    }

    private function decode(string $sealed): array
    {
        try {
            if (strlen($sealed) > 32768) {
                throw new LogicException;
            }
            $value = json_decode(Crypt::decryptString($sealed), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($value)) {
                throw new LogicException;
            }

            return $value;
        } catch (Throwable $error) {
            throw new LogicException('Invalid discovery evidence.', previous: $error);
        }
    }
}
