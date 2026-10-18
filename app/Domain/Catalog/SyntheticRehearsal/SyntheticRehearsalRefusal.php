<?php

namespace App\Domain\Catalog\SyntheticRehearsal;

use RuntimeException;

/** A refusal before (admission) or instead of (unexpected existing state) a synthetic rehearsal write. Messages carry no secrets. */
final class SyntheticRehearsalRefusal extends RuntimeException {}
