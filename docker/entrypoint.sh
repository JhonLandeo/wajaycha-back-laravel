#!/usr/bin/env bash
#
# Container entrypoint for the API, the Horizon worker and the scheduler.
#
# The source tree is bind-mounted from the host, so `vendor/` may be absent or
# built against a different platform than this image. This installs it once, on
# the volume the compose file mounts at vendor/, and then hands over to the
# command compose asked for.

set -euo pipefail

log() {
  printf '[entrypoint] %s\n' "$1" >&2
}

cd /app

if [[ ! -f vendor/autoload.php ]]; then
  log 'vendor/ is empty — running composer install (first boot is slow).'
  composer install --no-interaction --prefer-dist
else
  log 'vendor/ present — skipping composer install.'
fi

if [[ ! -f .env ]]; then
  log 'ERROR: .env is missing. Copy .env.example and set APP_KEY before starting.'
  exit 1
fi

# APP_KEY is read from the file rather than the environment: compose does not
# inject it, and an empty key fails at runtime with an opaque decryption error
# instead of something that points at the cause.
#
# Read in pure bash — no grep, no awk. This image ships neither by policy, and a
# missing binary here would fail as "APP_KEY is empty", which is a lie.
has_app_key=0
while IFS= read -r line || [[ -n "$line" ]]; do
  [[ "$line" == 'APP_KEY='?* ]] && { has_app_key=1; break; }
done <.env

if [[ "$has_app_key" -eq 0 ]]; then
  log 'ERROR: APP_KEY is empty in .env. Run: docker compose run --rm backend php artisan key:generate'
  exit 1
fi

# Stale caches from a previous host-side run point at host paths that do not
# exist in the container, and the failure looks like a missing config file.
php artisan config:clear >/dev/null 2>&1 || true

log "starting: $*"
exec "$@"
