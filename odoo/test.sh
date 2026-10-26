#!/usr/bin/env bash
# Runs the addon's tests in a throwaway Odoo + PostgreSQL pod: installs payment_mps into an empty
# database and runs every test tagged for it. Exits non-zero when any test fails or errors.
#
#   ./test.sh
#
# Needs docker or podman.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
cli="${CONTAINER_CLI:-$(command -v docker >/dev/null 2>&1 && echo docker || echo podman)}"
odoo_image="${ODOO_IMAGE:-docker.io/library/odoo:20.0}"
pod=mps-odoo-test

"$cli" pod exists "$pod" 2>/dev/null && "$cli" pod rm -f "$pod" >/dev/null
trap '"$cli" pod rm -f "$pod" >/dev/null 2>&1 || true' EXIT

"$cli" pod create --name "$pod" >/dev/null
"$cli" run -d --pod "$pod" --name "$pod-db" \
  -e POSTGRES_USER=odoo -e POSTGRES_PASSWORD=odoo -e POSTGRES_DB=postgres \
  docker.io/library/postgres:16 >/dev/null

for _ in $(seq 1 30); do
  "$cli" exec "$pod-db" pg_isready -U odoo >/dev/null 2>&1 && break
  sleep 1
done

log="$(mktemp)"
# The image's entrypoint builds the database flags from HOST, USER and PASSWORD.
"$cli" run --rm --pod "$pod" -e HOST=127.0.0.1 -e USER=odoo -e PASSWORD=odoo \
  -v "$here/payment_mps:/mnt/extra-addons/payment_mps:ro" "$odoo_image" \
  odoo -d mps_test \
  -i payment_mps --test-tags /payment_mps --stop-after-init --log-level=test 2>&1 | tee "$log" \
  | grep -E "(ERROR|FAIL|Ran [0-9]+ tests|tests? (failed|passed)|payment_mps: )" || true

if grep -qE " (ERROR|CRITICAL) " "$log" || ! grep -qE "payment_mps: [0-9]+ tests" "$log"; then
  echo "Tests failed; full log: $log" >&2
  exit 1
fi
echo "All passed."
