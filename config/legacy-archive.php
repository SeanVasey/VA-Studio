<?php

/*
 * BeatStars history archive and exclusivity authority (lane MIG). The tooling runs in `local` and `testing` only.
 * A hosted environment is admitted only when this reviewed file names it here; there is deliberately no env()
 * key, so admitting staging or production for the real export is a reviewed code change that needs Sean's
 * authorization (AGENTS.md release boundary). Admission never enables a grant, entitlement or download.
 */
return [
    'non_local_environments' => [],
];
