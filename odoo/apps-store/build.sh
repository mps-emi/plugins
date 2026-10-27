#!/usr/bin/env bash
# Writes the Odoo Apps Store images into payment_mps/static/description/, where the store reads them from the
# repository (they are committed, and ship in the zip):
#
#   banner.png                 the cover (1500 × 750, from banner.html): the listing thumbnail, the manifest's `images`
#   screenshot_*.png           the screenshots index.html shows (1920 × 1200), saved by dev/check.sh
#
#   SHOTS="$PWD/dist/apps-store/screenshots" ./dev/check.sh && ./apps-store/build.sh
#
# Needs node and npm (Playwright, as dev/browser uses it). See listing.md for what goes where.
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
shots="$here/dist/apps-store/screenshots"
out="$here/payment_mps/static/description"

# What dev/check.sh saves → what index.html shows.
pairs=(1-provider-settings:settings 2-checkout:checkout 3-order-confirmation:confirmation 4-transaction:transaction)
for pair in "${pairs[@]}"; do
  if [[ ! -f "$shots/${pair%%:*}.png" ]]; then
    echo "No $shots/${pair%%:*}.png — run: SHOTS=\"$shots\" ./dev/check.sh" >&2
    exit 1
  fi
done
for pair in "${pairs[@]}"; do cp "$shots/${pair%%:*}.png" "$out/screenshot_${pair#*:}.png"; done

(cd "$here/dev/browser" && { [[ -d node_modules ]] || npm install --no-audit --no-fund --silent; })

# From dev/browser, where Playwright is installed: an ES module resolves its imports from there.
(cd "$here/dev/browser" && HTML="$here/apps-store/banner.html" OUT="$out" node --input-type=module -e '
import { chromium } from "playwright";
import { readFileSync, writeFileSync, rmSync } from "node:fs";
// Written next to the images, so the page may load them: a file:// page reads only files.
const page_file = `${process.env.OUT}/.banner.html`;
writeFileSync(page_file, readFileSync(process.env.HTML, "utf8")
  .replace("\"ICON\"", "\"icon.png\"").replace("\"CHECKOUT\"", "\"screenshot_checkout.png\""));
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1500, height: 750 } });
await page.goto(`file://${page_file}`, { waitUntil: "networkidle" });
rmSync(page_file);
await page.screenshot({ path: `${process.env.OUT}/banner.png` });
await browser.close();
')

ls -l "$out"
