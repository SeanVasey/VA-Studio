<?php

namespace App\Domain\Customers\ProductionFeatures\Listening;

use App\Domain\Catalog\PublicCatalog;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\ProductionFeatures\Models\ProductionListeningLibrary as LibraryRow;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureOperation;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureShape;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** New explicitly bound feature records only; no test principal or historical data adoption. */
final class ProductionListeningLibrary
{
    public function read(ProductionAccountFeatureIdentity $identity): array
    {
        return $this->run($identity);
    }

    /** Called only by the dedicated server-authorized closed-empty-body POST. */
    public function initialize(ProductionAccountFeatureIdentity $identity): array
    {
        return $this->run($identity, initialize: true);
    }

    public function change(ProductionAccountFeatureIdentity $identity, array $command): array
    {
        (new ProductionListeningState)->validateCommand($command);

        return $this->run($identity, $command);
    }

    public function export(ProductionAccountFeatureIdentity $identity, int $expectedVersion): array
    {
        if ($expectedVersion < 0 || $expectedVersion > 2147483646) {
            throw new ListeningException;
        }

        return $this->run($identity, expectedExportVersion: $expectedVersion);
    }

    private function run(ProductionAccountFeatureIdentity $identity, ?array $command = null, bool $initialize = false, ?int $expectedExportVersion = null): array
    {
        if ($identity->feature !== 'listening_library') {
            throw new IdentityException;
        }

        return (new ProductionFeatureOperation)->run($identity, function (ProductionFeatureContext $context) use ($command, $initialize, $expectedExportVersion): array {
            $rules = new ProductionListeningState;
            $source = $context->configuration();
            $rollout = ProductionListeningRollout::capture($source);
            $context->guard(fn () => ProductionListeningRollout::requireCurrent($rollout, $source));
            $binding = $context->binding();
            if ($binding === null && ! $initialize) {
                if ($command !== null || $expectedExportVersion !== null) {
                    throw new ProductionFeatureException(409);
                }

                return ['initialized' => false];
            }
            if ($binding === null) {
                $at = now()->utc()->format('Y-m-d H:i:s');
                $binding = $context->createBinding($at);
                $empty = $rules->empty($context->accountId());
                $attributes = ['binding_id' => (int) $binding['row']['id'], 'version' => 0,
                    'payload' => $this->encrypt($empty), 'created_at' => $at, 'updated_at' => $at];
                $model = LibraryRow::create($attributes);
                $context->expected($model->getRawOriginal(), $attributes);
                if ($this->state($model->getRawOriginal(), $context->accountId()) !== $empty) {
                    throw new ProductionFeatureException;
                }
            }
            $rows = $context->observe('production_listening_libraries', ['binding_id' => (int) $binding['row']['id']]);
            if (count($rows) !== 1) {
                throw new ProductionFeatureException;
            }
            $row = $rows[0];
            if ($row['created_at'] !== $binding['row']['created_at']) {
                throw new ProductionFeatureException;
            }
            $state = $this->state($row, $context->accountId());
            $version = $state['version'];
            if ($initialize && ($version !== 0 || $state !== $rules->empty($context->accountId()))) {
                throw new ProductionFeatureException(409);
            }
            $reader = $context->reader();
            $evidence = new ProductionListeningPublicEvidence($reader->identityPrimary(), $reader->identityDriver(), DB::connection()->getDatabaseName(), $context->configuration());
            if ($command !== null) {
                if ($command['version'] !== $version || $version >= 2147483646) {
                    throw new ListeningException(409);
                }
                $public = function (string $id) use ($evidence): bool {
                    $evidence->capturePublic([(int) $id]);

                    return count(app(PublicCatalog::class)->selections([(int) $id])['tracks']) === 1;
                };
                $next = $rules->pruneNotes($rules->apply($state, $command, $public, $rollout['promotionEnabled']));
                if ($next !== $state || $command['action'] === 'clear-library') {
                    $next['version'] = ++$version;
                    $at = now()->utc()->format('Y-m-d H:i:s');
                    if ($at < $row['updated_at']) {
                        throw new ProductionFeatureException;
                    }
                    $attributes = ['binding_id' => (int) $binding['row']['id'], 'version' => $version,
                        'payload' => $this->encrypt($next), 'created_at' => $row['created_at'], 'updated_at' => $at];
                    $model = LibraryRow::findOrFail($row['id']);
                    $model->fill($attributes)->save();
                    $context->expected($model->getRawOriginal(), $attributes);
                    // A saved callback can refresh this instance; approve only the intended revision/state.
                    if ($this->state($model->getRawOriginal(), $context->accountId()) !== $next) {
                        throw new ProductionFeatureException;
                    }
                    $physical = $context->observe('production_listening_libraries', ['binding_id' => (int) $binding['row']['id']]);
                    if (count($physical) !== 1 || $this->state($physical[0], $context->accountId()) !== $next
                        || $physical[0]['payload'] !== $attributes['payload']) {
                        throw new ProductionFeatureException;
                    }
                    $row = $physical[0];
                }
                $state = $next;
            }
            if ($expectedExportVersion !== null) {
                if ($expectedExportVersion !== $version) {
                    throw new ListeningException(409);
                }

                return ['exportSchema' => 1, 'feature' => 'customer-listening-library', 'version' => $version,
                    'favorites' => $state['favorites'], 'playlists' => $state['playlists'], 'notes' => $state['notes'] ?? []];
            }
            $tracks = [];
            foreach (array_chunk($rules->references($state), 10) as $chunk) {
                $evidence->capturePublic(array_map('intval', $chunk));
                foreach (app(PublicCatalog::class)->selections(array_map('intval', $chunk))['tracks'] as $track) {
                    $tracks[$track['id']] = ['title' => $track['title'], 'artist' => $track['artist'], 'href' => route('tracks.show', $track['slug'], false)];
                }
            }
            $context->fence(fn (bool $committed) => $evidence->prove($committed));
            $context->guard(fn (bool $committed) => $evidence->close($committed));
            $item = fn (string $id): array => ['trackId' => $id, 'available' => isset($tracks[$id])]
                + (isset($tracks[$id]) ? ['track' => $tracks[$id]] : []);
            $v2 = $state['schema'] === 2 || $rollout['promotionEnabled'];
            $library = ['listeningSchema' => $v2 ? 2 : 1, 'version' => $version,
                'favorites' => array_map($item, $state['favorites']),
                'playlists' => array_map(fn ($playlist) => ['id' => $playlist['id'], 'name' => $playlist['name'], 'tracks' => array_map($item, $playlist['trackIds'])], $state['playlists']),
                ...($v2 ? ['notes' => $state['notes'] ?? []] : []),
                'limits' => ['favorites' => $rules::FAVORITES, 'playlists' => $rules::PLAYLISTS, 'playlistTracks' => $rules::PLAYLIST_TRACKS,
                    ...($v2 ? ['notes' => $rules::NOTES, 'noteCharacters' => $rules::NOTE_CHARACTERS, 'noteBytes' => $rules::NOTE_BYTES] : [])]];

            return ['initialized' => true, 'library' => $library];
        });
    }

    private function encrypt(array $state): string
    {
        $ciphertext = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (strlen($ciphertext) > ProductionListeningState::ENCRYPTED_PAYLOAD_BYTES) {
            throw new ListeningException;
        }

        return $ciphertext;
    }

    private function state(array $row, int $accountId): array
    {
        try {
            if (! is_string($row['payload']) || strlen($row['payload']) > ProductionListeningState::ENCRYPTED_PAYLOAD_BYTES
                || ! ProductionFeatureShape::timestamp($row['created_at']) || ! ProductionFeatureShape::timestamp($row['updated_at'])
                || $row['created_at'] > $row['updated_at']) {
                throw new ProductionFeatureException;
            }
            $state = json_decode(Crypt::decryptString($row['payload']), true, 32, JSON_THROW_ON_ERROR);

            return (new ProductionListeningState)->state($state, $accountId, (int) $row['version']);
        } catch (Throwable) {
            throw new ProductionFeatureException;
        }
    }
}
