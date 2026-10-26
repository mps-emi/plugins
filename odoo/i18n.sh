#!/usr/bin/env bash
# Regenerates payment_mps/i18n/payment_mps.pot from the installed addon, in a throwaway Odoo +
# PostgreSQL pod. Run it whenever a string changes, then fill in nl.po and fr.po (msgmerge keeps
# the existing translations: msgmerge -U payment_mps/i18n/nl.po payment_mps/i18n/payment_mps.pot).
#
# Needs docker or podman.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
cli="${CONTAINER_CLI:-$(command -v docker >/dev/null 2>&1 && echo docker || echo podman)}"
odoo_image="${ODOO_IMAGE:-docker.io/library/odoo:20.0}"
pod=mps-odoo-i18n
conf="$(mktemp -d)"

"$cli" pod exists "$pod" 2>/dev/null && "$cli" pod rm -f "$pod" >/dev/null
trap '"$cli" pod rm -f "$pod" >/dev/null 2>&1 || true; rm -rf "$conf"' EXIT

printf '%s\n' "[options]" "addons_path = /mnt/extra-addons" "db_host = 127.0.0.1" \
  "db_user = odoo" "db_password = odoo" > "$conf/odoo.conf"

"$cli" pod create --name "$pod" >/dev/null
"$cli" run -d --pod "$pod" --name "$pod-db" \
  -e POSTGRES_USER=odoo -e POSTGRES_PASSWORD=odoo -e POSTGRES_DB=postgres \
  docker.io/library/postgres:16 >/dev/null
for _ in $(seq 1 30); do
  "$cli" exec "$pod-db" pg_isready -U odoo >/dev/null 2>&1 && break
  sleep 1
done

odoo() {
  "$cli" run --rm --pod "$pod" --entrypoint odoo -v "$conf:/conf:ro" \
    -v "$here/payment_mps:/mnt/extra-addons/payment_mps" "$odoo_image" "$@"
}

odoo server -c /conf/odoo.conf -d mps_i18n -i payment_mps --stop-after-init --log-level=warn
odoo i18n export -c /conf/odoo.conf -d mps_i18n -o /mnt/extra-addons/payment_mps/i18n/payment_mps.pot payment_mps
echo "Wrote payment_mps/i18n/payment_mps.pot"
