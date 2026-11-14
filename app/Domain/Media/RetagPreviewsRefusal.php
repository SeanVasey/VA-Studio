<?php

namespace App\Domain\Media;

use RuntimeException;

/** A deliberate refusal of `vasey:media-retag-previews`; the message is operator-safe and never names a path. */
final class RetagPreviewsRefusal extends RuntimeException {}
