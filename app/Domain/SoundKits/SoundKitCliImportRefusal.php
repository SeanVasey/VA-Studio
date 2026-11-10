<?php

namespace App\Domain\SoundKits;

use RuntimeException;

/** A named, operator-readable refusal raised before the kit CLI import writes anything. */
final class SoundKitCliImportRefusal extends RuntimeException {}
