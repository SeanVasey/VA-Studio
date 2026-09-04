<?php

namespace Tests\Support;

use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;

/** Deliberately synthetic evidence; production services reject this engine. */
class TestOnlyMediaScanner extends MalwareScanner
{
    public bool $reject = false;

    public function scan(string $path): array
    {
        if (! app()->environment('testing') || $this->reject) {
            throw new MediaFailure('scan_not_clean', 'Synthetic scanner rejected this test fixture.');
        }

        return ['engine' => 'test-only', 'version' => 'synthetic-test-fixture-v1', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
    }
}
