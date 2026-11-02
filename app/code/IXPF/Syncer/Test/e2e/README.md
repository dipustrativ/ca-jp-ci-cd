# Review e2e checks

Scripts that bootstrap Magento and assert against the real database.

```bash
docker/cli php app/code/IXPF/Syncer/Test/e2e/upsert.php       # 74 checks
docker/cli php app/code/IXPF/Syncer/Test/e2e/media.php        # 33 checks
docker/cli php app/code/IXPF/Syncer/Test/e2e/read.php         # 118 checks (113 on a single-market install)
docker/cli php app/code/IXPF/Syncer/Test/e2e/grid.php         # 38 checks
docker/cli php app/code/IXPF/Syncer/Test/e2e/form-embed.php   # 26 checks
```

Each prints `N passed, M failed` and exits non-zero on failure. `read.php`'s source-domain
checks need the `de`, `ch_de` and `dk` store views; on an install without them (the JP store
has only `jp`) they print a `skip` line instead of failing.

## Drawer, in a real browser

```bash
node app/code/IXPF/Syncer/Test/e2e/drawer.cjs [product_id]   # ~3900 checks, ~30s warm
```

Every reachable storefront (16 here; `ot` shares the default domain) at desktop 1440px and phone
390px. `drawer-fixture.php` points one product at a throwaway pool `ZZDRAWER` and restores the
product's own `review_group` / `review_count` / `review_average` afterwards, also on failure and
Ctrl+C. Without an id it picks the first product in every website that already has reviews.

What the pool covers, per store and viewport:

- bought line in the store's locale; `hu_HU` left out of the map (falls back to `en_US`); a map with
  only `da_DK` (first-sent fallback); no line; no `bought` at all
- reply heading from the theme CSV plus `reply_name`; a legacy `reply.author` never rendered; a
  star-only review whose reply resolves through its source locale
- photos: portrait, landscape and one without width/height; previews capped at 120px on the longest side, under the text; a click
  grows the whole row to max 450px (never wider than the card), keeps aspect ratios and scrolls the clicked photo into view; the
  second click collapses and rewinds; Enter and Space; other reviews untouched; "Images only"
  drops the text-only review and resets the row
- filter row: "Text only" is switched on for every other store view (`show_text_filter`, restored
  afterwards), so both states run everywhere: shown or hidden per setting, the two checkboxes side
  by side, selects on the same line and side by side only when they fit; the run lists which
  stores use one, two or three lines
- filters: photos, text, both and none return exactly the right reviews, in the HTML and by
  clicking; with the setting off the server ignores `with_text`
- nothing overflows: bought row, reply heading, photo row, filter row, drawer; no drawer JS errors;
  every review image served

Expected strings are read from the theme `i18n/*.csv`, so a new translation needs no test change.
A deliberately broken bought-line locale fails 45 checks across 15 stores; a removed
scroll-into-view fails all 32 pages.

Needs Playwright's Chromium once: `npx playwright install chromium`. The script finds the npx copy
itself, and falls back to the newest downloaded Chromium when the versions disagree.

| Env | Default | |
|---|---|---|
| `E2E_CONCURRENCY` | 6 | Pages in flight. The first run after a static-content clear is slow (~85s) while developer mode builds `pub/static` |
| `E2E_KEEP=1` | off | Leave the fixture up to look at it; `docker/cli php app/code/IXPF/Syncer/Test/e2e/drawer-fixture.php down` cleans up |
| `E2E_SHOTS` | failures only | A directory: screenshot every page's drawer there |
| `MAGENTO_PHP` | `docker/cli php` | How to run the fixture script |
| `PLAYWRIGHT_MODULE`, `CHROMIUM_PATH` | auto | Point at a specific install |
| `E2E_THEME` | auto | Theme whose `i18n/*.csv` holds the expected strings: `Evalent/carpet` if present, else `carpetavenue/rugs` |

Every host other than the storefront's own, catalog images and the consent script are blocked: they are not under test,
and they are most of the page weight.

## Unit tests vs these scripts

`Test/Unit/` holds the PHPUnit tests (PHPUnit 12, so data providers are `#[DataProvider]`
attributes — docblock `@dataProvider` is ignored). Run them with Magento's unit config, which
defines `BP`:

```bash
docker/cli vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/IXPF/Syncer/Test/Unit
```

The only expected warning is the Allure extension's missing `allure/allure.config.php`.
The e2e scripts overlap the unit tests on logic but also cover the DB round trip, which unit
tests do not.

These scripts call the service directly, so they do not cover the HTTP layer, and the media
script hands the consumer a factory-built message rather than one that has been through the
queue. `media.php` decodes a stored queue body with `MessageEncoder` to cover that seam
specifically — without it a DTO the decoder cannot populate passes every other check here while
the live queue rejects every message. The rest of the HTTP layer needs curl, and mind the URL
shape, because a wrong one looks like an auth failure:

```bash
U=http://com-m246.carpetavenue.devs/ixpfsyncer/V1_Reviews/Upsert
T=$(docker/mysqlquery -N -e "SELECT t.token FROM oauth_token t
      JOIN integration i ON i.consumer_id = t.consumer_id WHERE t.type='access' LIMIT 1" | tr -d '| ')

curl -sS -X POST "$U" -H "X-Auth-Token: Bearer $T" -H 'Content-Type: application/json' \
  --data @app/code/IXPF/Syncer/Test/fixture/review_group_8518C.json
```

`/ixpfsyncer/V1/Reviews/Upsert` with slashes returns `302` and never reaches the controller —
Magento's router consumes three path segments and maps `_` in the action path to a namespace
separator. See `docs/ReviewsUpsert.md`.

## Safety

**Fixture review ids must stay `zztest`-prefixed.** `review_id` is unique platform-wide, so a
fixture pushed with an id that a live pool already holds moves that review into the test pool —
and the test's cleanup then deletes it from both. This is not hypothetical: the ids originally
copied from the IXPF spec example are the same ids IXPF sends in its own sample payload, and
running `upsert.php` silently removed two reviews from a real `8630A` pool. Same-length
`zztest`-prefixed ids cannot collide with a real 24-char hex id.


Both scripts run entirely under `ZZ`-prefixed review groups and delete `review_group LIKE 'ZZ%'`
on entry and exit. They never touch a real pool. The fixtures name a real design group (`8518C`)
but the scripts override `review_group` before pushing.

`media.php` also creates and removes a placeholder file at
`pub/media/catalog/product/a/w/aw1646-00001.jpg` to exercise the gallery-reuse path. If that
file already exists for real, the script will delete it — check before running against an
environment with production media.

## What they cover

**upsert.php** — first push, aggregates and locale-keyed labels, UTC date handling, text and
photo row counts, `has_photos` denormalisation; identical replay and older `generated_at` both
ignored as stale; a newer push that removes one review, updates one and skips one by hash, with
`entity_id` surviving; FK cascade leaving no orphans; empty `reviews` zeroing the group but
keeping the row; unknown group created; malformed payload writing nothing and naming the field.

**media.php** — messages published to the db queue; storage path conventions; derivative sizes
on real landscape and portrait JPEGs; bought-item thumbnail reusing an existing gallery file
without downloading; consumer idempotency on an already-stored file; the give-up path leaving
`local_path` null without rethrowing; republish queueing only what is still missing; cleanup
cron removing orphan directories while keeping live ones.

**read.php** — summary read from the group row including locale-resolved labels; unknown group
returning empty rather than throwing; sort order and `created_at` desc; locale fallback through
requested → source → `en_US`, with source text carried for the show-original toggle; a review
with no usable locale omitted while `total_count` still reflects storage; rating and photo
filters with the distribution provably unchanged under them; filtered totals counted rather
than taken from the group row; pagination boundaries including a page past the end; filter
clamping of out-of-range input; cache invalidation by the upsert's tag; product → group key
resolution; the three sort orders with their tiebreakers, an unrecognised sort falling back to
newest rather than reaching SQL, each sort getting its own cache fragment, and the photo filter
excluding a review whose photos have not downloaded yet.

**grid.php** — both admin listings built through `UiComponentFactory`, which parses the
`ui_component` XML against its XSD and instantiates every column class; every declared column
existing as a real table column; `has_photos` resolving to Yes/No options; the reviews data source
under filter, sort and paging with the unpaged total preserved; the pools data source resolving
row identity from its varchar primary key; the label filter matching both an `en_US` and a
non-`en_US` label; the group-label column across en_US, fallback, null, malformed JSON, empty
object and missing key; both ACL resources declared and referenced by the menu.

Note that `prepare()` does not cascade — the layout generator walks the component tree. `grid.php`
prepares recursively via `prepareTree()`. Preparing only the root leaves columns unprepared, and a
column with no wrapped component reports no options, which reads as a missing options source when
nothing is actually wrong.

Note the ordering constraint in `read.php`: the "no usable locale" block runs **last**, because
it strips a review's text rows and a restoring push would be skipped by the hash diff — the
review's JSON is unchanged, which is exactly the behaviour the upsert is supposed to have.
