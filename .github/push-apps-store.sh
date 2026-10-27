#!/usr/bin/env bash
# Pushes the Odoo module to the branch the Odoo Apps Store scans: the Odoo series of its version (20.0 for
# 20.0.1.0.0), holding odoo/dist/apps-store — payment_mps/ at the branch's root, and its README. The store
# lists the module from that branch and picks up each push on its next scan (odoo/apps-store/listing.md).
# The branch has a history of its own; main is never merged into it.
#
# The Release workflow runs it after each Odoo release, on the module it has just built and tested.
# By hand, after ./odoo/build.sh: GH_TOKEN="$(gh auth token)" .github/push-apps-store.sh 20.0.1.0.0
set -euo pipefail

version="$1"
root="$(cd "$(dirname "$0")/.." && pwd)"
repo="${GITHUB_REPOSITORY:-mps-emi/plugins}"
src="$root/odoo/dist/apps-store"
branch="$(echo "$version" | cut -d. -f1-2)"

[[ -f "$src/payment_mps/__manifest__.py" ]] || { echo "No $src/payment_mps: run odoo/build.sh first" >&2; exit 1; }

# A token is handed to git by a credential helper, so it never sits in a URL, the clone's config or a log.
auth=(-c credential.helper= -c 'credential.helper=!f() { echo username=x-access-token; echo "password=$GH_TOKEN"; }; f')

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
git init -q "$work"
cd "$work"
git remote add origin "https://github.com/$repo.git"
if git "${auth[@]}" fetch -q --depth 1 origin "$branch" 2>/dev/null; then
  git checkout -q -b "$branch" FETCH_HEAD
  git rm -rq .
else
  git checkout -q --orphan "$branch"
fi

# Only what build.sh writes for the store: dev/check.sh keeps its screenshots in the same folder.
cp -R "$src/payment_mps" "$src/README.md" .
git add -A
if git diff --cached --quiet; then
  echo "$branch already holds payment_mps $version"
  exit 0
fi

git -c user.name="github-actions[bot]" -c user.email="41898282+github-actions[bot]@users.noreply.github.com" \
  commit -q -m "payment_mps $version"
git "${auth[@]}" push -q origin "HEAD:refs/heads/$branch"
echo "$branch → payment_mps $version"
