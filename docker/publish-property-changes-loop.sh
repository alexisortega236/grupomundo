#!/usr/bin/env bash
set -euo pipefail

interval="${PROPERTY_CHANGES_PUBLISH_INTERVAL_SECONDS:-60}"
limit="${PROPERTY_CHANGES_PUBLISH_LIMIT:-100}"

while true; do
  if ! php artisan property-changes:publish --limit="${limit}"; then
    echo "Property change publisher failed; retrying after ${interval}s." >&2
  fi

  sleep "${interval}"
done
