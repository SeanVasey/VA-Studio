<?php

namespace App\Domain\Catalog\DiscoverySitemap;

use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/** Immutable private build log; the sole mutable row is a compare-and-swap publication pointer. */
final class SitemapStore
{
    public function __construct(private readonly SitemapSchema $schema = new SitemapSchema) {}

    /** Mint before enqueueing. The encrypted private request makes unknown-ack start retries exact. */
    public function newRequest(): string
    {
        SitemapConfiguration::assertEnabled();
        $at = CarbonImmutable::instance(now())->utc()->getTimestamp();
        $configuration = SitemapConfiguration::hash();
        $epoch = $this->sourceEpoch();
        $body = ['purpose' => 'private-sitemap-producer-v1', 'id' => bin2hex(random_bytes(16)), 'secret' => bin2hex(random_bytes(32)),
            'created_at' => $at, 'expires_at' => $at + SitemapConfiguration::GENERATION_SECONDS, 'epoch' => $epoch, 'configuration_hash' => $configuration];
        $sealed = Crypt::encryptString(CanonicalJson::encode($body));
        $this->assertConfiguration($configuration, $body['expires_at']);
        SitemapException::require($this->sourceEpoch() === $epoch, 'changed_epoch');

        return $sealed;
    }

    public function start(string $request): array
    {
        $body = $this->producer($request);
        $header = $this->headerFor($body);
        $row = ['id' => $body['id'], 'header' => CanonicalJson::encode($header)];
        $row['seal'] = $this->seal('generation', $row);

        return $this->transaction(function (PDO $pdo) use ($body, $row): array {
            $this->pointer($pdo, true);
            $existing = $this->generation($pdo, $body['id'], true);
            if ($existing === null) {
                $this->insert($pdo, SitemapSchema::TABLES[0], $row);
            } else {
                SitemapException::require($existing === $row, 'request_conflict');
            }
            $last = $this->last($pdo, $body['id'], true);
            if ($last !== null) {
                $this->candidate($last, $row);
            }
            $this->fence($pdo, $body['epoch'], $body['configuration_hash'], $body['expires_at']);

            return $last === null ? ['generation' => $body['id'], 'state' => 'generating', 'committed_windows' => 0] : $this->progress($last);
        });
    }

    /** One bounded step. Replaying an exact committed ordinal never scans or appends again. */
    public function step(string $request, int $expectedOrdinal): array
    {
        SitemapException::require($expectedOrdinal >= 1 && $expectedOrdinal <= SitemapConfiguration::SLOTS, 'invalid_ordinal');
        $body = $this->producer($request);
        $state = $this->transaction(function (PDO $pdo) use ($body, $expectedOrdinal): array {
            $this->pointer($pdo, true);
            $generation = $this->ownedGeneration($pdo, $body, true);
            $existing = $this->window($pdo, $body['id'], $expectedOrdinal, true);
            if ($existing !== null) {
                $this->candidate($existing, $generation);
                $this->fence($pdo, $body['epoch'], $body['configuration_hash'], $body['expires_at']);

                return ['replay' => $this->progress($existing)];
            }
            $last = $this->last($pdo, $body['id'], true);
            SitemapException::require($expectedOrdinal === ($last === null ? 1 : (int) $last['ordinal'] + 1) && ($last === null || (int) $last['more'] === 1), 'progress_conflict', 409);
            if ($last !== null) {
                $this->candidate($last, $generation);
            }
            $this->fence($pdo, $body['epoch'], $body['configuration_hash'], $body['expires_at']);

            return ['generation' => $generation, 'after' => $last === null ? 0 : (int) $last['end_id'], 'prior' => $last['seal'] ?? str_repeat('0', 64)];
        });
        if (isset($state['replay'])) {
            return $state['replay'];
        }
        $capture = CandidateBuildRequest::forState($body['id'], $expectedOrdinal, $state['after'], $body['epoch'], $body['configuration_hash']);
        $window = app(CurrentEligibleTrackSnapshot::class)->captureIdentities($capture);

        return $this->append($request, $window, $state['prior']);
    }

    /** Internal API verifies the actual source partition again; a server-minted partial window is insufficient. */
    public function append(string $request, CandidateWindow $window, string $prior): array
    {
        $body = $this->producer($request);
        SitemapException::require($window->generationId() === $body['id'] && $window->epoch() === $body['epoch']
            && hash_equals($body['configuration_hash'], $window->configurationHash()), 'invalid_window');
        $expected = app(CurrentEligibleTrackSnapshot::class)->captureIdentities(CandidateBuildRequest::forState($body['id'], $window->ordinal(), $window->after(), $body['epoch'], $body['configuration_hash']));
        SitemapException::require($expected->ids() === $window->ids() && $expected->more() === $window->more(), 'incomplete_window');
        $row = ['generation_id' => $body['id'], 'ordinal' => $window->ordinal(), 'after_id' => $window->after(), 'end_id' => $window->end(),
            'more' => $window->more() ? 1 : 0, 'prior_hash' => $prior, 'body' => $window->evidence()];
        $row['seal'] = $this->seal('window', $row);

        return $this->transaction(function (PDO $pdo) use ($body, $row, $window): array {
            $this->pointer($pdo, true);
            $generation = $this->ownedGeneration($pdo, $body, true);
            $existing = $this->window($pdo, $body['id'], $window->ordinal(), true);
            if ($existing !== null) {
                $stored = $this->candidate($existing, $generation);
                SitemapException::require($stored->ids() === $window->ids() && $stored->more() === $window->more() && $stored->after() === $window->after()
                    && $existing['prior_hash'] === $row['prior_hash'], 'progress_conflict', 409);
                $row = $existing;
            } else {
                $last = $this->last($pdo, $body['id'], true);
                SitemapException::require(($last === null && $row['ordinal'] === 1 && $row['after_id'] === 0 && $row['prior_hash'] === str_repeat('0', 64))
                    || ($last !== null && (int) $last['more'] === 1 && $row['ordinal'] === (int) $last['ordinal'] + 1 && $row['after_id'] === (int) $last['end_id'] && hash_equals($last['seal'], $row['prior_hash'])), 'progress_conflict', 409);
                $this->insert($pdo, SitemapSchema::TABLES[1], $row);
            }
            $this->fence($pdo, $body['epoch'], $body['configuration_hash'], $body['expires_at']);

            return $this->progress($row);
        });
    }

    public function status(string $request): array
    {
        $body = $this->producer($request);

        return $this->transaction(function (PDO $pdo) use ($body): array {
            $pointer = $this->pointer($pdo, true);
            $generation = $this->ownedGeneration($pdo, $body, true);
            $last = $this->last($pdo, $body['id'], true);
            if ($last !== null) {
                $this->candidate($last, $generation);
            }
            $this->fence($pdo, $body['epoch'], $body['configuration_hash'], $body['expires_at']);

            return $last === null ? ['generation' => $body['id'], 'state' => 'generating', 'committed_windows' => 0]
                : $this->progress($last) + ['published' => $pointer['generation_id'] === $body['id']];
        });
    }

    /** Full bounded chain verification is private, never performed by a public reader. */
    public function publish(string $request, int $expectedPointerRevision): string
    {
        $body = $this->producer($request);
        SitemapException::require($expectedPointerRevision >= 0 && $expectedPointerRevision < 2147483647, 'invalid_revision');

        return $this->transaction(function (PDO $pdo) use ($body, $expectedPointerRevision): string {
            $pointer = $this->pointer($pdo, true);
            $generation = $this->ownedGeneration($pdo, $body, true);
            // An unknown publication acknowledgement may replay only its exact already-committed successor.
            if ($pointer['generation_id'] === $body['id'] && (int) $pointer['revision'] === $expectedPointerRevision + 1) {
                $this->certificate($pointer, $generation);
                $this->fence($pdo, $body['epoch'], $body['configuration_hash'], $body['expires_at']);

                return $body['id'];
            }
            SitemapException::require((int) $pointer['revision'] === $expectedPointerRevision, 'pointer_conflict', 409);
            $s = $pdo->prepare('SELECT * FROM '.$this->schema->table(SitemapSchema::TABLES[1]).' WHERE generation_id = ? ORDER BY ordinal LIMIT 129'.$this->lock($pdo));
            $s->execute([$body['id']]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            SitemapException::require(count($rows) >= 1 && count($rows) <= SitemapConfiguration::SLOTS, 'incomplete_generation');
            $after = 0;
            $prior = str_repeat('0', 64);
            foreach ($rows as $i => $row) {
                $candidate = $this->candidate($row, $generation);
                SitemapException::require($candidate->ordinal() === $i + 1 && $candidate->after() === $after && hash_equals($prior, $row['prior_hash'])
                    && ($i === count($rows) - 1 ? ! $candidate->more() : $candidate->more()), 'incomplete_generation');
                $after = $candidate->end();
                $prior = $row['seal'];
            }
            $certificate = CanonicalJson::encode(['purpose' => 'complete-candidate-partition-v1', 'generation' => $body['id'], 'header_seal' => $generation['seal'],
                'windows' => count($rows), 'terminal_seal' => $prior, 'epoch' => $body['epoch'], 'configuration_hash' => $body['configuration_hash']]);
            $new = ['id' => 1, 'revision' => $expectedPointerRevision + 1, 'generation_id' => $body['id'], 'certificate' => $certificate];
            $new['seal'] = $this->seal('pointer', $new);
            $s = $pdo->prepare('UPDATE '.$this->schema->table(SitemapSchema::TABLES[2]).' SET revision = ?, generation_id = ?, certificate = ?, seal = ? WHERE id = 1 AND revision = ? AND seal = ?');
            $s->execute([$new['revision'], $new['generation_id'], $new['certificate'], $new['seal'], $expectedPointerRevision, $pointer['seal']]);
            SitemapException::require($s->rowCount() === 1, 'pointer_conflict', 409);
            $this->fence($pdo, $body['epoch'], $body['configuration_hash'], $body['expires_at']);

            return $body['id'];
        });
    }

    /** Exactly header/pointer and one indexed child; no count, catalog graph, last-window or all-window reads. */
    public function readForConsumption(string $publicId, int $slot): array
    {
        SitemapException::require(preg_match('/\A[a-f0-9]{32}\z/D', $publicId) === 1 && $slot >= 1 && $slot <= SitemapConfiguration::SLOTS, 'unavailable', 404);
        $origin = SitemapConfiguration::origin();

        return $this->transaction(function (PDO $pdo) use ($publicId, $slot, $origin): array {
            $pointer = $this->pointer($pdo, true);
            SitemapException::require($pointer['generation_id'] === $publicId, 'unavailable', 404);
            $generation = $this->generation($pdo, $publicId, true);
            SitemapException::require($generation !== null, 'corrupt_generation');
            $header = $this->header($generation);
            $certificate = $this->certificate($pointer, $generation);
            $window = $this->window($pdo, $publicId, $slot, true);
            SitemapException::require(($slot <= $certificate['windows']) === ($window !== null), 'corrupt_window');
            $candidate = $window === null ? null : $this->candidate($window, $generation);
            if ($window !== null) {
                SitemapException::require($candidate->more() === ($slot < $certificate['windows']), 'corrupt_window');
                if ($slot === $certificate['windows']) {
                    SitemapException::require(hash_equals($window['seal'], $certificate['terminal_seal']), 'corrupt_window');
                }
            }
            $records = compact('pointer', 'generation', 'header', 'window', 'candidate', 'origin', 'slot');
            $this->assertConsumptionCurrent($pdo, $records);
            $this->fence($pdo, $header['epoch'], $header['configuration_hash'], $header['expires_at']);

            return $records;
        });
    }

    /** Callback-free raw closure, usable also after Laravel commit events have completed. */
    public function assertConsumptionCurrent(PDO $pdo, array $records): void
    {
        $this->schema->assertOwned($pdo);
        SitemapException::require($this->pointer($pdo) === $records['pointer'] && $this->generation($pdo, $records['generation']['id']) === $records['generation']
            && $this->window($pdo, $records['generation']['id'], $records['slot']) === $records['window'], 'changed_manifest');
        $this->assertConfiguration($records['header']['configuration_hash'], $records['header']['expires_at']);
    }

    /** Root registration may call this whole, already serialized index; no post-proof XML assembly. */
    public function currentIndexXml(): string
    {
        $origin = SitemapConfiguration::origin();
        $records = null;
        $xml = $this->transaction(function (PDO $pdo) use ($origin, &$records): string {
            $pointer = $this->pointer($pdo, true);
            SitemapException::require(is_string($pointer['generation_id']), 'no_current_generation', 404);
            $generation = $this->generation($pdo, $pointer['generation_id'], true);
            SitemapException::require($generation !== null, 'corrupt_generation');
            $header = $this->header($generation);
            $this->certificate($pointer, $generation);
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            $paths = ['/site-pages-sitemap.xml'];
            for ($slot = 1; $slot <= SitemapConfiguration::SLOTS; $slot++) {
                $paths[] = '/track-sitemaps/'.$generation['id'].'/'.$slot.'.xml';
            }
            foreach ($paths as $path) {
                $xml .= '<sitemap><loc>'.htmlspecialchars($origin.$path, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></sitemap>';
            }
            $xml .= '</sitemapindex>'."\n";
            SitemapException::require(strlen($xml) <= SitemapConfiguration::INDEX_BYTES, 'xml_limit');
            $records = compact('pointer', 'generation', 'header');
            $this->assertIndexCurrent($pdo, $records);
            $this->fence($pdo, $header['epoch'], $header['configuration_hash'], $header['expires_at']);

            return $xml;
        });
        $pdo = DB::connection()->getPdo();
        $this->assertIndexCurrent($pdo, $records);
        $this->fence($pdo, $records['header']['epoch'], $records['header']['configuration_hash'], $records['header']['expires_at'], false);

        return $xml;
    }

    private function assertIndexCurrent(PDO $pdo, array $records): void
    {
        $this->schema->assertOwned($pdo);
        SitemapException::require($this->pointer($pdo) === $records['pointer'] && $this->generation($pdo, $records['generation']['id']) === $records['generation'], 'changed_manifest');
        // An expired generation is a harmless omission only after epoch and config remain authentic.
        $this->fence($pdo, $records['header']['epoch'], $records['header']['configuration_hash'], $records['header']['expires_at'], false);
    }

    private function producer(string $sealed): array
    {
        SitemapConfiguration::assertEnabled();
        try {
            SitemapException::require(strlen($sealed) <= 8192, 'invalid_request');
            $body = json_decode(Crypt::decryptString($sealed), true, 4, JSON_THROW_ON_ERROR);
            $keys = ['configuration_hash', 'created_at', 'epoch', 'expires_at', 'id', 'purpose', 'secret'];
            SitemapException::require(is_array($body) && array_keys($body) === $keys && $body['purpose'] === 'private-sitemap-producer-v1'
                && is_string($body['secret']) && preg_match('/\A[a-f0-9]{64}\z/D', $body['secret']) === 1, 'invalid_request');
            $header = $this->headerFor($body);
            $this->validateHeader($header);
            $this->assertConfiguration($body['configuration_hash'], $body['expires_at']);

            return $body;
        } catch (Throwable $error) {
            throw $error instanceof SitemapException ? $error : new SitemapException('invalid_request');
        }
    }

    private function headerFor(array $body): array
    {
        return $this->sorted(['purpose' => 'private-generation-v1', 'id' => $body['id'], 'created_at' => $body['created_at'], 'expires_at' => $body['expires_at'],
            'epoch' => $body['epoch'], 'configuration_hash' => $body['configuration_hash'], 'producer_hash' => hash('sha256', $body['secret'])]);
    }

    private function header(array $row): array
    {
        $this->assertSeal('generation', $row);
        try {
            $header = json_decode($row['header'], true, 4, JSON_THROW_ON_ERROR);
            $this->validateHeader($header);
            SitemapException::require($row['id'] === $header['id'], 'corrupt_generation');

            return $header;
        } catch (Throwable) {
            throw new SitemapException('corrupt_generation');
        }
    }

    private function validateHeader(mixed $header): void
    {
        SitemapException::require(is_array($header) && array_keys($header) === ['configuration_hash', 'created_at', 'epoch', 'expires_at', 'id', 'producer_hash', 'purpose']
            && $header['purpose'] === 'private-generation-v1' && is_int($header['created_at']) && is_int($header['expires_at'])
            && $header['expires_at'] - $header['created_at'] === SitemapConfiguration::GENERATION_SECONDS && is_string($header['producer_hash'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $header['producer_hash']) === 1, 'corrupt_generation');
        CandidateBuildRequest::forState($header['id'], 1, 0, $header['epoch'], $header['configuration_hash']);
    }

    private function candidate(array $row, array $generation): CandidateWindow
    {
        $this->assertSeal('window', $row);
        $header = $this->header($generation);
        $candidate = CandidateWindow::fromStored($row['body']);
        SitemapException::require($candidate->generationId() === $generation['id'] && $candidate->ordinal() === (int) $row['ordinal']
            && $candidate->after() === (int) $row['after_id'] && $candidate->end() === (int) $row['end_id'] && ($candidate->more() ? 1 : 0) === (int) $row['more']
            && $candidate->epoch() === $header['epoch'] && hash_equals($candidate->configurationHash(), $header['configuration_hash']), 'corrupt_window');

        return $candidate;
    }

    private function certificate(array $pointer, array $generation): array
    {
        $header = $this->header($generation);
        $this->assertSeal('pointer', $pointer);
        try {
            $body = json_decode($pointer['certificate'], true, 4, JSON_THROW_ON_ERROR);
            SitemapException::require(is_array($body) && array_keys($body) === ['configuration_hash', 'epoch', 'generation', 'header_seal', 'purpose', 'terminal_seal', 'windows']
                && $body['purpose'] === 'complete-candidate-partition-v1' && $body['generation'] === $generation['id'] && $pointer['generation_id'] === $generation['id']
                && hash_equals($generation['seal'], $body['header_seal']) && $body['epoch'] === $header['epoch'] && hash_equals($header['configuration_hash'], $body['configuration_hash'])
                && is_int($body['windows']) && $body['windows'] >= 1 && $body['windows'] <= SitemapConfiguration::SLOTS
                && is_string($body['terminal_seal']) && preg_match('/\A[a-f0-9]{64}\z/D', $body['terminal_seal']) === 1, 'corrupt_completion');

            return $body;
        } catch (Throwable) {
            throw new SitemapException('corrupt_completion');
        }
    }

    private function ownedGeneration(PDO $pdo, array $body, bool $lock): array
    {
        $row = $this->generation($pdo, $body['id'], $lock);
        SitemapException::require($row !== null, 'unknown_generation');
        SitemapException::require($this->header($row) === $this->sorted($this->headerFor($body)), 'invalid_request');

        return $row;
    }

    private function sorted(array $body): array
    {
        ksort($body);

        return $body;
    }

    private function progress(array $row): array
    {
        return ['generation' => $row['generation_id'], 'committed_windows' => (int) $row['ordinal'],
            'state' => (int) $row['more'] === 0 ? 'complete' : ((int) $row['ordinal'] === SitemapConfiguration::SLOTS ? 'overflow' : 'generating')];
    }

    private function seal(string $purpose, array $row): string
    {
        unset($row['seal']);
        foreach (['ordinal', 'after_id', 'end_id', 'more', 'id', 'revision'] as $numeric) {
            if (isset($row[$numeric]) && $numeric !== 'id') {
                $row[$numeric] = (int) $row[$numeric];
            }
        }
        if ($purpose === 'pointer') {
            $row['id'] = (int) $row['id'];
        }

        return hash_hmac('sha256', CanonicalJson::encode(['purpose' => 'sitemap-'.$purpose.'-v1', 'row' => $row]), (string) config('app.key'));
    }

    private function assertSeal(string $purpose, array $row): void
    {
        SitemapException::require(is_string($row['seal']) && hash_equals($row['seal'], $this->seal($purpose, $row)), 'corrupt_'.$purpose);
    }

    private function pointer(PDO $pdo, bool $lock = false): array
    {
        $rows = $pdo->query('SELECT * FROM '.$this->schema->table(SitemapSchema::TABLES[2]).$this->lock($pdo, $lock))->fetchAll(PDO::FETCH_ASSOC);
        SitemapException::require(count($rows) === 1 && (int) $rows[0]['id'] === 1 && (int) $rows[0]['revision'] >= 0 && (int) $rows[0]['revision'] <= 2147483647, 'corrupt_pointer');
        $row = $rows[0];
        if ((int) $row['revision'] === 0) {
            SitemapException::require($row['generation_id'] === null && $row['certificate'] === null && $row['seal'] === str_repeat('0', 64), 'corrupt_pointer');
        } else {
            $this->assertSeal('pointer', $row);
        }

        return $row;
    }

    private function generation(PDO $pdo, string $id, bool $lock = false): ?array
    {
        return $this->one($pdo, SitemapSchema::TABLES[0], 'id = ?', [$id], $lock);
    }

    private function window(PDO $pdo, string $id, int $slot, bool $lock = false): ?array
    {
        return $this->one($pdo, SitemapSchema::TABLES[1], 'generation_id = ? AND ordinal = ?', [$id, $slot], $lock);
    }

    private function last(PDO $pdo, string $id, bool $lock): ?array
    {
        return $this->one($pdo, SitemapSchema::TABLES[1], 'generation_id = ? ORDER BY ordinal DESC LIMIT 1', [$id], $lock);
    }

    private function one(PDO $pdo, string $table, string $where, array $bindings, bool $lock): ?array
    {
        $s = $pdo->prepare('SELECT * FROM '.$this->schema->table($table).' WHERE '.$where.$this->lock($pdo, $lock));
        $s->execute($bindings);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        SitemapException::require(count($rows) <= 1, 'corrupt_rows');

        return $rows[0] ?? null;
    }

    private function insert(PDO $pdo, string $table, array $row): void
    {
        $s = $pdo->prepare('INSERT INTO '.$this->schema->table($table).' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
        $s->execute(array_values($row));
    }

    private function lock(PDO $pdo, bool $lock = true): string
    {
        return $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function transaction(callable $work): mixed
    {
        SitemapConfiguration::assertEnabled();
        $connection = DB::connection();
        SitemapException::require($connection->transactionLevel() === 0 && $connection->getTablePrefix() === '', 'changed_transaction');
        $pdo = $connection->getPdo();
        $this->schema->assertOwned($pdo);
        if ($connection->getDriverName() === 'mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return $connection->transaction(function () use ($work, $connection, $pdo) {
            // SQLite's main writer fence mirrors native pointer-first ownership without changing revision.
            if ($connection->getDriverName() === 'sqlite') {
                $pdo->exec('UPDATE '.DiscoveryEpoch::TABLE.' SET epoch = epoch WHERE id = 1');
            }
            $marker = 'dsm_store_'.bin2hex(random_bytes(12));
            $pdo->exec('SAVEPOINT '.$marker);
            $result = $work($pdo);
            SitemapException::require(DB::connection() === $connection && $connection->getPdo() === $pdo && $connection->transactionLevel() === 1 && $pdo->inTransaction(), 'changed_transaction');
            try {
                $pdo->exec('RELEASE SAVEPOINT '.$marker);
            } catch (Throwable) {
                throw new SitemapException('changed_transaction');
            }

            return $result;
        });
    }

    private function sourceEpoch(): int
    {
        SitemapException::require(DB::transactionLevel() === 0 && DB::connection()->getTablePrefix() === '', 'changed_transaction');
        $pdo = DB::connection()->getPdo();
        $epochs = new DiscoveryEpoch;
        $epochs->assertInstalled($pdo, DB::getDriverName());

        return $epochs->current($pdo);
    }

    private function fence(PDO $pdo, int $epoch, string $configuration, int $expires, bool $lock = true): void
    {
        $epochs = new DiscoveryEpoch;
        $epochs->assertInstalled($pdo, DB::getDriverName());
        SitemapException::require($epochs->current($pdo, $lock) === $epoch, 'changed_epoch');
        $this->assertConfiguration($configuration, $expires);
    }

    private function assertConfiguration(string $configuration, int $expires): void
    {
        SitemapConfiguration::assertEnabled();
        SitemapException::require(hash_equals($configuration, SitemapConfiguration::hash()), 'changed_configuration');
        SitemapException::require(CarbonImmutable::instance(now())->utc()->getTimestamp() < $expires, 'expired_generation');
    }
}
