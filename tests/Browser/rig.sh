#!/usr/bin/env bash
# Builds the browser rig's Laravel app OUTSIDE this package — at ../beam-rig, or
# $BEAM_RIG_DIR — from a fresh Laravel skeleton plus the overlay in tests/Browser/rig/.
# The app is never committed here; run this once locally, and CI runs it on every push.
#
#   npm run rig:setup
set -euo pipefail

PKG="$(cd "$(dirname "$0")/../.." && pwd)"
RIG="${BEAM_RIG_DIR:-$PKG/../beam-rig}"
OVERLAY="$PKG/tests/Browser/rig"

if [ ! -f "$RIG/artisan" ]; then
    echo "→ creating a Laravel app at $RIG"
    composer create-project laravel/laravel "$RIG" --no-interaction --prefer-dist --quiet
fi
RIG="$(cd "$RIG" && pwd)"
cd "$RIG"

# This package, symlinked. Outside a git checkout Composer cannot guess a version, so pin one.
if git -C "$PKG" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    composer config repositories.beam "{\"type\": \"path\", \"url\": \"$PKG\", \"options\": {\"symlink\": true}}"
    REQ="realrashid/beam:@dev"
else
    composer config repositories.beam "{\"type\": \"path\", \"url\": \"$PKG\", \"options\": {\"symlink\": true, \"versions\": {\"realrashid/beam\": \"1.0.0\"}}}"
    REQ="realrashid/beam:^1.0"
fi
echo "→ requiring the package and Reverb"
composer require "$REQ" laravel/reverb -W --no-interaction --quiet
php artisan install:broadcasting --reverb --without-node --no-interaction --quiet || true

echo "→ applying the overlay"
cp -R "$OVERLAY"/. "$RIG"/

# Always wins over whatever install:broadcasting left in .env: under --no-interaction
# it sets BROADCAST_CONNECTION=reverb but no key/secret/app id, and Reverb's Pusher-
# protocol client rejects a null auth key outright. .env.rig is a complete fixture —
# APP_KEY aside, nothing from create-project's own .env is needed.
cp .env.rig .env
php artisan key:generate --force --quiet
touch database/database.sqlite
php artisan beam:install --no-interaction --quiet
php artisan migrate --force --quiet

echo "→ vendor assets"
cd "$PKG" && BEAM_RIG_DIR="$RIG" node tests/Browser/rig-assets.mjs

echo "rig ready at $RIG — npx playwright test --project=chromium"
