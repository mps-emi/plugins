# Odoo Apps Store listing — Payment Provider: MPS

How the module gets onto apps.odoo.com, and what in this folder each part of the listing comes from. The rules
quoted are the store's vendor guidelines (apps.odoo.com › Vendor guidelines).

The store does not take an upload of a zip. It scans a git repository: one branch per Odoo series (`20.0`), with
each module as a folder at the branch's root. Everything on the app's page comes from that folder — the
manifest, `static/description/index.html` and the images beside it — so the listing changes by pushing, not
by editing anything on the site.

## Before submitting

```bash
./build.sh                                          # tests, the zips, dist/apps-store/
SHOTS="$PWD/dist/apps-store/screenshots" ./dev/check.sh   # the merchant's path on Odoo 20, with the screenshots
./apps-store/build.sh                               # banner and screenshots into payment_mps/static/description/
./build.sh                                          # again, so the zip and dist/apps-store/ carry the new images
```

`dev/check.sh` is the only rehearsal there is: the store's own scan checks that the module installs, nothing
more. Commit the images `apps-store/build.sh` wrote; the store reads them from the repository.

## The repository

| What | Value |
|---|---|
| Repository | `mps-emi/plugins` (public), the same repository the zips are released from |
| Branch | `20.0` — the branch name is the Odoo series the store lists the module under. It has a history of its own and holds only the module: `main` keeps one folder per plugin, which the store cannot read. |
| Contents | `dist/apps-store/` from `./build.sh`: `payment_mps/` and a short `README.md` |
| Pushed by | The public repository's Release workflow, after each new Odoo release (`plugins-repo/.github/push-apps-store.sh`). The branch never gets a module the release did not build and test. |
| Register | Once, after the first push: apps.odoo.com › Upload, with `https://github.com/mps-emi/plugins.git#20.0` as the repository. Later pushes are picked up by the store's next scan. |

A manifest the scan cannot read takes every app of the branch off the store, so never push the branch by hand
from a tree that `./build.sh` has not built.

## What the listing shows, and where it comes from

| Listing | Source | Rule |
|---|---|---|
| Name | `name` in `__manifest__.py`: **Payment Provider: MPS** | At most 25 characters (21); Odoo's own providers are named this way |
| Version | `version`: `20.0.<major>.<minor>.<patch>` | Starts with the series; a major of 0 reads as a beta (see Release) |
| Summary | `summary` | Says what is sent to MPS, as the rules ask for an external service |
| Price | none in the manifest | No `price` key: the app is **free**. MPS charges its fees under the merchant agreement. |
| License | `license`: **LGPL-3**, text in `payment_mps/LICENSE` | |
| Author, website | `author`, `website` | |
| Cover / thumbnail | `images`: `static/description/banner.png` (1500 × 750, from `apps-store/banner.html`) | Under 1500 × 1000; 2 : 1 fits both the app page and the list thumbnail |
| Icon | `static/description/icon.png` | |
| Page | `static/description/index.html` | English; Bootstrap 4 and inline color/font/margin/padding/border styles; no scripts; no links outside the store except `mailto:` and YouTube |
| Screenshots | `screenshot_*.png` beside it, shown by `index.html` (1920 × 1200) | English; from `dev/check.sh` |
| Support | `support` in the manifest: **ict@mps-emi.com**, and the same `mailto:` at the foot of the page | |

The screenshots run against `dev/fake-gateway.php`, and none of them shows its page: it is not MPS. The
settings screenshot hides the Advanced group, whose Gateway field holds the fake one's address.

The page names the external service and lists exactly what the module sends (the client credentials, per
payment the amount, the transaction reference and the shop's return and notification addresses, and the
payment reference to read it back), and that nothing is sent before the merchant saves their credentials:
the rules ask for both. The module has no activation key and installs by copying the folder into the
addons path, as they also require.

## Release

- The first listing is `20.0.1.0.0`; a major of 0 would read as a beta.
- Every later release is a version bump in `__manifest__.py`, its `CHANGELOG.md` entry, the image steps above
  when the screens changed, and a merge to `master`: the mirror releases it and pushes `20.0`. The store shows
  the version from the manifest; merchants who installed from the zip upgrade as before.
- Odoo 21 is a new branch `21.0`, once the module has been ported and `dev/check.sh` passes on it
  (`ODOO_IMAGE=docker.io/library/odoo:21.0`). The push script names the branch after the version's series, so a
  `21.0.x.y.z` version lands there; register that branch on the store as well.
