# Explicit development dependency fixture

This directory retains 69 exact attributed runtime/migration/test support files
from the provisional paid producer and identity dependency. `source-map.json`
records every source commit and SHA256. These files are preparation fixtures and
are not canonical application registration or production authority. The small
`autoload.php` loader is separately formatted packaging, outside that source map.

Set `VA_PAID_DEVELOPMENT_DEPENDENCY` deliberately and use
`tests/Support/paid-development-bootstrap.php` only when canonical producer code
is absent. If canonical code exists, ordinary Composer classes take priority even
when that environment variable is invalid. No credentials, databases, media,
contracts, provider responses or customer exports are packaged here.

The inherited fresh-checkout command findings and final producer review are
separate prerequisites. Do not treat this snapshot or its successful isolated
checks as approval of the final paid grant consumer. See the checkpoint handoff.
