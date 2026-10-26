#!/usr/bin/env bash
# Points the release <plugin>-latest at the release <plugin>-v<version>: the very same zip, under the
# plugin's stable name, so this address always serves the newest version and never changes:
#
#   https://github.com/mps-emi/plugins/releases/download/<plugin>-latest/<stable name>.zip
#
# The Release workflow runs it after each release. By hand: GH_TOKEN=… .github/update-latest.sh woocommerce 0.1.0
set -euo pipefail

plugin="$1"
version="$2"
repo="${GITHUB_REPOSITORY:-mps-emi/plugins}"
tag="$plugin-v$version"
latest="$plugin-latest"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# The released zip, renamed to the stable name the build also writes: mps-payments-0.1.0.zip → mps-payments.zip.
gh release download "$tag" -R "$repo" -p '*.zip' -D "$work"
for zip in "$work"/*-"$version".zip; do
  mv "$zip" "${zip%-"$version".zip}.zip"
done

sha="$(gh api "repos/$repo/git/ref/tags/$tag" --jq .object.sha)"
title="$plugin latest ($version)"
notes="The newest $plugin release, [$tag](https://github.com/$repo/releases/tag/$tag), under a name that does not change. Every version: [releases](https://github.com/$repo/releases?q=$plugin&expanded=true)."

if gh release view "$latest" -R "$repo" >/dev/null 2>&1; then
  gh api -X PATCH "repos/$repo/git/refs/tags/$latest" -f sha="$sha" -F force=true >/dev/null
  gh release upload "$latest" "$work"/*.zip -R "$repo" --clobber
  gh release edit "$latest" -R "$repo" --title "$title" --notes "$notes" --latest=false
else
  gh release create "$latest" "$work"/*.zip -R "$repo" --target "$sha" --title "$title" --notes "$notes" --latest=false
fi
echo "$latest → $tag"
