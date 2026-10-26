#!/usr/bin/env bash
# Builds the installable module: writes the translations, lints and tests it, and writes to dist/
#
#   mpspayments-<version>.zip   the release, never overwritten
#   mpspayments.zip             the same file under a stable name — the docs page links here
#
# The zip holds the folder mpspayments/, as PrestaShop's Module Manager › Upload a module expects.
#
#   ./build.sh
#
# Needs docker or podman (the lint and the tests run in php:cli containers), python3 and zip.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
dist="$here/dist"

cli="${CONTAINER_CLI:-$(command -v docker >/dev/null 2>&1 && echo docker || echo podman)}"

version="$(sed -n "s/^ *\$this->version = '\(.*\)';$/\1/p" "$here/mpspayments/mpspayments.php")"
if [[ -z "$version" ]]; then
  echo "No version in mpspayments/mpspayments.php" >&2
  exit 1
fi

if ! grep -q "^## $version\$" "$here/CHANGELOG.md"; then
  echo "CHANGELOG.md has no entry for $version" >&2
  exit 1
fi

echo "== translations"
python3 "$here/i18n.py" >/dev/null
python3 "$here/tests/translations.py"

# PHP 7.2 is PrestaShop 8.0's floor (7.2.5); 8.5 is the newest PrestaShop 9.2 runs on.
for php in 7.2 8.5; do
  echo "== lint and tests on PHP $php"
  "$cli" run --rm -v "$here:/src:ro" -w /src "docker.io/library/php:$php-cli" sh -c \
    'find mpspayments -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null && php tests/run.php | tail -1'
done

echo "== zip $version"
rm -rf "$dist"
mkdir -p "$dist"
(cd "$here" && zip -qrX "$dist/mpspayments-$version.zip" mpspayments -x '*/.*')
cp "$dist/mpspayments-$version.zip" "$dist/mpspayments.zip"

echo "== done"
ls -l "$dist"
