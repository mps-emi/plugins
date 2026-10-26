#!/usr/bin/env bash
# Builds the installable plugin: runs the tests, compiles the translations, and writes to dist/
#
#   mps-payments-<version>.zip   the release, never overwritten
#   mps-payments.zip             the same file under a stable name — the docs page links here
#   info.json                    what an installed copy reads to offer the next update
#   mps-payments-marketplace-<version>.zip
#                                the WooCommerce Marketplace upload: no updater and no update URL,
#                                because Woo.com delivers the updates there
#
# PLUGIN_BASE_URL is the public folder these three are uploaded to. It is stamped into the plugin so an
# installed copy knows where to look for updates; without it the zip still works but never updates.
#
#   PLUGIN_BASE_URL=https://<cdn-host>/woocommerce ./build.sh
#
# Needs docker or podman (the tests and the .mo compiler run in containers) and zip.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
dist="$here/dist"
stage="$dist/stage/mps-payments"
market="$dist/stage-marketplace/mps-payments"

cli="${CONTAINER_CLI:-$(command -v docker >/dev/null 2>&1 && echo docker || echo podman)}"
base_url="${PLUGIN_BASE_URL:-}"
base_url="${base_url%/}"

version="$(sed -n 's/^ \* Version: *//p' "$here/mps-payments/mps-payments.php" | tr -d '[:space:]')"
stable_tag="$(sed -n 's/^Stable tag: *//p' "$here/mps-payments/readme.txt" | tr -d '[:space:]')"
if [[ "$version" != "$stable_tag" ]]; then
  echo "Version $version in mps-payments.php differs from Stable tag $stable_tag in readme.txt" >&2
  exit 1
fi

if [[ -n "$base_url" && "$base_url" != https://* ]]; then
  echo "PLUGIN_BASE_URL must be https:// — WordPress downloads the update from it" >&2
  exit 1
fi

echo "== tests"
"$cli" run --rm -v "$here:/src:ro" -w /src docker.io/library/php:8.3-cli php tests/run.php

echo "== stage $version"
# Only what this build writes: dist/lint and dist/marketplace (the listing files) are kept.
rm -rf "$dist/stage" "$dist/stage-marketplace" "$dist"/mps-payments*.zip "$dist/info.json"
mkdir -p "$stage"
cp -R "$here/mps-payments/." "$stage/"
find "$stage/languages" -name '*.mo' -delete

if [[ -n "$base_url" ]]; then
  sed -i.bak "s#define( 'MPS_PAYMENTS_UPDATE_URL', '' );#define( 'MPS_PAYMENTS_UPDATE_URL', '$base_url' );#" "$stage/mps-payments.php"
  rm "$stage/mps-payments.php.bak"
  grep -q "MPS_PAYMENTS_UPDATE_URL', '$base_url'" "$stage/mps-payments.php" || { echo "Could not stamp the update URL" >&2; exit 1; }
fi

echo "== translations"
"$cli" run --rm -v "$stage:/plugin" -w /plugin --user "$(id -u):$(id -g)" \
  docker.io/library/wordpress:cli-php8.3 wp i18n make-mo languages languages

echo "== zip"
(cd "$dist/stage" && zip -qr "$dist/mps-payments-$version.zip" mps-payments)
cp "$dist/mps-payments-$version.zip" "$dist/mps-payments.zip"

# The Marketplace copy: the same stage, translations included, with the self-updater taken out.
mkdir -p "$market"
cp -R "$stage/." "$market/"
rm "$market/includes/class-mps-payments-updater.php"
sed -i.bak "s#define( 'MPS_PAYMENTS_UPDATE_URL', '.*' );#define( 'MPS_PAYMENTS_UPDATE_URL', '' );#" "$market/mps-payments.php"
rm "$market/mps-payments.php.bak"
grep -q "define( 'MPS_PAYMENTS_UPDATE_URL', '' );" "$market/mps-payments.php" || { echo "Could not empty the update URL for the Marketplace zip" >&2; exit 1; }
(cd "$dist/stage-marketplace" && zip -qr "$dist/mps-payments-marketplace-$version.zip" mps-payments)

# This version's changelog entry from readme.txt, as the HTML list the update dialog shows.
changelog="$(awk -v v="= $version =" '
  $0 == v { on = 1; next }
  on && /^= / { exit }
  on && /^\* / { sub(/^\* /, ""); printf "<li>%s", $0; next }
  on && /^  / { sub(/^ +/, " "); printf "%s", $0; next }
' "$here/mps-payments/readme.txt" | sed 's#<li>#</li><li>#g; s#^</li>##')"
[[ -n "$changelog" ]] && changelog="<ul>$changelog</li></ul>"

download_url="${base_url:+$base_url/}mps-payments-$version.zip"
requires="$(sed -n 's/^Requires at least: *//p' "$here/mps-payments/readme.txt" | tr -d '[:space:]')"
requires_php="$(sed -n 's/^Requires PHP: *//p' "$here/mps-payments/readme.txt" | tr -d '[:space:]')"
tested="$(sed -n 's/^Tested up to: *//p' "$here/mps-payments/readme.txt" | tr -d '[:space:]')"

cat > "$dist/info.json" <<JSON
{
  "version": "$version",
  "download_url": "$download_url",
  "requires": "$requires",
  "requires_php": "$requires_php",
  "tested": "$tested",
  "changelog": "${changelog//\"/\\\"}"
}
JSON

rm -rf "$dist/stage" "$dist/stage-marketplace"
echo "== done"
ls -l "$dist"/mps-payments*.zip "$dist/info.json"
