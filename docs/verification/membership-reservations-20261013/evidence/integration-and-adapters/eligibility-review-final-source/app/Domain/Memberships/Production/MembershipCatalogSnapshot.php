<?php

namespace App\Domain\Memberships\Production;

use PDO;

/** Immutable operation-local evidence, never an authority token or a caller supplied reservation input. */
final readonly class MembershipCatalogSnapshot
{
    public function __construct(
        public PDO $pdo,
        public string $driver,
        public string $schema,
        public array $definitions,
        public array $plans,
        public array $selection,
        public array $files,
        public MembershipLicenseBinding $binding,
        public int $deadlineNs,
    ) {}
}
