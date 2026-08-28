# Image size — where the bytes go, and what is safe to strip

Measured on `registry.carpetavenue.net/carpient:v21` (arm64, 12 store-view
locales). Re-run the commands in [Measuring](#measuring) after any change —
every number here is reproducible, none of it is estimated.

## Baseline

`docker images` reports **2.14 GB**. `docker history` attributes it as:

| Layer | Size | Notes |
|---|---:|---|
| `php:8.5-fpm-bookworm` base | ~513 MB | Debian rootfs 97 MB + `PHPIZE_DEPS` toolchain 299 MB + PHP build 102 MB + source 15 MB. Not ours to shrink — the official image ships the build toolchain in the same layer as PHP itself. |
| `COPY --from=builder /var/www/html` | **1.61 GB** | The application. Everything actionable is here. |
| runtime libs + cron | 4 MB | |
| PHP extensions | 6.7 MB | |
| configs, entrypoint, cron table | < 5 KB | |

The application tree on disk is 1.9 GB (the layer is 1.61 GB because it is
stored compressed):

```
pub/static   1.2G   ← 63% of the tree
  frontend/Magento/blank      530M
  frontend/carpient/rugs      582M   (12 locales x 49M)
  adminhtml/Magento/backend    58M
vendor        497M
generated      89M
app            45M
lib            35M
var            21M
n98-magerun2.phar  6.9M
setup         5.2M
```

### Why v20 (1.74 GB) → v21 (2.14 GB)

Not a regression in what gets copied. The theme filter added in v21 worked —
`Magento/luma` and the unassigned `carpetavenue/rugs` are gone. But the deployed
locale count went **3 → 12** (`de_DE en_US sv_SE` → all store views), and each
locale is a full ~49 MB copy of the theme's compiled assets. 9 × 49 MB ≈ 440 MB,
which is the jump.

`STATIC_LOCALES=""` (auto-discover from the DB) is the right default —
production mode never builds static on demand, so a missing locale is a broken
storefront for that store view, not a fallback to `en_US`. Do not pin the list
to shrink the image unless you are certain a store view is dead.

## The dominant cause: SCD duplicates every locale

This is the answer to "why is it so big", and it is not the strips below.

`setup:static-content:deploy` writes a **complete copy of every asset for every
locale**, including the assets that have nothing to do with language — jQuery,
KnockoutJS, images, fonts, third-party payment JS. With 12 store-view locales,
`pub/static` holds **510 MB of files but only ~78 MB of distinct content**.
Roughly 85% of the directory is byte-identical duplication.

Replacing those duplicates with hardlinks reclaims **415 MiB** — more than all
the deletions combined. Nothing is removed; every path still resolves.

```
pub/static   510 MB  →  78 MB
rugs/en_US    38 MB      (first locale keeps the real inodes)
rugs/sv_SE   1.2 MB      (the rest are links + genuinely translated files)
```

Four things had to be true for this to be shippable, and all four were verified
by building test images, not by reasoning:

1. **BuildKit's `COPY --from` preserves hardlinks.** A 12×20 MB fixture went
   from a 337 MB image to 117 MB, with link count 12 intact in the final stage.
2. **`COPY --chown=www-data:www-data` also preserves them.** Link count stayed
   12 and ownership applied to the shared inode.
3. **The `static` service's `cp -a /var/www/html/pub/static/. /mnt/static/`
   preserves them too** — GNU `cp --preserve=all` includes `links`. So the
   `magento_static` volume shrinks by the same 415 MB, not just the image.
4. **`--ignore-time` is required.** SCD stamps a different mtime per locale, so
   the default run finds only 189 MiB. mtime is not used for static
   cache-busting (`pub/static/deployed_version.txt` is), and identical files
   serving one `Last-Modified` is correct.

`--ignore-mode` / `--ignore-owner` would buy ~53 MB more (468 MiB total). **Not
used** — that can link an executable to a byte-identical non-executable twin and
silently drop the `+x` bit somewhere in `vendor/bin`. 415 MiB for zero risk is
the better trade.

The one real constraint: **hardlinking must be the last step in the builder
stage.** Any later step that rewrites a static file in place would mutate every
locale sharing that inode. Production mode never writes to `pub/static` at
runtime, so the running container is unaffected.

## Verified-safe strips (~710 MB)

### 1. `pub/static/frontend/Magento/blank` — 530 MB

SCD keeps ancestors of any deployed theme (`PackagePool::canDeployTheme`), so
`Magento/blank` is deployed as `carpient/rugs`' parent even though no store view
resolves to it.

Proof it is unused at runtime, strongest argument first:

- **The build already decides which themes exist.** SCD runs with an explicit
  include list — `--theme carpient/rugs --theme Magento/backend`. Whatever any
  database says, those are the only two themes deployed, so `Magento/blank` is
  present *solely* as an ancestor of `carpient/rugs`. This holds independently of
  store config, which is what makes the strip safe.
- **`blank` is a strict subset of `rugs`.** `blank/en_US` holds 2863 files,
  `rugs/en_US` holds 2933, and `comm -23` of the two file lists is **empty** —
  zero files exist in `blank` that are not already in `rugs`. Theme fallback is
  resolved at *deploy* time and flattened into the child theme's output; the
  runtime asset URL only ever names the active theme.
- **No store view resolves to it.** Production, verified 2026-08-22:
  `design/theme/theme_id` exists **only at default scope**, value `6` =
  `carpient/rugs`, so it covers all 12 store views across the 3 websites. There
  is no website- or store-scope override. `carpetavenue/rugs` (id `7`) is a
  leftover DB row with no files on disk, assigned nowhere.

  ⚠️ `theme_id` values differ per environment — local dev has the same two themes
  at ids `4`/`5`. Never carry an ID between environments; re-read it from the
  target database. The first two bullets are what the strip actually rests on,
  and neither depends on config.

**One caveat, and it must be fixed first.** `rugs/*/css/styles-l.css` and
`styles-m.css` contain exactly one cross-theme reference:

```
url(../../../../../frontend/Magento/blank/en_US/Mageplaza_Core/css/owl.video.play.png)
```

A Mageplaza LESS `@import` emitted an absolute-from-`pub/static` path instead of
a theme-relative one. The identical 4976-byte PNG already exists at
`rugs/<locale>/Mageplaza_Core/css/owl.video.play.png`, so the fix is to repoint
the URL at the local copy — after which `blank` has no inbound references at
all. The Dockerfile does this with one `sed` before the `rm`. It is
locale-agnostic; do not hardcode `en_US`.

Re-check after any Mageplaza or theme upgrade — if a new cross-theme `url()`
appears, the verification command below will surface it.

### 2. `*.map` source maps — 59 MB

50 files, 27 MB of it Adyen alone (`adyen.js.map` is 2.2 MB × 12 locales).
Browsers fetch a `.map` only when DevTools is open with source maps enabled;
nothing in a page load touches them. 39 deployed JS files carry a
`sourceMappingURL` comment while only 2 `.map` files exist per locale, so 37
references are **already** dangling in the shipped image — deleting the last two
changes nothing except two more silent 404s in a developer's console.

### 3. `pub/static/frontend/*/hugerte` — 99 MB

HugeRTE (the TinyMCE fork Magento 2.4.9 uses) is the **admin** WYSIWYG editor.
SCD copies `lib/web/hugerte` into every area and theme, so the storefront gets
its own 8.3 MB copy per locale.

`grep` for `wysiwygAdapter|tinymce|hugerte` across every
`app/code/*/*/view/frontend` and `vendor/*/*/view/frontend` returns **0
matches** — no frontend layout or template requires it.

It *does* appear in `rugs/*/requirejs-config.js` and in `js/bundle/bundle1.js`,
which is what makes this look risky. Both are only the RequireJS **path map and
shim** (`tinymce: 'hugerte/hugerte.min'`, `wysiwygAdapter: 'mage/adminhtml/...'`)
— the library source itself is not bundled (`grep -c 'hugerte/hugerte.min.js'`
across `js/bundle/*.js` finds nothing). RequireJS resolves a path lazily, on
first `require`, and nothing on the storefront requires it.

The `adminhtml/Magento/backend` copy (8.3 MB, single locale) must stay — that is
the one the editor actually loads.

### 4. `var/import*` — 22 MB

`var/import_history` (11 MB), `var/importexport` (11 MB), nine
`var/importexport_<hash>` temp dirs and stray `var/*.csv` — leftovers from
someone's local import/export runs that rode into the image via `COPY . .`.
`.dockerignore` listed `var/cache`, `var/log` and friends but not these. Now
excluded in both places (`.dockerignore` for the copy, the strip `RUN` as
belt-and-suspenders, since BuildKit can reuse a cached COPY layer). Magento
recreates these directories on demand.

### 5. Not stripped — deliberately

| Kept | Size | Why |
|---|---:|---|
| `n98-magerun2.phar` | 6.9 MB | Operational tooling — wanted inside the running container for on-server debugging. |
| `*.sample` (`nginx.conf.sample`, `package.json.sample`, `.htaccess.sample`, …) | ~40 KB | Reference copies; cost is noise-level and they are handy when reconstructing config. |
| `docs/` | 76 KB | Not read at runtime (`CarpetAvenue_Core`'s admin doc viewer reads `app/code/<Vendor>/<Module>/README.md` and per-module `docs/`, never the repo root), but 76 KB is not worth a line in the Dockerfile. |
| `prototype/` | 11 MB | Legacy JS. Not in `requirejs-config.js`'s path map, but a third-party module could still `require` it at runtime. Needs a storefront smoke test before cutting — see [Unverified](#unverified-candidates). |
| `generated/` | 89 MB | `setup:di:compile` output. Required — production mode does not generate on demand. |

## Unverified candidates

Not applied. Each needs a decision or a test first.

Ranked by size. Note that after the strips + dedup, `vendor` (480 MB) and the
base image (513 MB) are the two biggest remaining items — `pub/static` is down to
78 MB and is no longer where the bytes are.

| Candidate | Size | Blocker |
|---|---:|---|
| **Final stage from `debian:bookworm-slim`** instead of `php:*-fpm-bookworm` | **~300 MB** | The official PHP image ships the `PHPIZE_DEPS` build toolchain (gcc, g++, make, autoconf, dpkg-dev) in a 299 MB layer of the *runtime* base. It cannot be removed in a child layer — layers are additive, so an `apt-get purge` only adds a whiteout. The fix is to stop inheriting: final stage `FROM debian:bookworm-slim` + `COPY --from=builder /usr/local /usr/local` (PHP itself is only 65 MB). Needs the `www-data` user, `PHP_INI_DIR`, `docker-php-entrypoint` and the php-fpm config tree reproduced by hand. Biggest remaining win, moderate risk. |
| **`vendor/mpdf/mpdf/ttfonts`** | **up to 80 MB of 88 MB** | mPDF ships 83 font files. `Sun-ExtA.ttf` (22 MB) + `Sun-ExtB.ttf` (17 MB) + `UnBatang` (6.7 MB) + `Aegyptus`/`Aegean`/`Akkadian`/`Jomolhari`/`Quivira`/`XB Riyaz` are CJK-extension and ancient-script fallbacks. Both consumers (`CarpetAvenue_Proforma`, `Evalent_ProductCertificate`) construct mPDF with `'default_font' => 'dejavusans'`, and the templates name only `dejavusans`, `Helvetica`, `Arial` and `YasashisaGothicBold-V2`/`cid0jp`. `cid0jp` is a **CID-keyed** font served from `vendor/mpdf/mpdf/data` (2.8 MB), not from `ttfonts`, so Japanese output does not depend on `ttfonts`. **But** mPDF's `useSubstitutions`/autoFont silently falls back through `ttfonts` for unexpected glyphs, and these are customer-facing invoice and certificate PDFs — a missing glyph in a Japanese address is a real defect, not a cosmetic one. Before pruning: generate a proforma **and** a certificate for an order with a Japanese name/address and one with a European accented name, and diff against the current output. |
| **`vendor/aws/aws-sdk-php/src/data`** | **~40 MB of 47 MB** | Per-service AWS API model JSON, loaded lazily by service name. The only consumer is `vendor/magento/module-aws-s3` (remote storage), and `remote_storage.driver` is `'file'` in `env.php.template` — S3 is not in use. Keeping `s3`, `sts` and the core models would reclaim ~40 MB. Modifies a vendor package's payload rather than deleting whole unused dirs, so it needs a check that no other AWS service client is referenced anywhere first. |
| `frontend/*/Magento_Swagger` (`swagger-ui`) | 19 MB → ~2 MB after dedup | `vendor/magento/module-swagger/etc/frontend/routes.xml` declares a real **frontend** route — `/swagger` is a live storefront URL. Deleting the assets breaks that page and nothing else. Now largely moot: the copies are hardlinked, so the 19 MB is ~2 MB of distinct content. |
| `frontend/*/prototype` | 11 MB → ~1 MB after dedup | Legacy `prototype.js` + `validation.js`. Absent from the RequireJS path map, but older third-party modules load it via a direct `<script>`. Also mostly moot after dedup. Verify with a storefront smoke test before removing. |
| `pub/static` as a volume instead of baked into the image | ~78 MB | Was the obvious structural lever when static was 1.2 GB. After dedup it is 78 MB — no longer worth the cost in compose changes, upgrade-job sequencing and rollback semantics. Dropped. |
| `hugerte/{CHANGELOG.md,README.md,bower.json,composer.json,hugerte.d.ts}` in adminhtml | < 1 MB | Dev cruft inside the admin copy. Not worth a line. |

## Result

Measured by replaying the exact Dockerfile commands inside a `:v21` container:

| | Before | After |
|---|---:|---:|
| `/var/www/html` tree | 1.9 GB | **684 MB** |
| ↳ `pub/static` | 1.2 GB | **78 MB** |
| ↳ `vendor` | 497 MB | 480 MB |
| ↳ `generated` | 89 MB | 80 MB |
| ↳ `app` | 45 MB | 31 MB |
| ↳ `var` | 21 MB | 16 KB |
| Image (`docker images`) | 2.14 GB | **≈ 1.1 GB** |
| `magento_static` volume | ~1.2 GB | ~78 MB |

Contribution of each change, largest first:

| Change | Reclaimed |
|---|---:|
| Cross-locale hardlink dedup | **415 MiB** |
| `pub/static/frontend/Magento/blank` | 530 MB → ~40 MB after dedup¹ |
| frontend `hugerte` | 99 MB → ~8 MB after dedup¹ |
| `*.map` | 59 MB → ~5 MB after dedup¹ |
| `var/import*` | 22 MB |

¹ The strips and the dedup overlap: most of what the strips delete is itself
duplicated across locales, so their standalone figures do not add up to the
total. Measured together, the strips + dedup take the tree from 1.9 GB to
684 MB. The strips are still worth keeping — they remove content the running
site never reads, and they shrink the dedup work.

Post-change sanity checks, all confirmed in the same dry-run:

- `hardlink` reports `Saved: 415.03 MiB`; the build asserts
  `stat -c %h .../en_US/jquery.js > 1` so a silent dedup failure fails the build.
- The rewritten CSS reads `url("../Mageplaza_Core/css/owl.video.play.png")`,
  which resolves to the existing file in `rugs/<locale>/`.
- `adminhtml/Magento/backend/en_US/hugerte` survives at 8.3 MB.
- `frontend/carpient/rugs/en_US/hugerte` is gone.
- All 12 locale directories intact: `da_DK de_DE en_US es_ES fi_FI fr_FR hu_HU
  it_IT nl_NL pl_PL pt_PT sv_SE`.
- 5570 executable files still carry `+x`, `vendor/bin/*` included.

An empty `pub/static/frontend/Magento/` directory is left behind. Harmless.

The remaining floor is ~513 MB base image + ~480 MB `vendor` + ~80 MB
`generated`. `pub/static` is no longer a significant contributor, and adding a
13th locale now costs ~1 MB instead of ~40 MB. The two ways to go materially
lower are both in [Unverified](#unverified-candidates): a `debian-slim` final
stage (~300 MB) and the mPDF font set (~80 MB).

## Measuring

Total, and the per-layer attribution:

```bash
docker images registry.carpetavenue.net/carpient
docker history registry.carpetavenue.net/carpient:latest \
  --no-trunc --format '{{.Size}}\t{{.CreatedBy}}' | head -20
```

Where the application bytes are:

```bash
docker run --rm --entrypoint sh registry.carpetavenue.net/carpient:latest -c '
  du -sh /var/www/html/* | sort -hr | head -12
  du -sh /var/www/html/pub/static/frontend/*/* /var/www/html/pub/static/adminhtml/*/*
'
```

Re-check procedure after a Magento, theme, or Mageplaza upgrade — **any output
here means the `Magento/blank` strip is no longer safe as written**:

```bash
# 1. New cross-theme references into a stripped theme?
docker run --rm --entrypoint sh registry.carpetavenue.net/carpient:latest -c '
  grep -rho "[^ (\"]*frontend/Magento/blank/[^ )\"]*" \
    /var/www/html/pub/static/frontend/carpient 2>/dev/null | sort -u
'
# Expected: empty (the sed in the Dockerfile rewrote the only one).

# 2. Blank still a strict subset of rugs?
docker run --rm --entrypoint sh registry.carpetavenue.net/carpient:latest -c '
  cd /var/www/html/pub/static/frontend
  ( cd Magento/blank/en_US && find . -type f | sort ) > /tmp/b
  ( cd carpient/rugs/en_US && find . -type f | sort ) > /tmp/r
  comm -23 /tmp/b /tmp/r
'
# Expected: empty.

# 3. Did a frontend module start using the WYSIWYG?
docker run --rm --entrypoint sh registry.carpetavenue.net/carpient:latest -c '
  cd /var/www/html && grep -rl "wysiwygAdapter\|tinymce\|hugerte" \
    app/code/*/*/view/frontend vendor/*/*/view/frontend 2>/dev/null
'
# Expected: empty.

# 4. Did the hardlink dedup actually survive into the shipped image?
docker run --rm --entrypoint sh registry.carpetavenue.net/carpient:latest -c '
  cd /var/www/html
  stat -c "links=%h" pub/static/frontend/carpient/rugs/en_US/jquery.js
  du -sh pub/static
'
# Expected: links=13 (12 locales + adminhtml), pub/static ~78M.
# links=1 means a BuildKit or base-image change broke hardlink preservation
# across COPY --from. The in-build assert should have caught it; if it did not,
# re-run the fixture test described in the dedup section above.
```

Re-measure the true dedup ceiling if you ever want to revisit the numbers — this
reports what is available without changing anything:

```bash
docker run --rm --entrypoint sh registry.carpetavenue.net/carpient:latest -c '
  apt-get update >/dev/null 2>&1 && \
  apt-get install -y --no-install-recommends hardlink >/dev/null 2>&1
  hardlink --dry-run --ignore-time /var/www/html 2>&1 | grep Saved
'
```

Verify the built site after a strip: storefront home, a category page, a product
page, cart, and checkout, plus the admin panel — with the browser console open,
watching for 404s on `/static/`.
