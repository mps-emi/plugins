#!/usr/bin/env bash
# Builds the installable addon: runs the tests and writes to dist/
#
#   payment_mps-<version>.zip   the release, never overwritten
#   payment_mps.zip             the same file under a stable name — the docs page links here
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
rm -rf "$dist"
mkdir -p "$dist"
(cd "$here" && zip -qr "$dist/payment_mps-$version.zip" payment_mps \
  -x '*/__pycache__/*' '*.pyc' '*/.DS_Store')
cp "$dist/payment_mps-$version.zip" "$dist/payment_mps.zip"
ls -l "$dist"
