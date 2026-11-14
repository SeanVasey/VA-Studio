#!/bin/bash
# Re-pin the sound-kit license profile (vasey-kit-license-pdf-v1) in one command, after a tc-lib update, a v1/v2 re-pin or any
# reviewed change to a file its implementation closure names:
#   1. rebuild profile-assets.json and rewrite VaseyKitLicenseProfile::MANIFEST_SHA256;
#   2. re-render the synthetic golden document and rewrite tests/Fixtures/vasey-kit-license/golden.json.
# Run from the repository root with the v1 and v2 profiles already re-pinned (their --check scripts must pass first), a
# `public/build`-free tree and the same PHP environment the PHP tests use. Review the resulting diff: it is the pin change.
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/build-contract-profile-vasey-kit-license-v1.php
[ -e public/build ] && { echo "public/build present; refusing (Inertia tests need it absent)" >&2; exit 2; }
env APP_ENV=testing APP_DEBUG=false APP_KEY="base64:$(head -c32 /dev/zero | base64)" DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array \
  SESSION_DRIVER=array QUEUE_CONNECTION=sync PAO_DISABLE=1 VASEY_KIT_REPIN=1 \
  php -d memory_limit=1024M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- \
  --colors=never --filter test_the_synthetic_license_renders_in_the_shared_visual_system tests/Unit/VaseyKitLicensePdfRendererTest.php
php scripts/build-contract-profile-vasey-kit-license-v1.php --check
