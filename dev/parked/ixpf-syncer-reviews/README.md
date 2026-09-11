# Parked: IXPF reviews read service

Nothing in this folder is loaded by Magento. It is the half of the reviews feature that **did**
land in the repo, moved out of the module's live paths so `setup:di:compile` completes.

## Why

`setup:di:compile` aborted with:

```
Class "IXPF\Syncer\Api\ReviewProviderInterface" does not exist
Impossible to process constructor argument Parameter #0 [ <required>
IXPF\Syncer\Api\ReviewProviderInterface $provider ] of IXPF\Syncer\ViewModel\Reviews\Reviews class
```

The service layer of the feature was never committed — not in any branch of this repo, and not in
the reference store at `carpetavenue/workspace/magento`, which has the identical hole. Missing:

| Missing class | Was referenced by |
|---|---|
| `IXPF\Syncer\Api\ReviewProviderInterface` | `etc/di.xml`, the view model |
| `IXPF\Syncer\Api\Data\ReviewFilterInterface` | `etc/di.xml`, the view model |
| `IXPF\Syncer\Api\Data\ReviewSummaryInterface` | `etc/di.xml`, the view model |
| `IXPF\Syncer\Api\Data\ReviewListInterface` | `etc/di.xml`, the view model |
| `IXPF\Syncer\Model\Reviews\{ReviewProvider,ReviewFilter,ReviewList,ReviewSummary,ReviewItem}` | `etc/di.xml`, the view model |
| `IXPF\Syncer\Model\Reviews\ReviewFilterFactory` | the view model (generated from the missing `ReviewFilter`) |
| `IXPF\Syncer\Services\Reviews\{ReviewMediaStorage,UpsertReviewGroup,PayloadValidator}` | the view model, the parked unit tests |
| `IXPF\Syncer\Helper\Logger\ReviewLogger` | the parked unit tests, `Test/e2e/upsert.php` |
| `IXPF\Syncer\Cron\ReviewMediaCleanup` | `etc/crontab.xml` |
| `IXPF\Syncer\Model\Source\ReviewHasPhotos` | `ixpfsyncer_review_listing.xml` |
| `ixpfsyncer/review`, `ixpfsyncer/reviewgroup` adminhtml controllers | `etc/adminhtml/menu.xml` |
| `ixpfsyncer/reviews/load` frontend controller | the parked view model's `getLoadUrl()` |
| `IXPF_Syncer::reviews/trigger.phtml`, `reviews/drawer.phtml` | the parked `catalog_product_view.xml` (they live in the theme, not the module) |

## What moved here

| Parked path | Original path |
|---|---|
| `ViewModel/Reviews/Reviews.php` | `app/code/IXPF/Syncer/ViewModel/Reviews/Reviews.php` |
| `Ui/Component/Listing/Column/ReviewGroupLabel.php` | same, under the module root |
| `view/adminhtml/layout/ixpfsyncer_review_index.xml` | same |
| `view/adminhtml/layout/ixpfsyncer_reviewgroup_index.xml` | same |
| `view/adminhtml/ui_component/ixpfsyncer_review_listing.xml` | same |
| `view/adminhtml/ui_component/ixpfsyncer_reviewgroup_listing.xml` | same |
| `view/frontend/layout/catalog_product_view.xml` | same (the module's only `catalog_product_view.xml`) |
| `Test/Unit/Services/Reviews/{PayloadValidatorTest,UpsertReviewGroupTest}.php` | same |
| `Test/e2e/read.php` | same |

## What was commented out in place

- `etc/di.xml` — the four `Review*` preferences and the `ReviewLogger` note.
- `etc/crontab.xml` — the `ixpf_review_media_cleanup` job.
- `etc/adminhtml/menu.xml` — the "Product Reviews" and "Review Pools" entries.

## What was deliberately left alone

- `etc/db_schema.xml` — `ixpf_review_group`, `ixpf_review`, `ixpf_review_text`, `ixpf_review_photo`.
  The tables stay; dropping them would lose whatever IXPF has already pushed.
- `etc/adminhtml/system.xml` — the `ixpf_reviews` section, and the matching getters in
  `Helper/Config.php`. Both are inert without the service layer and harmless.
- `etc/acl.xml` — `IXPF_Syncer::product_reviews` and `IXPF_Syncer::review_pools`. Unused while the
  menu entries are commented out.
- `Test/fixture/review_group_8518C*.json` — payload fixtures for the parked tests.
- `Test/e2e/upsert.php` — still references `ReviewLogger`, so it cannot run, but it is a standalone
  script that nothing loads.

## Restoring

Move every file back to the path in the table above, uncomment the three XML blocks, then
`setup:upgrade` + `setup:di:compile`. The feature still needs the missing classes in the first
table before any of it works.
