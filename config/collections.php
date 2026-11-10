<?php

return [
    // Public collections: browse groupings of individually licensed tracks (BL-54, FP-006). A code literal on purpose, not an
    // environment key: the pages stay off until a reviewed change flips it once Sean has built and published collections, so no
    // deployment environment can open them by accident. A collection has no price and no purchase path of its own.
    // Read only through App\Domain\Collections\CollectionsPolicy.
    'enabled' => false,
];
