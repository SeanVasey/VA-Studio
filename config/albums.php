<?php

return [
    // Public albums page for Sean's own released music (BL-67). A code literal on purpose, not an environment key: the page stays off
    // until a reviewed change flips it once Sean has entered and published releases, so no deployment environment can open it by
    // accident. There is no purchase path. Read only through App\Domain\Albums\AlbumsPolicy.
    'enabled' => false,
];
