#!/usr/bin/env python3
"""Every string the module translates — trans('…', [...], 'Modules.Mpspayments.*') in PHP and
{l s='…' d='Modules.Mpspayments.*'} in templates — has a Dutch and a French row in i18n.py, and i18n.py
has no row the code no longer uses. Exits non-zero otherwise. build.sh runs it."""
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, ".."))
from i18n import STRINGS  # noqa: E402

MODULE = os.path.join(HERE, "..", "mpspayments")
# The parameters array may hold one level of nested brackets; any domain is captured and filtered below,
# so a call into PrestaShop's own domains ('Admin.Actions') is not mistaken for one of ours.
PHP = re.compile(r"trans\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*\[(?:[^\[\]]|\[[^\[\]]*\])*\]\s*,\s*'([^']+)'", re.S)
TPL = re.compile(r"\{l s='((?:[^'\\]|\\.)*)' d='(Modules\.Mpspayments\.\w+)'\}")

used = set()
for root, _, files in os.walk(MODULE):
    for name in files:
        if name.endswith((".php", ".tpl")):
            text = open(os.path.join(root, name), encoding="utf-8").read()
            for source, domain in PHP.findall(text) + TPL.findall(text):
                if domain.startswith("Modules.Mpspayments."):
                    used.add((domain, source.replace("\\'", "'")))

known = {(domain, row[0]) for domain, rows in STRINGS.items() for row in rows}
failures = 0
for domain, source in sorted(used - known):
    print(f"FAIL no translation in i18n.py: [{domain}] {source}")
    failures += 1
for domain, source in sorted(known - used):
    print(f"FAIL unused row in i18n.py: [{domain}] {source}")
    failures += 1
for domain, rows in STRINGS.items():
    for row in rows:
        for placeholder in re.findall(r"%\w+%", row[0]):
            if any(placeholder not in target for target in row[1:]):
                print(f"FAIL placeholder {placeholder} missing in a translation of: {row[0]}")
                failures += 1

print(f"{len(used)} strings checked." if not failures else f"{failures} failed.")
sys.exit(1 if failures else 0)
