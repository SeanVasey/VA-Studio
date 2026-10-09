#!/bin/bash
# Runs PHPUnit directly with this worktree's Composer autoload (avoids the Artisan Collision issue).
# Usage: phpunit.sh [ROOT=dir env] <phpunit args...>
cd "${ROOT:-/home/user/rv-m16}" || exit 1
echo "# source: $(git rev-parse HEAD) root=$(pwd) public/build present: $([ -e public/build ] && echo yes || echo no)"
echo "# git status --short (product paths): $(git status --short -- app config tests resources scripts ops | tr '\n' ' ')"
exec php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --do-not-cache-result "$@"
