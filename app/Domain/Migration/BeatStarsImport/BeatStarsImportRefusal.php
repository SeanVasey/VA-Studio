<?php

namespace App\Domain\Migration\BeatStarsImport;

use RuntimeException;

/** A refusal before (admission) or instead of (unexpected existing state) a BeatStars import write. Messages carry no secrets, paths or catalog values. */
final class BeatStarsImportRefusal extends RuntimeException {}
