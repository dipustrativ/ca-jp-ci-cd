# Review e2e checks

Two scripts that bootstrap Magento and assert against the real database.

```bash
docker/cli php app/code/IXPF/Syncer/Test/e2e/upsert.php   # 64 checks
docker/cli php app/code/IXPF/Syncer/Test/e2e/media.php    # 30 checks
docker/cli php app/code/IXPF/Syncer/Test/e2e/read.php     # 86 checks
docker/cli php app/code/IXPF/Syncer/Test/e2e/grid.php     # 37 checks
```

Each prints `N passed, M failed` and exits non-zero on failure.

## Why these exist instead of PHPUnit

`Test/Unit/` holds the real PHPUnit tests, but **PHPUnit cannot run in this project**:
`vendor/bin/phpunit --version` itself dies with

```
Subscriber "PHPUnit\Event\Test\DataProviderMethodCalledSubscriber" does not exist
```

`vendor/phpunit/phpunit` has two versions overlaid (`composer dump-autoload` reports ambiguous
class resolution for `PHPUnit\Framework\TestRunner` and `PHPUnit\Runner\PhptTestCase`). This is
pre-existing and affects every existing test in `app/code`, not just these. Once the vendor
install is repaired, `Test/Unit/` runs normally and these scripts become redundant for the
logic they duplicate — they still cover the DB round trip, which unit tests do not.

These scripts call the service directly, so they do not cover the HTTP layer, and the media
script hands the consumer a factory-built message rather than one that has been through the
queue. `media.php` decodes a stored queue body with `MessageEncoder` to cover that seam
specifically — without it a DTO the decoder cannot populate passes every other check here while
the live queue rejects every message. The rest of the HTTP layer needs curl, and mind the URL
shape, because a wrong one looks like an auth failure:

```bash
U=http://com-m246.carpetavenue.devs/ixpfsyncer/V1_Reviews/Upsert
T=$(docker/mysqlquery -N -e "SELECT t.token FROM oauth_token t
      JOIN integration i ON i.consumer_id = t.consumer_id WHERE t.type='access' LIMIT 1")

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
