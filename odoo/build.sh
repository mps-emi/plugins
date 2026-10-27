#!/usr/bin/env bash
# Builds the installable addon: runs the tests and writes to dist/
#
#   payment_mps-<version>.zip   the release, never overwritten
#   payment_mps.zip             the same file under a stable name — the docs page links here
#   apps-store/                 the repository the Odoo Apps Store scans: payment_mps/ at its root, to push to
#                               its 20.0 branch (the branch names the Odoo series; see apps-store/listing.md)
#
# Odoo has no update channel for an uploaded addon: a merchant installs a new zip over the old one and
# upgrades the module. The version is the one in payment_mps/__manifest__.py.
#
# Needs docker or podman (the tests run in containers) and zip.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
dist="$here/dist"

version="$(sed -n 's/^ *"version": *"\([^"]*\)".*/\1/p' "$here/payment_mps/__manifest__.py")"
[[ -n "$version" ]] || { echo "No version in payment_mps/__manifest__.py" >&2; exit 1; }
grep -q "^## $version\$" "$here/CHANGELOG.md" || { echo "No '## $version' entry in CHANGELOG.md" >&2; exit 1; }

echo "== tests"
"$here/test.sh"

echo "== zip $version"
# Only what this build writes: dev/check.sh keeps its screenshots under dist/ too.
rm -rf "$dist"/payment_mps*.zip "$dist/apps-store/payment_mps" "$dist/apps-store/README.md"
mkdir -p "$dist/apps-store"
(cd "$here" && zip -qr "$dist/payment_mps-$version.zip" payment_mps \
  -x '*/__pycache__/*' '*.pyc' '*/.DS_Store')
cp "$dist/payment_mps-$version.zip" "$dist/payment_mps.zip"

echo "== apps-store repository"
# The same files as the zip, so what the store installs is what was tested.
unzip -q "$dist/payment_mps-$version.zip" -d "$dist/apps-store"
cp "$here/apps-store/README.md" "$dist/apps-store/README.md"
ls -l "$dist" "$dist/apps-store"
