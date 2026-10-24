<?php

return [
    // Legacy URL redirects (FP-100). Off until Sean authorizes the domain cutover. Deliberately a code literal, not an
    // environment switch: turning it on is a reviewed change made together with a reviewed map and the cutover packet.
    // LegacyRedirects middleware acts only when this is the literal boolean true.
    'enabled' => false,

    // The reviewed, versioned map: one entry per row of docs/migration/legacy_route_inventory.csv. Check it with
    // `php artisan vasey:redirects-validate` before and after any route or map change.
    'map_path' => resource_path('legacy-redirects/map.json'),

    // The inventory the map is pinned to. Read only by the validation command; the request path never reads it.
    'inventory_path' => base_path('docs/migration/legacy_route_inventory.csv'),
];
