#!/usr/bin/env bash
# Prints a plugin's version, read where its build.sh reads it.
#
#   .github/version.sh <plugin> [<folder holding the plugin folders; default: this repository>]
set -euo pipefail

plugin="$1"
root="${2:-$(cd "$(dirname "$0")/.." && pwd)}"

case "$plugin" in
  woocommerce) version="$(sed -n 's/^ \* Version: *//p' "$root/woocommerce/mps-payments/mps-payments.php" | tr -d '[:space:]')" ;;
  magento)     version="$(sed -n 's/^  "version": "\(.*\)",$/\1/p' "$root/magento/mps-payments/composer.json")" ;;
  prestashop)  version="$(sed -n "s/^ *\$this->version = '\(.*\)';$/\1/p" "$root/prestashop/mpspayments/mpspayments.php")" ;;
  odoo)        version="$(sed -n 's/^ *"version": *"\([^"]*\)".*/\1/p' "$root/odoo/payment_mps/__manifest__.py")" ;;
  *)           echo "Unknown plugin: $plugin" >&2; exit 1 ;;
esac

[[ -n "$version" ]] || { echo "No version found for $plugin" >&2; exit 1; }
echo "$version"
