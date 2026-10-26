#!/usr/bin/env bash
# Builds the installable module: lints and tests it, and writes to dist/
#
#   mps-module-payments-<version>.zip   the release, never overwritten
#   mps-module-payments.zip             the same file under a stable name — the docs page links here
#
# The zip holds the module at its root, composer.json included, so the one file serves both ways of
# installing it: unzipped into app/code/Mps/Payments, or as a Composer artifact repository.
#
#   ./build.sh
#
# Needs docker or podman (the lint and the tests run in a php:cli container) and zip.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
dist="$here/dist"

cli="${CONTAINER_CLI:-$(command -v docker >/dev/null 2>&1 && echo docker || echo podman)}"

version="$(sed -n 's/^  "version": "\(.*\)",$/\1/p' "$here/mps-payments/composer.json")"
if [[ -z "$version" ]]; then
  echo "No version in mps-payments/composer.json" >&2
  exit 1
fi

if ! grep -q "^## $version\$" "$here/CHANGELOG.md"; then
  echo "CHANGELOG.md has no entry for $version" >&2
  exit 1
fi

# The oldest and the newest PHP the module supports (composer.json: ~8.1.0 … ~8.5.0). 8.1 because
# Magento 2.4.7 still allows it, and the Marketplace refuses a module that narrows that range.
for php in 8.1 8.5; do
  echo "== lint and tests on PHP $php"
  "$cli" run --rm -v "$here:/src:ro" -w /src "docker.io/library/php:$php-cli" sh -c \
    'find mps-payments -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null && php tests/run.php'
done

echo "== zip $version"
# Only the zips: dist/matrix (dev/matrix.sh) and dist/marketplace (marketplace/build.sh) stay.
mkdir -p "$dist"
rm -f "$dist"/*.zip
(cd "$here/mps-payments" && zip -qrX "$dist/mps-module-payments-$version.zip" . -x '.*')
cp "$dist/mps-module-payments-$version.zip" "$dist/mps-module-payments.zip"

echo "== done"
ls -l "$dist"
