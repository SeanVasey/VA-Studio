<?php

namespace App\Domain\Catalog\DiscoverySitemap;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;

/** Fixed, one-use public-read request. Store records are private inputs, never browser authority. */
final class DiscoverySitemapConsumption
{
    private ?PDO $primary = null;

    private ?string $marker = null;

    private function __construct(private readonly SitemapStore $store, private readonly array $records,
        private readonly ?CandidateWindow $window, private readonly string $origin, private readonly PDO $preparedPrimary) {}

    public static function prepare(SitemapStore $store, string $publicId, int $slot): self
    {
        $primary = DB::connection()->getPdo();
        $records = $store->readForConsumption($publicId, $slot);
        SitemapException::require(DB::connection()->getPdo() === $primary && DB::transactionLevel() === 0 && ! $primary->inTransaction(), 'changed_transaction');

        return new self($store, $records, $records['candidate'], $records['origin'], $primary);
    }

    /** Bounded locator only; authority is freshly captured and closed against these immutable records later. */
    public function idsForFreshCapture(): array
    {
        SitemapException::require(DB::connection()->getPdo() === $this->preparedPrimary && DB::transactionLevel() === 0 && ! $this->preparedPrimary->inTransaction(), 'changed_connection');

        return $this->window?->ids() ?? [];
    }

    public function assertAfterCommit(PDO $primary): void
    {
        SitemapException::require($this->primary === $primary && $this->marker !== null && DB::transactionLevel() === 0
            && DB::connection()->getPdo() === $primary && ! $primary->inTransaction(), 'changed_transaction');
        $this->store->assertConsumptionCurrent($primary, $this->records);
    }

    public function candidateIds(PDO $primary): array
    {
        SitemapException::require($this->primary === null && $this->preparedPrimary === $primary && DB::transactionLevel() === 1 && DB::connection()->getPdo() === $primary && $primary->inTransaction(), 'changed_transaction');
        $this->primary = $primary;
        $this->marker = 'dsm_consume_'.bin2hex(random_bytes(12));
        $primary->exec('SAVEPOINT '.$this->marker);
        $this->assertCurrent($primary);

        return $this->window?->ids() ?? [];
    }

    public function assertCurrent(PDO $primary): void
    {
        SitemapException::require($this->primary === $primary && $this->marker !== null && DB::transactionLevel() === 1
            && DB::connection()->getPdo() === $primary && $primary->inTransaction(), 'changed_transaction');
        try {
            $primary->exec('RELEASE SAVEPOINT '.$this->marker);
            $primary->exec('SAVEPOINT '.$this->marker);
        } catch (\Throwable) {
            throw new SitemapException('changed_transaction');
        }
        // Exact owned current-pointer/header/requested-window, seals, config and expiry through raw captured PDO only.
        $this->store->assertConsumptionCurrent($primary, $this->records);
    }

    public function generationDeadline(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC((int) $this->records['header']['expires_at']);
    }

    public function epoch(): int
    {
        return (int) $this->records['header']['epoch'];
    }

    public function configurationHash(): string
    {
        return $this->records['header']['configuration_hash'];
    }

    public function xml(array $currentPaths): string
    {
        return SitemapProjection::urlset($this->origin, $currentPaths);
    }

    public function __serialize(): array
    {
        throw new \LogicException('Internal sitemap consumption cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'current_sitemap_read'];
    }
}
