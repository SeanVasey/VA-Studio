<?php

namespace App\Domain\Catalog\DiscoverySitemap;

use Illuminate\Support\Facades\Crypt;

/** Authenticated structural identities ONLY. No eligible paths, decision clock or expiry to renew. */
final readonly class CandidateWindow
{
    private function __construct(private array $body, private string $sealed) {}

    /** Internal source factory; encryption callbacks MUST precede the source's final raw fence. */
    public static function captured(CandidateBuildRequest $request, array $ids, bool $more): self
    {
        $body = ['purpose' => 'sitemap-candidate-window-v1', 'generation' => $request->generationId(),
            'ordinal' => $request->ordinal(), 'after' => $request->after(), 'end' => $ids === [] ? $request->after() : end($ids),
            'ids' => $ids, 'more' => $more, 'epoch' => $request->epoch(), 'configuration' => $request->configurationHash()];
        self::validate($body);
        $sealed = Crypt::encryptString(json_encode($body, JSON_THROW_ON_ERROR));

        return new self($body, $sealed);
    }

    public static function fromStored(string $sealed): self
    {
        try {
            SitemapException::require(strlen($sealed) <= 16384, 'invalid_window');
            $body = json_decode(Crypt::decryptString($sealed), true, 4, JSON_THROW_ON_ERROR);
            SitemapException::require(is_array($body), 'invalid_window');
            self::validate($body);

            return new self($body, $sealed);
        } catch (\Throwable $error) {
            throw new SitemapException('invalid_window');
        }
    }

    private static function validate(array $body): void
    {
        SitemapException::require(array_keys($body) === ['purpose', 'generation', 'ordinal', 'after', 'end', 'ids', 'more', 'epoch', 'configuration']
            && $body['purpose'] === 'sitemap-candidate-window-v1' && is_string($body['generation']) && is_int($body['ordinal'])
            && is_int($body['after']) && is_int($body['epoch']) && is_string($body['configuration']) && is_int($body['end'])
            && is_bool($body['more']) && is_array($body['ids']) && array_is_list($body['ids']) && count($body['ids']) <= SitemapConfiguration::IDS, 'invalid_window');
        CandidateBuildRequest::forState($body['generation'], $body['ordinal'], $body['after'], $body['epoch'], $body['configuration']);
        $previous = $body['after'];
        foreach ($body['ids'] as $id) {
            SitemapException::require(is_int($id) && $id > $previous, 'invalid_window');
            $previous = $id;
        }
        SitemapException::require($body['end'] === $previous && (! $body['more'] || count($body['ids']) === SitemapConfiguration::IDS), 'invalid_window');
    }

    public function generationId(): string
    {
        return $this->body['generation'];
    }

    public function ordinal(): int
    {
        return $this->body['ordinal'];
    }

    public function after(): int
    {
        return $this->body['after'];
    }

    public function end(): int
    {
        return $this->body['end'];
    }

    public function ids(): array
    {
        return $this->body['ids'];
    }

    public function more(): bool
    {
        return $this->body['more'];
    }

    public function epoch(): int
    {
        return $this->body['epoch'];
    }

    public function configurationHash(): string
    {
        return $this->body['configuration'];
    }

    public function evidence(): string
    {
        return $this->sealed;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Candidate identities cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'candidate_window'];
    }
}
