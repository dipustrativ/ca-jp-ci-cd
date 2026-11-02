# Reviews Upsert

IXPF owns reviews end to end — collection, moderation, translation, brand replies. Magento
stores a copy, calculates nothing, and never calls IXPF at read time. IXPF pushes on every change.

Reviews are pooled by `review_group`, a product attribute holding the join key itself. Magento
matches on that one attribute and does no branching.

## Endpoint

```
POST /ixpfsyncer/V1_Reviews/Upsert
X-Auth-Token: Bearer <integration token>
Content-Type: application/json
```

Same auth as `AttributeMassUpdate` and every other IXPF write endpoint.

> **The underscore is not a typo.** Magento's frontend router consumes exactly three path
> segments — front name, action path, action name — and converts `_` in the action path into a
> namespace separator. So `V1_Reviews` resolves to `Controller/V1/Reviews/` and
> `Upsert` to `Upsert.php`.
>
> `/ixpfsyncer/V1/Reviews/Upsert` (with slashes) **never reaches the controller**. It parses as
> action path `V1`, action `Reviews`, looks for a non-existent `Controller/V1/Reviews.php`,
> falls through to the CMS no-route handler and returns a `302` to the storefront with an
> "Invalid Form Key" message — which looks like an auth problem but is a routing miss.
>
> Path segments are case-insensitive, so `/ixpfsyncer/v1_reviews/upsert` works identically.
>
> The same applies to every other endpoint in this module, including
> `POST /ixpfsyncer/V1_Products/AttributeMassUpdate`.

Verified response matrix:

```
no token                  401  {"message":"Unauthorized"}
bad token                 401  {"message":"Unauthorized token"}
non-JSON body             400  {"message":"Request body must be a JSON object","field":"payload"}
invalid rating            400  {"message":"rating is required and must be an integer between 1 and 5",
                                "field":"reviews[1].rating"}
valid payload             200  {"status":"stored","review_group":"8518C","reviews":3}
replay of same payload    200  {"status":"ignored_stale","review_group":"8518C"}
GET                       404
```

**One call replaces the entire review set for one `review_group`. Anything absent from the
payload is deleted.**

## Request

Top level: `review_group`, `group_label` (locale-keyed), `generated_at`, `count`,
`with_text_count`, `average`, `distribution`, `sub_averages`, `reviews`.

Per review: `id`, `sku`, `rating`, `sub_ratings`, `reviewer`, `created_at`, `source`,
`source_locale`, `source_store_code`, `bought` (`name`, `variant_texts` keyed by locale,
`purchased_at`, `thumbnail_path`), `body` keyed by locale, `photos` (`bucket_path`, `width`,
`height`), `reply` (`body` keyed by locale, `created_at`) or `null`.

`title` was dropped from the payload in the 2026-09-07 spec. It is no longer required, stored, or
returned; a stray one is ignored rather than rejected.

On 2026-09-24 IXPF replaced `bought.variant_text` (one English sentence) with `variant_texts`,
the finished line per store view locale, e.g. `"sv_SE": "Köpt: Vit / Beige, 300 × 384 cm"`. It is
stored as JSON in `ixpf_review.bought_variant_texts` and printed as sent - nothing is translated,
parsed or looked up on our side. `reply.author` was dropped the same day; the reply heading is
ours (`ixpf_reviews/general/reply_name`). Both old columns are gone. A push still carrying either
old field is accepted and the field ignored. `bought` is not validated beyond the column widths
of `name` and `thumbnail_path`: a malformed `variant_texts` map is filtered to nothing rather than
rejecting the push.

`count` is every approved rating, including star-only ones. `with_text_count` counts only those
with a written body, and drives the "Based on 79 ratings and 41 reviews" label plus the drawer's
with-text filter. It is **optional** so a push predating the field is still accepted; absent means
0, and the label then falls back to the ratings half alone rather than claiming "and 0 reviews".

`source` (`customer` | `staff` | `auto`) is stored on the review row and never rendered, per the
spec. It was silently dropped until 2026-09-08.

Worked example: `app/code/IXPF/Syncer/Test/fixture/review_group_8518C.json`.

## Responses

| Case | Status | Body |
|---|---|---|
| Stored | 200 | `{"status":"stored","review_group":"8518C","reviews":79}` |
| Older than stored | 200 | `{"status":"ignored_stale","review_group":"8518C"}` |
| Validation failure | 400 | `{"message":"...","field":"reviews[3].rating"}` |
| Auth failure | 401 | `{"message":"Unauthorized token"}` |
| Unexpected error | 500 | Logged with the group key, nothing written |

**A stale push returns 200, not an error.** Any 4xx or 5xx there will make IXPF retry a push
that can never succeed.

## Validation

The whole payload is rejected before anything is written. The failing field is named in the
response.

Every max length is the column width in `etc/db_schema.xml`, counted in characters (not bytes).
Magento runs with an empty `SQL_MODE`, so MySQL would otherwise truncate an overlong value
silently - on a key column that loses text rows, collides ids, or makes a path never match the
payload again so it is re-queued on every push.

| Field | Rule |
|---|---|
| `review_group` | Required, non-empty string, max 64 chars |
| `generated_at` | Required, parseable ISO 8601 |
| `count` | Required int, >= 0 |
| `average` | Required numeric, 0–5 |
| `distribution` | Required array of exactly 5 non-negative ints, index 0 is 1-star |
| `with_text_count` | Optional int >= 0; absent stores 0 |
| `sub_averages` | Optional; each value numeric 0–5 if present |
| `group_label` | Optional; object of locale to string |
| `reviews` | Required array, may be empty |
| `reviews[].id` | Required, unique within the payload, `^[A-Za-z0-9_-]{1,32}$` (it is also a directory name under `pub/media/ixpf/reviews/`) |
| `reviews[].sku` | Optional; max 64 chars |
| `reviews[].reviewer` | Optional; max 255 chars **as stored** - each emoji counts as its `&#x...;` reference (9-10 chars) |
| `reviews[].rating` | Required int 1–5 |
| `reviews[].created_at` | Required ISO 8601 |
| `reviews[].source` | Optional; max 16 chars, stored raw, never rendered |
| `reviews[].source_locale` | Required, max 12 chars, must appear as a key in `body` |
| `reviews[].source_store_code` | Optional; max 32 chars |
| `reviews[].body`, `reviews[].reply.body` | Object of locale to string; each locale key max 12 chars (`ixpf_review_text.locale`, part of its primary key) |
| `reviews[].bought.name` | Optional; max 255 chars as stored, same emoji rule as `reviewer` |
| `reviews[].bought.thumbnail_path` | Optional; max 500 chars |
| `reviews[].sub_ratings.*` | Optional int 1–5 |
| `reviews[].photos[].bucket_path` | Required if the photo object is present, max 500 chars |

Locale keys are accepted as sent and **not** validated against Magento store locales — IXPF may
push locales for markets not yet live. An unknown locale is stored and simply never selected at
read time.

## How the apply works

Each review carries an md5 of its own JSON exactly as received. On every push:

- reviews absent from the payload are deleted (cascades to text and photos)
- reviews not yet stored are inserted
- reviews whose hash changed are updated in place — `entity_id` survives
- reviews whose hash is identical are skipped entirely, zero writes

A brand reply, a translation update or a moderation change touches one review, so the apply
writes one row instead of rewriting the pool. On a first push everything is an insert.

Photo rows are compared by `bucket_path`. An unchanged path set keeps its `local_path`, so
nothing is re-fetched and no derivatives are regenerated.

`SELECT ... FOR UPDATE` on the group row makes two concurrent pushes for the same group safe.
Two pushes for different groups do not block each other.

## Product attributes

`review_count`, `review_average` and `review_group` are pushed separately through the existing
`AttributeMassUpdate` endpoint. Nothing in this module writes them.

They duplicate two columns of `ixpf_review_group` on purpose: they are read inside
full-page-cached HTML and in category listing indexes, where a query per product is not
affordable. That duplication is why they can briefly disagree with the group table between the
attribute push and the upsert — a known and accepted window.

> **Setup step:** the three attribute codes must be selected under
> **Stores > Configuration > IXPF > Product > Product Update Config > Allow Attributes**, or the
> attribute push silently drops them.

## Reading reviews

`IXPF\Syncer\Api\ReviewProviderInterface` is the read side. It is a service, not a controller —
a future AJAX endpoint is a thin wrapper over it.

```php
$group = $provider->getGroupKeyForProduct($productId);   // null if the product has no pool
if ($group === null) { return; }

$summary = $provider->getSummary($group, $locale);
$list    = $provider->getReviews($group, $locale, new ReviewFilter(
    rating: null,      // 1-5 or null
    hasPhotos: null,   // true / false / null
    page: 1,
    pageSize: 10,
    sort: 'newest',    // newest | highest | lowest, anything else clamps to newest
    withText: null     // true = written body only, false = star-only, null = no filter
));
```

**Never accept a `review_group` from the client.** The caller starts from a product id and the
service resolves the pool itself; taking the key from a request would turn the endpoint into a
way to enumerate every pool on the platform.

- An unknown group returns a zeroed summary and an empty list, never an exception.
- The distribution always comes from the group row, never the filtered set, so the bars do not
  move when a filter is applied.
- The unfiltered total is the group row's own `review_count`, and a with-text-only total is its
  `with_text_count`; a `COUNT(*)` runs only for any other filter combination.
- Each item carries both the resolved text and the review's own source-language text, so a
  "show original" toggle needs no second request. `is_translated` says whether to offer it.
- The note beside that toggle names the **source market's domain**, resolved from
  `source_store_code` through that store's base URL — per the spec, "not the language name". An
  unresolvable code (a market not live in Magento) falls back to the language name.
- Locale fallback per review: requested → the review's `source_locale` → `en_US`. A review with
  none of those is omitted from `items` and logged once per group per request. `total_count`
  still reflects what is stored.
- The bought line resolves on its own, not with the body: requested → `en_US` → the first locale
  sent. A review read in its source language still shows the bought line in the viewer's.
- Photos expose only the 80px and 800px derivative paths. Photos not yet downloaded are omitted.
  The card renders the 800px one in both its preview and its expanded state.
- Text is plain as received — **escape on output**.

Results are cached for 24h under `ixpf_review_group_<md5(review_group)>`, which the upsert
cleans. TTL is only a backstop; correctness comes from the tag.

## Configuration

**Stores > Configuration > IXPF > Product Reviews**

| Path | Scope | Default | Purpose |
|---|---|---|---|
| `ixpf_reviews/general/enabled` | store view | 0 | Master switch for the read service and cleanup cron |
| `ixpf_reviews/general/show_on_frontend` | store view | 1 | Rating on product pages and the drawer; ANDed with `enabled` |
| `ixpf_reviews/general/per_page` | store view | 10 | Default page size |
| `ixpf_reviews/general/review_criteria` | store view | `text,photo,sub_ratings` | Multiselect: an entry with any selected part (written text, photo, a Quality/Delivery/Customer service rating) is a review, otherwise a bare rating. All cleared = every entry is a rating. Part of the counts cache key |
| `ixpf_reviews/general/count_display` | store view | `split` | Drawer headline: `split` "8 reviews and 2 ratings", `overlap` "10 ratings and 8 reviews", `combined` "10 reviews". Unknown values fall back to `split` |
| `ixpf_reviews/general/show_photo_filter` | store view | 1 | "Images only" checkbox in the drawer; off hides it and ignores `has_photos`. Hidden anyway for a pool without a downloaded photo |
| `ixpf_reviews/general/show_text_filter` | store view | 0 | "Text only" checkbox in the drawer; off hides it and ignores `with_text` |
| `ixpf_reviews/general/reply_name` | store view | Carpet Avenue Customer Service | Name a store reply is attributed to; empty falls back to the default |
| `ixpf_reviews/general/warn_threshold` | global | 2000 | Log a warning above this review count in one payload |
| `ixpf_reviews/media/enabled` | global | 1 | Media consumer on/off |
| `ixpf_reviews/form/enabled` | global | 0 | Serve the hosted invite form at `/reviews`; independent of `enabled` |
| `ixpf_reviews/form/base_url` | global | empty | Origin of the hosted form; must be `https://`, otherwise the page 404s and an error is logged |

Group **Cache** holds a **Clean Review Cache** button: it clears the `ixpf_reviews` cache type
and nothing else (same as `bin/magento cache:clean ixpf_reviews`), for after a hand edit of the
review tables. The section and the button both need ACL `IXPF_Syncer::product_reviews`.

`bin/magento config:show` prints only stored values, not these `config.xml` defaults — an empty
result there means the default is in force.

Ingest is deliberately **not** behind the master switch — IXPF can backfill while everything
downstream is off.

## Media

After the push commits, one message per still-missing file goes onto `ixpf.review.media.download`
(`connection="db"`, consumed by `ixpf.review.media.download`). The publish reads current state
rather than what the push touched, so a file a previous fetch failed on is retried on the next
push instead of staying dark forever. A review whose photos are unchanged already has its
`local_path` set and is never re-queued.

Files land under `pub/media`, keyed by `review_id`:

```
ixpf/reviews/<review_id>/<basename>       original as downloaded, never served
ixpf/reviews/<review_id>/80/<basename>    80px square, bought-item thumbnail (unused for review photos)
ixpf/reviews/<review_id>/800/<basename>   800px longest edge, review card photo (preview + expanded)
```

`local_path` and `thumbnail_local` store the **original** relative path; the derivative paths
are derived from it, so the columns stay valid if the sizes ever change.

A `bought.thumbnail_path` is checked against the product gallery first — it normally points at
the same bucket object the gallery already pulled, so that file is reused and nothing is
downloaded or resized.

The consumer is idempotent (path set and file present → no-op), retries transport failures
three times with backoff, then logs an error and leaves the column null. It never rethrows: a
rethrow requeues the message, and a bucket path that 404s would be retried forever.

Run it manually with:

```bash
bin/magento queue:consumers:start ixpf.review.media.download --max-messages=50
```

`ixpf_review_media_cleanup` runs daily at 03:00 and deletes directories whose `review_id` is no
longer in `ixpf_review`. It is gated on `ixpf_reviews/general/enabled`.

## Admin grids

**System > IXPF Syncer**

| Menu | Table | Answers |
|---|---|---|
| Product Reviews | `ixpf_review` | Did this review arrive? For which SKU, what rating, from which market, does it have photos or a reply? |
| Review Pools | `ixpf_review_group` | Which pools has IXPF pushed, with what aggregates, and when? |

Both are read-only listings — no add, edit, delete or mass action. IXPF owns this data; the only
way it changes is a push.

On **Review Pools**, `Generated At (IXPF)` is IXPF's own clock and is what the staleness check
compares against; `Stored At` is when the push actually landed here. A pool that looks out of
date is usually a gap between those two. The `Label` column decodes the locale-keyed
`group_label` JSON, preferring `en_US` and falling back to whichever locale IXPF sent — its
filter searches the raw JSON, so a search matches the label in any language.

On **Product Reviews**, `Photos` is the denormalised `has_photos` flag, so filtering on it is one
indexed column rather than a join. It says a review *has* photos, not that they have been
downloaded — a photo whose fetch failed still shows Yes. Use the media log for that. `Bought`
(hidden by default) decodes `bought_variant_texts` the same way `Label` does on Review Pools.

> **Setup step:** both grids sit behind new ACL resources, `IXPF_Syncer::product_reviews` and
> `IXPF_Syncer::review_pools`. A role that grants `Magento_Backend::all` picks them up
> automatically. A role with an explicit per-resource rule list does **not** — new resources
> default to deny, and the menu entries are simply invisible with no error. Tick them under
> **System > Permissions > User Roles > _role_ > Role Resources**.

Neither grid has a row detail view. Review text and photos live in `ixpf_review_text` and
`ixpf_review_photo` and are read through `ReviewProviderInterface`; add a view page when someone
actually needs to read a translation from admin.

## Logging

`IXPF\Syncer\Helper\Logger\ReviewLogger` — the same `BaseLogger` helper photostudio, webhook,
product and orders use, so the files sit where every other IXPF log does, one per day.

| File | Holds |
|---|---|
| `var/log/ixpf/review/review-<Y-m-d>.log` | everything below |
| `var/log/ixpf/exception/review-<Y-m-d>.log` | Throwables only, with file, line and trace |

Every call to the upsert endpoint is logged in full, as two lines sharing an 8-character request
id so a response can always be matched to the body that produced it:

```
[9f2c41ab] >> POST http://…/ixpfsyncer/V1_Reviews/Upsert from 10.0.0.4
Headers: {"Content-Type":"application\/json","X-Auth-Token":"*** redacted ***", …}
Body: {"review_group":"8630A","generated_at":"…","count":2,"reviews":[…]}     <- verbatim, untruncated
[9f2c41ab] << 200 {"status":"stored","review_group":"8630A","reviews":2}
```

The body is the exact bytes posted, not a re-encode of the decoded array — only that can settle
an argument about what IXPF sent. `X-Auth-Token`, `Authorization`, `Cookie` and `X-Api-Key`
values are replaced with `*** redacted ***`: the integration token arrives on every request and
a log file is not a place to keep credentials.

A request that fails the token check is logged with its headers but **not** its body:

```
[c2c7dd1a] >> POST … Headers: {…}
Body: <5011 bytes, not logged: unauthenticated>
[c2c7dd1a] << 401 {"message":"Unauthorized token"}
```

The endpoint is public and unrate-limited, so writing an attacker-chosen payload of any size to
disk on every rejected request is a way to fill the volume rather than an audit trail. Headers
and the byte count still identify who is knocking.

Between those two lines sits what the database did:

- **push accepted** — group key, added/updated/removed/skipped counts, media queued, elapsed ms
- **stale push** — ignored, with both timestamps
- **rolled back** — "nothing written", with the reason
- **warning** — payloads over `warn_threshold`
- **exception** — also copied to the exception file above

Nothing prunes these files, matching the other IXPF logs.

**If the log file is empty when you expect entries, check ownership first.** `BaseLogger` creates
its folder with `mkdir(0777)`, which umask turns into `0755`, so whichever process writes first
owns it — and on this stack CLI runs as root while PHP-FPM runs as www-data. A cron or console
write landing before any web write leaves a root-owned directory the endpoint cannot write into,
and `BaseLogger` swallows the failure and returns `false`, so the request still succeeds with
nothing logged. Fix with `chown -R www-data:www-data var/log/ixpf`. This applies to every IXPF
log channel, not just reviews.

## Operational limits

A payload that exceeds any of these fails in a way that looks like a Magento bug rather than a
limit. Check these before blaming the module.

| Limit | Where | Needs to allow |
|---|---|---|
| `client_max_body_size` | nginx | The largest expected payload, plus headroom |
| `post_max_size`, `memory_limit` | PHP | Same — the decoded array is several times the raw JSON |
| `max_execution_time` | PHP-FPM | The apply, which is proportional to what changed, not to pool size |
| Request timeout | Cloudflare / Traefik | The same ceiling |

Gzip the request body. The payload is highly repetitive text across many locales and compresses
very well, which moves the practical ceiling out by roughly an order of magnitude for no code
change on either side.

If a group ever outgrows a synchronous push, the fallback is to accept the body, queue it and
return 202. Not built — the diff apply removes most of the pressure, and the `warn_threshold`
log is the signal for when it becomes real.
