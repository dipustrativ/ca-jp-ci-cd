# Reviews — status, decisions and backlog

Audit of what is built against `IXPF Product Reviews.pdf`, plus every decision taken and
everything deliberately left. Nothing here is a surprise waiting to be found later.

Implementation docs: `ReviewsUpsert.md` (ingest, read service, admin grids, media, config).

## Spec coverage

### Core Logic & Variant Grouping — complete

| Requirement | Status |
|---|---|
| Universal variant aggregation on a master design id | ✅ `review_group` pool; products join on the `review_group` attribute. One review shows on every variant of the design |
| 1-of-1 unique inventory anchored to carpet type | ✅ Same mechanism, no extra code. The pool key is a `varchar(64)`, so `"Hamadan"` or `"Ziegler Farahan"` works as well as `8519A` — staging already does this |
| Cross-domain auto-translation with Show Original | ✅ Locale-keyed text stored per review; read-time fallback requested → source → `en_US`; toggle needs no second request because both texts ship together |
| Sub-ratings alongside the overall rating | ⚠️ Complete for Quality / Delivery / Customer Service — see limitation 1 |

### Front-End UX — complete

| Requirement | Status |
|---|---|
| Trustpilot-style layout | ✅ |
| Cards carry product thumbnail + article number | ✅ `[thumb] Art. No: AW1655 | Köpt: Vit / Beige, 300 × 384 cm` |
| Title anchor: aggregate rating + count near the title, acts as a button | ✅ Immediately above the price; opens the drawer |
| Slide-out with score, total, 1–5 distribution chart | ✅ |
| Rating dropdown + photos checkbox | ✅ Plus a sort select, which the spec does not ask for |
| Fully translated | ✅ All 44 rendered strings in all 15 non-English locales, in the theme's `i18n/` |
| Cards: name, date, comment, translation disclaimer, brand response, exact item bought | ✅ |
| Sub-ratings below the comment, horizontal | ✅ Colour-coding was built and then removed — see the decision below |

### Admin & Moderation — not built, by design

Moderation lives in IXPF. It owns collection, moderation, translation and brand replies, and
pushes only approved reviews; Magento stores a copy and calculates nothing. Building an approve
/ reject queue here would mean a second source of truth, and the next push would overwrite it.

Magento has **read-only** admin grids instead (`System → IXPF Syncer → Product Reviews` and
`→ Review Pools`) for support and sync visibility.

If in-Magento moderation is genuinely wanted, it needs a write-back API to IXPF first — that is a
project, not a task. Same for staff-authored brand replies.

### Marketing & Growth — not built

| Requirement | Status |
|---|---|
| Automated post-purchase email triggers | Out of scope — IXPF and the email platform |
| Google Shopping / Meta XML feed | **Backlog item 1** |
| schema.org review markup for rich snippets | **Backlog item 2** |

## Backlog

Ordered by value, with what each actually needs.

**1. Google Shopping / Meta XML feed.** Aggregate sits at pool level, the feed must emit it per
SKU. Data is all present (`ixpf_review_group` + the `review_group` product attribute); the work is
a feed generator, a cron and a public URL. Note Google's Product Ratings program requires a
minimum review count and its own schema — confirm eligibility before building.

**2. schema.org markup on product pages.** Cheapest remaining SEO win: `AggregateRating` from the
`review_count` / `review_average` attributes already on the product, so it stays inside
full-page-cached HTML with no query. Individual `Review` nodes would need the drawer's data at
render time, which is exactly the cost the current design avoids — so start with
`AggregateRating` only.

**3. ~~Photo lightbox.~~ Done 2026-09-24** as an in-place expand rather than a lightbox - see the
decision below. The card now renders `grid_path`.

**4. Clickable distribution bars.** `ReviewFilter` supports `rating` and it is tested; the bars
just are not wired to it. One click handler.

**5. Category-listing star widget.** `review_count` / `review_average` are `used_in_product_listing`,
so this is cheap. Not in the spec.

**6. `with_text_count` on the PDP trigger.** The number, the dual label and the with-text filter
all shipped on 2026-09-08 — but only inside the drawer. The trigger renders from the
`review_count` / `review_average` product attributes so it stays inside full-page-cached HTML, and
`with_text_count` is not a product attribute. Until IXPF pushes a fourth one through
`attributemassupdate`, the trigger shows the rating count under the word "reviews" for a number
that is now every rating, star-only included.

Ask IXPF for `review_with_text_count`; the storefront side is then one label change.

Related latent bug, not yet triggered: a review with **no `body` key at all** writes no
`ixpf_review_text` row, so `ReviewProvider` finds nothing to resolve and `continue`s — the review
is silently dropped from the list while `countTotal()` still reports `review_count`, giving short
pages and a total that never matches. The documented payload still sends `"body": {"xx_XX": ""}`
for star-only reviews, which writes a row and renders fine, so nothing is broken today. It breaks
the day IXPF omits the key.

**7. Product image placement.** Alexander noted the bought-item image sits beneath the header
rather than to the right, and asked Nick Thaulow for a view. Left as-is pending that answer;
moving it is a flex-direction change in `&__bought` plus a float on the card.

## Known limitations

**1. Sub-ratings are fixed at three dimensions.** `ixpf_review` has exactly
`rating_quality`, `rating_delivery`, `rating_customer_service`. The spec says "eg. Quality,
Delivery, and Customer Service", which implies the set could grow. **A fourth dimension IXPF
sends today would be silently dropped at ingest** — no error, no warning. Fixing it properly means
a key/value child table (`ixpf_review_rating`) rather than columns. Worth doing before IXPF adds
one, not after.

**2. Review photos do not download locally.** No GCS credentials file on this environment, so
`local_path` stays null and undownloaded photos are correctly omitted from the read side. Not a
code issue; it does mean real photos cannot be reviewed locally. `drawer.cjs` renders stand-in files
at the real aspect ratios instead.

**3. ~~PHPUnit cannot run in this project.~~ Resolved.** The duplicated PHPUnit install is gone;
`Test/Unit/` runs under PHPUnit 12 with `-c dev/tests/unit/phpunit.xml.dist` (77 tests). Its data
providers had to move from docblock `@dataProvider` to `#[DataProvider]` attributes, which
PHPUnit 12 requires. The bootstrap-driven checks and the Playwright drawer suite in `Test/e2e/`
still cover the DB round trip and the browser. See that directory's README.

**4. The flag and the translation note come from different fields.** The flag is derived from
`source_locale`'s region and the note from `source_store_code`'s domain, so a Swiss-German review
(`source_locale: de_DE`, `source_store_code: ch_de`) shows a German flag beside a Swiss domain.
Not wrong — the review really was written in German on the Swiss site — but the two read as
disagreeing. Deriving the flag from `source_store_code` instead would align them, at the cost of
mapping `ch_de` → `CH`, which is exactly the mapping the domain approach avoided needing.

## Decisions taken

Each of these was a real fork; recording the reasoning so nobody re-opens it blind.

| Decision | Why |
|---|---|
| Trigger sits **above the price**, not beside the title | Spec says "near the main title"; the title block is followed by the SKU row, brand badge and discount flag, so directly under the title would separate it from the rating context. Anchored on `product.info.price` because that is a *native* child of `product.info.main` — the title and SKU row are `<move>`d in later, and a block anchored on those silently lands at the end of the container |
| Half stars on averages, floor plus a half | 3.7 used to round to four solid stars beside the printed "3.7", which claimed more than the number. Floor plus a half for any fraction matches Kappahl (4.8 → four and a half, not five). One hard-stop gradient clipped to the glyph, so there is no second element to fall out of alignment. Per-review and sub-rating values are whole integers and so can never render one |
| Plain green star glyphs, not Trustpilot filled boxes | Boxes were built first and rejected: at these sizes five coloured blocks read as one solid bar and crowd everything near them |
| **All stars one brand green**, no colour scale | Sub-ratings were colour-coded 1★ red → 3★ amber → 4–5★ green, which the spec asked for. Alexander reported "differences in color on stars" reviewing stage: within a single card the overall stars were green while Delivery was amber, and that reads as an inconsistency rather than a signal. The star count already carries the value. Removed 2026-09-08 along with `getScoreClass()` and the `@reviews__score-*` variables |
| **Load more** button, 10 per page | Previous / Next paging replaced the original "Show more" button so the panel never grew past one page; the client asked for load more back 2026-09-26. Each click appends the next page (`page + 1`) under the reviews already shown and the button hides on the last page; a filter or sort change still replaces the list from page one. While it loads the button shows a spinner beside its label (positioned absolutely, so the label never shifts) and carries `aria-busy`; the reviews already read stay at full strength. New cards fade and rise 12px into place, 320ms each, staggered 50ms and capped at five steps so cards below the fold never hold anything back; a filter or sort change animates its new list the same way, the first open does not (the skeleton swap already reads as arrival). On the last page the button fades out over 200ms before it is hidden. With `prefers-reduced-motion` nothing moves: cards appear, the spinner becomes a dimmed button. A filter or sort change made while a request is out (the keyboard still reaches the controls) runs once it lands instead of being dropped, which used to leave a select showing one sort over a list in another When the last page hides the focused button, focus moves to the first review it added rather than dropping to `<body>`. A page past the end (the pool shrank between clicks) renders nothing, so no empty-state message lands under the list. One new string, `Load more`; `Page %1 of %2` and `Review pages` are gone from the CSVs |
| Flag derived from `source_locale` region | `da_DK` → 🇩🇰, computed from regional indicator symbols, no lookup table. Note that since the translation note moved to the domain, the two no longer come from the same field — see limitation 4 |
| Translation note names the **source market's domain** | The spec is explicit: the domain comes from `source_store_code`, *"not the language name"*, and Kappahl does the same ("skriven på Kappahl Finland"). We shipped the language name first because a `source_store_code` → domain map looked unresolved. It was not needed: every code IXPF sends matches a Magento store code, so `getStore($code)->getBaseUrl()` yields the host with nothing to maintain. Corrected 2026-09-08. It also fixes a real ambiguity — `de` and `ch_de` both render as "German", so on a language name a Swiss customer cannot tell a Swiss review from a German one. An unresolvable code still falls back to the language name |
| BUYER badge shows when `purchased_at` is set | IXPF matched the review to an order. No explicit verified flag exists in the payload |
| The card image is the **review photo**, not the bought item | Keeps "Images only" consistent with what is visible. The bought item has its own thumbnail in the variant row |
| Review photos **expand in place, Rugvista's way** | The client asked for Rugvista's behaviour 2026-09-24, so its panel was measured rather than eyeballed: 128px-tall previews at natural width in one row that scrolls sideways, and a click on any photo grows the whole row to 470px; a second click shrinks it. Sizes were then set by request 2026-09-24 as longest-side caps, shape kept: previews max 120px (`@reviews__photo-size`, portrait 90x120, landscape 120x80) and expanded max 450px (`@reviews__photo-size--expanded`), and never wider than the card - the desktop card is 444px, so a 450px landscape would otherwise scroll by 6px. We add two things Rugvista lacks: each photo is a `button` with `aria-expanded` (Enter / Space work; the existing "Photo by %1" alt is its name, nothing new to translate), and the clicked photo is scrolled into view once the row grows. Both states render the 800px `grid_path`, so expanding is a class change with no second request. Photos sit under the comment on every width |
| No visible labels on the filter selects | Every option names its own dimension ("All ratings", "Latest"). Labels forced the control row onto two lines on a phone. Accessible names moved to `aria-label` |
| Checkbox reads "Images only" | The full phrase ellipsised at 390px, and a truncated control label reads as broken. Full wording is on the `title` |
| The control row **wraps**, and the labels never ellipsise | Shortening the labels was not enough. The row was `flex-wrap: nowrap` with `flex-shrink: 0` selects, so the two checkbox labels were the only shrinkable items in it: in German they clipped to "Nur..." and "N..." while "Alle Bewertungen" kept its full width. Alexander reported it 2026-09-21. English still fits one line, so nothing wraps until a locale needs it, and the long labels were shortened in the CSVs on top of that. 2026-09-24: the two checkboxes are one group, because `space-between` pushed them to opposite edges once the row wrapped (German); the selects wrap the same way, as they overflowed a phone by up to 17px in es, pt and hu. "Text only" is now an admin setting, `show_text_filter`, off by default: with just "Images only" beside the selects the row fits one desktop line in most locales Same reason `.ixpf-reviews-dist__label` moved from a fixed `width` to `min-width` |
| Drawer ships a **skeleton**, not an empty shell | The first open fetches everything, so the panel was blank for as long as the request took and read as a broken drawer. The skeleton is static markup inside the containers the first response overwrites - no JS, no second request, still inside the full page cache. Filtering and sorting keep the dim-in-place, because the list is already populated by then and swapping a skeleton back in makes the panel jump |
| Reply heading wraps to two lines | The configured name plus a translated prefix is longer than one 390px line in most locales. Truncating hid the end of it |
| Reply is attributed to a **config value** | IXPF used to send the admin user who typed the reply - "Reply from Alexander Hamming elastycloud / Indexon" reached the storefront. We stopped rendering it 2026-09-22, and IXPF removed `reply.author` from the payload 2026-09-24, so the column is dropped. `ixpf_reviews/general/reply_name` (default "Carpet Avenue Customer Service") is store-scoped, so one global value covers every view and a single market can still localise the name. The phrase around it stays the existing `Reply from %1` i18n key |
| The bought row prints **IXPF's text per locale, as sent** | `bought.variant_text` used to be one English sentence carrying an internal colour code ("Bought a 5722 153 × 230 cm"), so for two days we regexed the size out and supplied our own label. IXPF replaced it 2026-09-24 with `variant_texts`, the finished line per store view locale ("Köpt: Vit / Beige, 300 × 384 cm"). We translate, parse and look up nothing. Stored as JSON on `ixpf_review`, not in `ixpf_review_text`: a text row's existence decides which locale the body resolves to, so a locale with only a bought line would win and blank the body. It resolves on its own - requested → `en_US` → first sent - so a review shown in its source language still gets the bought line in the viewer's. On phones the line gets its own row: the longer text wrapped inline and started the new line with the `|` separator |
| Ingest is **not** behind the enabled switch | IXPF must be able to backfill while everything downstream is dark |
| `show_on_frontend` is ANDed with `enabled` in `Config` | With the master off the read service returns zeros, so honouring the display flag alone would render a trigger that opens an empty drawer |
| Drawer uses modal `type: 'reviews'`, not `'filter'` | `Amasty_Shopby/web/js/amShopbyApplyFilters-mixin.js` removes `aside.modal-filter.filter-modal` from the DOM after filters apply, which would destroy a drawer sharing those classes on an unrelated event |
| Templates live only in the theme | Requested split: data in `IXPF_Syncer`, all visual output in `Evalent/carpet` |
| The read cache is a registered cache type, `ixpf_reviews` | Entries used to carry only the per-pool tag, which belongs to no cache type, so every `cache:clean` skipped them and only a full `cache:flush` cleared them - there was no way to tell the drawer to refresh. Both tags are written now: the type tag for `cache:clean` and Cache Management, the pool tag for precise invalidation. A dedicated button, **Stores > Configuration > IXPF > Product Reviews > Cache > Clean Review Cache** (ACL `IXPF_Syncer::product_reviews`), clears the type and nothing else. **A new cache type lands disabled** - every environment needs `bin/magento cache:enable ixpf_reviews` once, or the provider reads the database on every drawer open |
| Every writer invalidates through `ReviewCacheCleaner` | `ReviewMediaConsumer` updated `thumbnail_local` and `local_path` with no invalidation at all, so photos landing from the queue after a push stayed invisible for the provider's full 24h lifetime. One service, called by the upsert and both consumer write paths, instead of each writer cleaning its own tag |
| Translations live in the **theme** `i18n/`, not the module | That is where this theme already keeps its 16 CSVs. Module i18n would work equally well but would split the same feature's strings across two locations |
| `de_CH` / `fr_CH` get their own copies | Magento does not fall back from `de_CH` to `de_DE` for translations, and both files exist here with only Swiss-specific rows — without their own entries those views stay English |
| Average score goes through `NumberFormatter`, not `number_format()` | `number_format()` is hardcoded to a dot, which is a thousands separator in twelve of the fourteen locales: "3.7 von 5" reads as a bug to a German customer |
| The count picks between singular and plural | The distribution rows already did; the count did not, so a one-review pool read "(1 reviews)" everywhere |
| A request failing the token check is logged **without** its body | The ingest endpoint is public and unrate-limited, so writing an attacker-chosen payload of any size on every rejected request fills the volume rather than recording anything. Headers and byte count still identify the caller |

## Open questions for IXPF

Blocking or risky, in order.

1. **Production bucket prefix for review photos.** Staging uses `ixpfStageCaStageMagento/...` with
   photos at `reviews/<prefix>/<id>/<file>-p.jpg`; the spec example shows `shop/...`. A wrong
   prefix means every photo 404s. The consumer now treats a 4xx as permanent, so it fails fast
   rather than retrying — but it still fails.
2. **Is `reviews[]` always the complete set for a pool?** A sample payload carried `count: 79`
   with two reviews. If that was truncation, fine. If IXPF intends to send pages, the contract
   breaks: one call replaces the whole pool, so a second batch would delete the first. The read
   side paginates on `count`, so a mismatch shows empty pages.
3. ~~**`source_store_code` → market mapping.**~~ **Answered 2026-09-08.** No mapping is needed:
   every code in the stage data (`en`, `se`, `de`, `dk`, `ch_de`) is a real Magento store code, so
   the store's own base URL gives the domain. `ot` is the Otto marketplace store and would resolve
   to its own base URL if IXPF ever sourced a review there.
4. ~~**`reply_author` length.**~~ **Moot 2026-09-22, gone 2026-09-24.** The storefront attributes
   every reply to `ixpf_reviews/general/reply_name`, and IXPF has since removed `reply.author` from
   the payload. The column and its admin grid column are dropped.
5. **SKU casing in `thumbnail_path`.** IXPF sends `products/AWK1070/...` uppercase; every gallery
   object here is lowercase. GCS keys are case-sensitive.
6. **Will the sub-rating set ever exceed three?** Drives limitation 1 above.
7. **A `review_id` must never appear in two pools.** `review_id` is unique platform-wide, so the
   later push silently wins and the review disappears from the first pool.
8. ~~**Send `bought` as fields, not as an English sentence.**~~ **Answered 2026-09-24.** IXPF now
   sends `bought.variant_texts`, the finished line per store view locale with the colour by name
   ("Köpt: Vit / Beige, 300 × 384 cm"). The regex and our `Bought %1` label are gone.

## Verified behaviour

Checked in a real browser, not inferred:

- Trigger renders from product attributes inside cached HTML; drawer content arrives on first open
- Rating filter (5★ → 1 review, 3★ → empty state), sort (all three orders), photos-only filter
- **Distribution bars do not move when a filter is applied** — they come from the pool row
- Load more appends the next page and hides on the last one; a filter or sort change resets to page one
- Show-original toggles text and flips its own label
- Locale fallback across `en_US`, `sv_SE`, `de_DE`, `da_DK`, `ja_JP`
- `show_on_frontend` in all four on/off combinations
- A caller-supplied `review_group` is ignored; unknown or missing `product_id` returns empty
- Controls and reply header layout at a 390px panel
- Star ratings exposed to assistive tech as text ("Rated 5 out of 5", "Quality: 5 out of 5")
- No console errors
- 2026-09-24, `Test/e2e/drawer.cjs`: every storefront at desktop and 390px - bought line per locale
  and both fallbacks, reply heading per locale, photo previews / row expand / keyboard / filter
  reset, no overflow anywhere. Runs in ~22s; see `Test/e2e/README.md`

232 e2e checks: `upsert` 69, `media` 32, `read` 93, `grid` 38. Plus ~3900 browser checks in `drawer.cjs`.
