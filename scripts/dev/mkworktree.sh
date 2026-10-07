#!/usr/bin/env bash
# Usage: mkworktree.sh <path> <commit>
# Detached worktree whose vendor/ symlinks each locked package of the main
# checkout but owns its own vendor/composer metadata and generated autoload,
# so App\ and Tests\ resolve to the worktree's source, never the main checkout.
set -euo pipefail
dest=$1; rev=$2; main=${VA_STUDIO_MAIN:-$(git rev-parse --show-toplevel)}
git -C "$main" worktree add --detach "$dest" "$rev" >/dev/null
mkdir -p "$dest/vendor"
for d in "$main"/vendor/*; do
  n=$(basename "$d")
  case "$n" in composer|autoload.php|bin) continue;; esac
  ln -s "$d" "$dest/vendor/$n"
done
mkdir -p "$dest/vendor/composer"
cp "$main"/vendor/composer/installed.json "$main"/vendor/composer/installed.php \
   "$main"/vendor/composer/InstalledVersions.php "$dest/vendor/composer/"
ln -s "$main/vendor/bin" "$dest/vendor/bin"
cp "$main/.env" "$dest/.env"
(cd "$dest" && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload -q 2>&1 | grep -v "Ambiguous class" || true)
echo "worktree $dest at $(git -C "$dest" rev-parse HEAD)"
