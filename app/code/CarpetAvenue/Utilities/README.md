# CarpetAvenue\_Utilities

> General-purpose utility tools for the CarpetAvenue Magento store.
> **Package:** `CarpetAvenue_Utilities` · **Author:** Strativ Magento Team · **© Carpet Avenue GmbH**

---

## Manual

### What is this module?

`CarpetAvenue_Utilities` provides six standalone tools:

- **Region Manager** — Admin CRUD for `directory_country_region` entries.
- **Media Cleanup & Backup** — CLI commands to archive, delete, and restore product image files.
- **Retrieve Originals** — Admin UI to download GCS-backed originals by SKU as a ZIP.
- **Manual Media Backup** — Back up arbitrary images to GCS via CSV (UI for up to 50 paths; CLI for unlimited).
- **Attribute Override Audit & Fix** — Detect and delete store-view-specific attribute overrides so products fall back to their global value.
- **Web Images** — Allow SVG and WebP uploads across the admin (Page Builder, WYSIWYG, theme logo/favicon, product gallery, swatches, category image).

All GCS operations use the `google/cloud-storage` PHP SDK — no `gsutil` or OS-level tools required.

> **Web Images** was absorbed from a standalone module.
> See [Merged Modules](#merged-modules) for provenance and upgrade notes.

> **Config Scope Hints** used to live here (merged from `EW_ConfigScopeHints`). It moved to
> **`CarpetAvenue_Developify`** — see `app/code/CarpetAvenue/Developify/README.md`.

---

### Region Manager

**Path:** CarpetAvenue → Utilities → Region Manager

Add, edit, and delete custom country/region entries used in checkout address forms.

| Field        | Constraints             |
|--------------|-------------------------|
| Country      | Required — dropdown     |
| Region Code  | Required, max 32 chars  |
| Default Name | Required, max 255 chars |

On create, a matching `directory_country_region_name` row is inserted with `locale = en_US`.

---

### Admin Configuration

**Path:** Stores → Configuration → CarpetAvenue → Utilities

#### General

Global settings shared by all commands. CLI options always take priority over these values.

| Field                  | Description                                                                                      |
|------------------------|--------------------------------------------------------------------------------------------------|
| **Master Enable**      | Global enable/disable switch for all Media Cleanup & Backup commands.                            |
| **GCS Bucket Name**    | Google Cloud Storage bucket name (without `gs://`). Used unless overridden with `--bucket`.      |
| **GCS Credentials JSON** | Upload a GCP service-account key JSON file. Stored under `var/carpetavenue/gcs/`. Leave empty for Application Default Credentials (ADC). |

> **Migration note** (if upgrading from a version before the General group was added): run this SQL to move existing config values:
> ```sql
> UPDATE core_config_data
> SET path = REPLACE(path, 'carpetavenue_utilities/media_cleanup/', 'carpetavenue_utilities/general/')
> WHERE path IN (
>     'carpetavenue_utilities/media_cleanup/enabled',
>     'carpetavenue_utilities/media_cleanup/gcs_bucket',
>     'carpetavenue_utilities/media_cleanup/gcs_credentials'
> );
> ```

#### Media Cleanup & Backup — per-command toggles

Each command also has its own settings group under **Media Cleanup & Backup** (e.g. ID ranges, limits, staging dir, ZIP enable). Refer to the CLI option tables below; config values are the defaults.

| Group                   | Admin path                                      |
|-------------------------|-------------------------------------------------|
| Disabled Cache Cleanup  | Media Cleanup & Backup → Disabled Cache Cleanup |
| Orphan Cache Cleanup    | Media Cleanup & Backup → Orphan Cache Cleanup   |
| Disabled Image Resize   | Media Cleanup & Backup → Disabled Image Resize  |
| Manual Media Backup     | Media Cleanup & Backup → Manual Media Backup    |

The **Disabled Image Resize** group also contains:
- **Re-quantify Small Images** — when enabled, images already within max dimensions are re-saved at the configured JPEG quality instead of being skipped (equivalent to `--requalify` CLI flag).
- **Retrieve Originals** link — opens the Retrieve Originals admin page.

The **Manual Media Backup** group contains:
- **Enable** — enable/disable toggle.
- **GCS Path Prefix** — stored config value used by both UI and CLI (e.g. `manual_backup` or `manual_backup/2024-jan`). Default: `manual_backup`.
- **Upload CLI CSV** — file upload saved as `cli_input_{timestamp}.csv` under `var/carpetavenue/manual_backup/`. Stored path shown after save — copy for `--csv=` option.
- **Last Backup Result** — download link for latest result CSV (shared by UI and CLI runs).
- **Manual Backup Page** link — opens the admin UI page.

---

### Media Cleanup & Backup (CLI)

Five CLI commands. All default to **dry-run** — pass `--live` to make real changes (except `carpetavenue:media:manual-backup` which has no dry-run mode).

**Safety workflow (with `--bucket`):**
Stage → [ZIP → GCS ZIP upload] → GCS raw upload (PHP SDK) → Delete/Resize

The destructive step only runs **after** GCS upload succeeds. On failure the operation aborts and staging files are kept locally. After a successful upload with zero errors the local staging directory is removed automatically.

**Progress:** all commands print `N/total (X%)` progress lines during the upload, delete, and restore loops.

#### `carpetavenue:media:cleanup:disabled-cache`

Deletes cache images for disabled products. Originals are never touched. Role images (image, small_image, thumbnail, swatch_image) are always kept.

```bash
bin/magento carpetavenue:media:cleanup:disabled-cache [--live] [options]
```

| Option                      | Default | Description                            |
|-----------------------------|---------|----------------------------------------|
| `--live`                    | —       | Perform deletion (default: dry-run)    |
| `--id-from=N` / `--id-to=N` | config  | Product entity_id range                |
| `--limit=N`                 | config  | Max files to process (`0` = unlimited) |
| `--zip`                     | config  | Create ZIP archive + upload via SDK    |
| `--bucket=NAME`             | config  | GCS bucket (no `gs://`)                |
| `--credentials=PATH`        | config  | GCP service-account JSON key           |
| `--staging-dir=PATH`        | config  | Custom staging directory               |

**Execution order (live, with `--zip --bucket`):**
Fetch disabled products → Scan cache dir → Collect candidates → Stage files → ZIP → Upload ZIP (SDK) → Upload raw staging (SDK) → Delete cache files → Remove local staging (if zero errors)

#### `carpetavenue:media:cleanup:orphan-cache`

Deletes cache files with no matching original in the product media gallery.

```bash
bin/magento carpetavenue:media:cleanup:orphan-cache [--live] [--limit=N] [--zip] [--bucket=NAME] [--credentials=PATH]
```

**Execution order (live, with `--zip --bucket`):**
Build DB image set → Scan cache, find orphans → Stage files → ZIP → Upload ZIP (SDK) → Upload raw staging (SDK) → Delete orphans → Remove local staging (if zero errors)

#### `carpetavenue:media:resize:disabled`

Resizes original product images in-place for disabled products. Backs up originals to staging before any modification. Results logged to `carpetavenue_image_resize_log` DB table.

```bash
bin/magento carpetavenue:media:resize:disabled [--live] [--max-width=1200] [--max-height=1200] [--quality=82] [options]
```

| Option               | Default | Description                                                                             |
|----------------------|---------|-----------------------------------------------------------------------------------------|
| `--live`             | —       | Actually resize (default: dry-run)                                                      |
| `--id-from=N`        | config  | Min product entity_id                                                                   |
| `--id-to=N`          | config  | Max product entity_id                                                                   |
| `--limit=N`          | config  | Max images to process                                                                   |
| `--max-width=N`      | config  | Target max-width px (default: 1200)                                                     |
| `--max-height=N`     | config  | Target max-height px (default: 1200)                                                    |
| `--min-width=N`      | 0       | Skip images narrower than N px                                                          |
| `--min-height=N`     | 0       | Skip images shorter than N px                                                           |
| `--min-size=BYTES`   | 0       | Skip images smaller than N bytes (supports `KB`/`MB`/`GB` suffixes)                    |
| `--quality=N`        | config  | JPEG quality 1–100 (default: 82)                                                        |
| `--driver=NAME`      | config  | Image driver: `auto` \| `gd` \| `imagick`                                              |
| `--requalify`        | config  | Re-save images already within max dimensions at `--quality` (no dimension change)      |
| `--zip`              | config  | ZIP originals + upload via SDK before resize                                            |
| `--bucket=NAME`      | config  | GCS bucket                                                                              |
| `--credentials=PATH` | config  | GCP service-account JSON key                                                            |
| `--staging-dir=PATH` | config  | Custom staging directory                                                                |
| `--include-enabled`  | false   | Also process enabled products                                                           |
| `--skip-roles`       | false   | Skip role images (image, small_image, thumbnail)                                        |

**`--requalify`:** images whose dimensions are already within `--max-width × --max-height` are normally skipped. With `--requalify` (or the "Re-quantify Small Images" admin toggle enabled), they are re-saved at `--quality` to reduce file size without changing dimensions. Results are logged as `converted` in the DB.

After live resize, flush cache: `bin/magento cache:flush`

**Execution order (live, with `--zip --bucket`):**
Fetch products → Collect images → Filter (skip small/missing/unsupported) → Stage originals → ZIP → Upload ZIP (SDK) → Upload raw staging (SDK) → Resize/re-quantify in-place → Remove local staging (if zero errors)

#### `carpetavenue:media:restore:originals`

Restores original images from GCS back to disk. Only restores rows with `status=converted` and a recorded `bucket_remote_path`. After each successful download the row is marked `restored` in the DB.

```bash
bin/magento carpetavenue:media:restore:originals [--live] [options]
```

| Option               | Default | Description                                            |
|----------------------|---------|--------------------------------------------------------|
| `--live`             | —       | Actually download files (default: dry-run)             |
| `--bucket=NAME`      | config  | GCS bucket (no `gs://`) — required for live mode       |
| `--credentials=PATH` | config  | GCP service-account JSON key                           |
| `--limit=N`          | 0       | Max files to restore (`0` = unlimited)                 |
| `--product-id=N`     | —       | Restore only images for this product entity_id         |

After restore, flush cache: `bin/magento cache:flush`

#### `carpetavenue:media:retrieve:originals`

Downloads GCS-backed originals by SKU, packages them as a ZIP, and saves to disk. Non-interactive equivalent of the Retrieve Originals admin UI.

```bash
bin/magento carpetavenue:media:retrieve:originals --sku=SKU001,SKU002 --bucket=my-bucket [options]
```

| Option               | Description                                          |
|----------------------|------------------------------------------------------|
| `--sku=SKUS`         | Comma-separated SKU list (max 20)                    |
| `--bucket=NAME`      | GCS bucket                                           |
| `--credentials=PATH` | GCP service-account JSON key                         |
| `--output-dir=PATH`  | Directory to save the ZIP (default: `var/carpetavenue/retrieve/`) |

#### `carpetavenue:media:manual-backup`

Parses a CSV of relative image paths, stages each found file, uploads to GCS, and writes a result CSV. No row limit — suitable for large batches. Progress printed to console.

```bash
bin/magento carpetavenue:media:manual-backup --csv=/absolute/path/to/file.csv [options]
```

| Option               | Default | Description                                                   |
|----------------------|---------|---------------------------------------------------------------|
| `--csv=PATH`         | —       | **Required.** Absolute path to input CSV file                 |
| `--bucket=NAME`      | config  | GCS bucket (no `gs://`)                                       |
| `--credentials=PATH` | config  | GCP service-account JSON key (empty = ADC)                    |
| `--gcs-prefix=NAME`  | `manual_backup` | GCS object path prefix inside the bucket             |

Result CSV written to `var/log/carpetavenue/manual_backup_delete/result_{timestamp}.csv`.
Pointer updated at `var/log/carpetavenue/manual_backup_delete/latest_result.txt` — same file the UI "Download Result CSV" button reads.

---

### GCS Authentication

| Method              | How                               |
|---------------------|-----------------------------------|
| Service Account Key | `--credentials=/path/to/key.json` |
| Application Default | Omit `--credentials`              |

GCS is optional. Without `--bucket`, staging files are kept locally.

---

### Retrieve Originals (Admin UI)

**Path:** Stores → Configuration → CarpetAvenue → Utilities → Disabled Image Resize → **Retrieve Originals** (link button)

Downloads GCS-backed originals for given SKUs and packages them as a browser ZIP download.

- Enter up to **20** comma-separated SKUs.
- Only SKUs with a recorded GCS backup in `carpetavenue_image_resize_log` are included.
- Bucket and credentials are taken from the **General** admin config automatically (not shown).
- Allowed SKU characters: letters, digits, `-`, `_`, `.` — max 255 chars each.

---

### Manual Media Backup (Admin UI + CLI)

**Admin UI path:** Stores → Configuration → CarpetAvenue → Utilities → Media Cleanup & Backup → Manual Media Backup → **Manual Backup Page** (link button)

**CLI:** `bin/magento carpetavenue:media:manual-backup` — no row limit, full progress output, suitable for large batches.

| Mode | Max paths | Bucket field | Progress |
|------|-----------|--------------|----------|
| Admin UI | 50 | Editable (pre-filled from General config) | None — redirect on completion |
| CLI | Unlimited | `--bucket` option or config | Printed to console (N/total %) |

#### Admin UI Workflow

1. Configure **GCS Path Prefix** in the config panel (Stores → Config → Manual Media Backup).
2. Prepare a CSV with an `image_path` header column.
3. Open the Manual Backup Page, upload the CSV (max 50 valid paths), click **Process CSV & Backup**.
4. Download the result from the **Last Backup Result** link in the config panel, or the button on the admin page.

- GCS bucket taken from **General → GCS Bucket Name** — not shown on the UI page.
- GCS prefix taken from **Manual Media Backup → GCS Path Prefix** — not shown on the UI page.
- Input CSVs saved as `ui_input_{timestamp}.csv` in `var/log/carpetavenue/manual_backup_delete/`.
- Result CSVs and run logs also saved to `var/log/carpetavenue/manual_backup_delete/`.

#### CLI Workflow

1. Upload a CSV via the **Upload CLI CSV** field in the config panel — saved as `cli_input_{timestamp}.csv` to `var/carpetavenue/manual_backup/`.
2. Copy the saved path shown in the config after save.
3. Run: `bin/magento carpetavenue:media:manual-backup --csv={saved_path}`
4. Result CSV written to `var/log/carpetavenue/manual_backup_delete/` — downloadable from config panel.

#### Input CSV format

| Requirement | Detail |
|-------------|--------|
| Header      | Must contain `image_path` column (case-insensitive) |
| Paths       | Relative to `pub/media/catalog/product/` — no leading slash, no full path |
| Characters  | Letters, digits, `-`, `_`, `.`, `/` only |
| Extensions  | `jpg`, `jpeg`, `png`, `gif`, `webp`, `svg`, `bmp`, `tiff`, `tif` |
| Security    | Path traversal (`..`), absolute paths, null bytes — all rejected |
| UI limit    | Max **50 valid paths** per UI upload; CLI (`--csv=`) has no limit |

Example input:
```
image_path
a/i/ai.jpg
b/u/rug-image.png
cache/hash123abc/x/y/thumb.webp
```

#### Result CSV columns

| Column            | Notes                                                              |
|-------------------|--------------------------------------------------------------------|
| `image_path`      | Original relative path from input CSV                             |
| `bucket_full_path`| Full GCS URI: `gs://bucket-name/prefix/a/i/image.jpg` when backed up, empty otherwise |

GCS path prefix set in config (default: `manual_backup`). Files stored as `gs://bucket/prefix/a/i/image.jpg`.

---

### Attribute Override Audit & Fix

Magento 2 website-scope attributes propagate to all store views. When an import, admin save, or integration writes a store-view-specific row (`store_id > 0`) for a website-scope attribute, it silently overrides the global value for that store. This tool detects those rows and removes them so Magento falls back to the global (`store_id = 0`) value.

**Supported attribute types:**
- **Price** — `price`, `special_price`, `cost`, any decimal website-scope attribute
- **Image roles** — `image`, `small_image`, `thumbnail`, `swatch_image`
- **Image position** — per-store gallery order
- **Custom** — any other website-scope EAV product attribute

**Protected stores:** configured in admin — overrides for these stores are **never** detected or deleted (e.g. a Japan-specific store where per-store values are intentional).

#### Admin Configuration

**Path:** Stores → Configuration → CarpetAvenue → Utilities → **Attribute Override**

| Group | Field | Description |
|-------|-------|-------------|
| Price | Enabled / Attributes | Master toggle + which price attributes to fix |
| Image | Enabled / Override Roles / Override Position | Toggles for role and position overrides |
| Custom | Enabled / Attributes | Toggle + which website-scope custom attributes |
| Detect | (same three sub-groups) | Independent attribute selection for the scheduled detect scan |
| Protected Stores | Store IDs | Stores whose overrides are NEVER deleted |
| Auto Fix | Enabled | When detect CLI finds violations, delete them in the same run |
| Notification | Email Enabled / Email To | Send violation report email after each detect run |

#### CLI Commands

##### `carpetavenue:attribute-override:detect`

Scans DB for store-view-specific attribute rows (`store_id > 0`) for configured attributes. Every such row is a violation — it overrides the global value and should be removed.

```bash
bin/magento carpetavenue:attribute-override:detect [--dry-run] [--type=price|image|custom] [--store=1,2]
```

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | — | Print violations to stdout only. No DB writes, no email. |
| `--type` | all enabled | Limit scan to `price`, `image`, or `custom`. |
| `--store` | all non-protected | Comma-separated store view IDs to scan. |

Output table columns: `entity_id`, `sku`, `store_id`, `type`, `attribute`, `current_value`, `after_fix` (always `(global)`).

Jenkins cron example (2×/day):
```
0 6,18 * * * /var/www/magento/bin/magento carpetavenue:attribute-override:detect
```

##### `carpetavenue:attribute-override:fix`

Deletes store-view-specific attribute rows for a given scope. Always backs up current values to CSV first (unless `--dry-run`). Magento automatically falls back to the global value after deletion.

```bash
bin/magento carpetavenue:attribute-override:fix --type=price --attribute=special_price [options]
```

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | — | Show rows that would be deleted. No backup, no writes. |
| `--type` | **required** | `price`, `image`, or `custom`. |
| `--attribute` | **required** for price/custom | Attribute code to fix. |
| `--image-type` | `all` | For `--type=image`: `roles`, `position`, or `all`. |
| `--scope` | `all` | `all`, `sku`, or `id`. |
| `--values` | — | Comma-separated SKUs or entity IDs (required when `--scope != all`). |
| `--store` | `0` (all) | Store view ID. **0 or omit = all non-protected stores**. Specific ID = only that store. |
| `--no-backup` | — | Skip CSV backup (use with caution). |

Examples:
```bash
# See which special_price overrides exist on store 2
bin/magento carpetavenue:attribute-override:fix --type=price --attribute=special_price --store=2 --dry-run

# Delete special_price overrides from ALL non-protected stores
bin/magento carpetavenue:attribute-override:fix --type=price --attribute=special_price

# Delete image role overrides for specific SKUs on store 2
bin/magento carpetavenue:attribute-override:fix --type=image --image-type=roles --store=2 --scope=sku --values=SKU001,SKU002

# Delete ca_material overrides from store 1
bin/magento carpetavenue:attribute-override:fix --type=custom --attribute=ca_material --store=1
```

Backup CSVs written to `var/carpetavenue/override_backup/`. Restore by re-importing the CSV directly into the relevant EAV table.

#### Manual Fix (Admin UI)

**Path:** CarpetAvenue → Utilities → Attribute Override

1. Select attribute type and code.
2. Select scope: All Products / Specific SKUs / Specific IDs.
3. Select store view (required for admin UI; use CLI for all-stores fix).
4. Run **Dry Run** first — preview table shows `current_value → (global)`.
5. Run Fix — backs up to CSV, deletes store-view rows, clears cache.

The **Run Fix** button is disabled until a dry run has been executed for the current selection.

---

### Web Images (SVG / WebP uploads)

Magento's upload whitelists reject `svg` and `webp` in most admin surfaces. This feature widens
those whitelists and swaps in a GD2 adapter that understands both formats.

**No admin configuration** — it is always on once the module is enabled. The allowed extension
lists live in `config.xml` under `carpetavenue_utilities/webimages/extensions` and are read by
`WebImages\Helper\ImageHelper`.

Covered surfaces:

| Surface | Mechanism |
|---------|-----------|
| Page Builder image upload | `UploaderFactory` injected into `PageBuilder\...\Image\Upload` |
| WYSIWYG editor / media browser | Plugins on `Cms\Model\Wysiwyg\Images\Storage`, `...\Wysiwyg\Directive`, `...\Wysiwyg\Images\Thumbnail` |
| Theme logo & favicon | Plugin on `Theme\Model\Design\Backend\{Logo,Favicon,Image}` |
| Product media gallery | Plugin on `Catalog\Model\Product\Gallery\Processor` |
| Attribute swatch images | Plugin on `Swatches\Helper\Media` |
| Category image | `Magento\Catalog\CategoryImageUpload` virtualType arguments |
| Media storage serving & validation | Plugins on `MediaStorage\App\Media`, `...\File\Uploader`, `...\File\Validator\NotProtectedExtension` |
| Media gallery sync | `AssetFactory` + `fileExtensions` / `imageExtensions` arguments |

The GD2 swap is a **config value**, not a preference:
`dev/image/adapters/GD2/class` → `CarpetAvenue\Utilities\WebImages\Model\Image\Adapter\Gd2`.
That core path must not be renamed.

> **Not covered:** the product CSV import path. It uses its own uploader —
> `CarpetAvenue\Core\Model\Import\Uploader` — with a separate hardcoded mime map.

**Known issue:** if an uploaded image saves but 404s in the browser, the web server is blocking the
extension. Check the nginx/apache config, not Magento.

---

### Merged Modules

`MagestyApps_WebImages` was absorbed into `CarpetAvenue_Utilities`. It was a vendored fork in
`app/code` — never required through Composer, so nothing is lost from the upgrade path. Upstream
copyright headers are retained.

| Was | Now | Upstream |
|-----|-----|----------|
| `MagestyApps_WebImages` | `CarpetAvenue\Utilities\WebImages\*` | [MagestyApps/module-web-images](https://github.com/MagestyApps/module-web-images) |

`EW_ConfigScopeHints` was absorbed here too, then moved on to `CarpetAvenue_Developify`
(`CarpetAvenue\Developify\ConfigScopeHints\*`). Its history and deploy notes live in that
module's README.

What changed in the merge:

| Item | Before | After |
|------|--------|-------|
| Config path | `magestyapps_webimages/extensions/*` | `carpetavenue_utilities/webimages/extensions/*` |
| GD2 adapter class | `MagestyApps\WebImages\Model\Image\Adapter\Gd2` | `CarpetAvenue\Utilities\WebImages\Model\Image\Adapter\Gd2` |
| RequireJS mixin | `MagestyApps_WebImages/js/form/element/image-uploader-mixin` | `CarpetAvenue_Utilities/js/form/element/image-uploader-mixin` |
| DI plugin names | `allow-web-images`, … | `carpetavenue_utilities_webimages_*` |

The config-path rename was safe because `core_config_data` held **zero** rows under
`magestyapps_webimages` or `dev/image` — all values came from `config.xml` defaults. No data
migration is required.

**Deploy checklist for existing environments:**

```bash
# stale static content for the removed modules must be cleared, or
# setup:static-content:deploy fails trying to read the old paths
rm -rf pub/static/adminhtml var/view_preprocessed
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f --area adminhtml en_US
bin/magento cache:flush
```

---

### Removed: Webkul POS coupon filter

`CarpetAvenue\Utilities\Plugin\PosCouponRepositoryPlugin` plugged into
`Webkul\Pos\Model\SalesRule\PosCouponRepository` to hide coupons whose
`salesrule.pos_enabled` flag was off. **`Webkul_Pos` is not installed** — not in
`app/code`, not in `vendor/`, not in `app/etc/config.php` — so the plugin could
never fire, and its `<type>` entry pointed at a class that does not exist. The
plugin class, its di.xml entry, and the `Webkul_Pos` sequence in `module.xml`
were removed.

What deliberately stays, because it is independent of that module and may be
read by the ERP sync:

- the `salesrule.pos_enabled` column (`etc/db_schema.xml`)
- the POS visibility toggle on the cart-rule form (`ui_component/sales_rule_form.xml`)
- the `Magento_SalesRule` sequence

If `Webkul_Pos` is ever installed, restore the plugin from git history
(`git log -- app/code/CarpetAvenue/Utilities/Plugin/PosCouponRepositoryPlugin.php`).

---

### Recommended Order of Operations

1. `carpetavenue:media:cleanup:disabled-cache` — remove cache images for disabled products
2. `carpetavenue:media:cleanup:orphan-cache` — remove orphan cache images
3. `carpetavenue:media:resize:disabled` — resize original images for disabled products

To undo a resize:

4. `carpetavenue:media:restore:originals` — download originals back from GCS

Always run dry-run first. Verify the log. Then run with `--live`.

---

### Installation

```bash
bin/magento module:enable CarpetAvenue_Utilities
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

**Requirements:** PHP 8.1+, Magento 2.4.x, `ZipArchive` extension, `GD` or `Imagick`, `google/cloud-storage` Composer package (for GCS backup/restore/retrieve).

---

## Developer

### Architecture

```
CarpetAvenue_Utilities/
├── Block/Adminhtml/
│   ├── AttributeOverride/
│   │   └── ManualFix.php             # Template data — Attribute Override fix page
│   ├── Media/
│   │   ├── ManualBackup.php          # Template data — Manual Backup page
│   │   └── Retrieve.php              # Template data — Retrieve Originals page
│   └── System/Config/
│       ├── ManualBackupLink.php       # Config field renderer — link to Manual Backup page
│       └── RetrieveLink.php          # Config field renderer — link to Retrieve page
├── Console/Command/
│   ├── AttributeOverride/
│   │   ├── DetectCommand.php         # carpetavenue:attribute-override:detect
│   │   ├── FixCommand.php            # carpetavenue:attribute-override:fix
│   │   ├── SnapshotCommand.php       # carpetavenue:attribute-override:snapshot (optional)
│   │   └── ImportCommand.php         # carpetavenue:attribute-override:import (optional)
│   └── Media/                        # 6 CLI command classes
│       ├── CleanupDisabledCacheCommand.php
│       ├── CleanupOrphanCacheCommand.php
│       ├── ResizeDisabledCommand.php
│       ├── RestoreFromGcsCommand.php
│       ├── RetrieveOriginalsCommand.php
│       └── ManualBackupCommand.php
├── Controller/Adminhtml/
│   ├── AttributeOverride/
│   │   ├── Index.php                 # GET — renders manual fix form
│   │   ├── DryRun.php                # POST AJAX — dry run preview (requires specific store_id)
│   │   └── RunFix.php                # POST — backup + delete overrides (requires specific store_id)
│   ├── Media/
│   │   ├── ManualBackup/
│   │   │   ├── Index.php             # GET — renders Manual Backup form
│   │   │   ├── Process.php           # POST — handles CSV upload, runs service
│   │   │   └── DownloadResult.php    # GET — streams latest result CSV
│   │   └── Retrieve/
│   │       ├── Index.php             # GET — renders Retrieve Originals form
│   │       └── Download.php          # POST — validates SKUs, downloads from GCS, streams ZIP
│   └── Region/                       # Index, New, Edit, Save, Delete (standard CRUD)
├── Helper/Config.php                 # Admin config value getters (all groups)
├── Model/
│   ├── AttributeOverrideValue.php    # Model for optional intended-value table
│   ├── AttributeOverrideValueRepository.php
│   ├── OverrideEventLog.php          # Model for detect run violation log
│   ├── OverrideBackupLog.php         # Model for backup CSV log
│   ├── ImageResizeLog.php
│   ├── ImageResizeLogRepository.php
│   ├── ResourceModel/
│   │   ├── AttributeOverrideValue.php
│   │   ├── AttributeOverrideValue/Collection.php
│   │   ├── AttributeOverrideValue/Grid/Collection.php
│   │   ├── OverrideEventLog.php
│   │   ├── OverrideBackupLog.php
│   │   ├── ImageResizeLog.php
│   │   └── ImageResizeLog/Collection.php
│   └── Config/
│       ├── Backend/GcsCredentials.php
│       ├── Backend/ManualBackupCsv.php
│       ├── Source/ImageDriver.php
│       ├── Source/WebsiteScopePriceAttributes.php   # Multiselect: price-type website-scope attrs
│       └── Source/WebsiteScopeCustomAttributes.php  # Multiselect: custom website-scope attrs
├── Service/
│   ├── AttributeOverride/
│   │   ├── DetectService.php         # Find all store_id>0 rows for configured attributes
│   │   ├── FixService.php            # Orchestrate: load → backup → DELETE → cache clear
│   │   ├── PriceOverrideService.php  # loadStoreViewRows + deleteStoreViewRows (decimal table)
│   │   ├── ImageOverrideService.php  # loadStoreViewRoles/Positions + delete variants
│   │   ├── CustomAttributeOverrideService.php  # loadStoreViewRows + deleteStoreViewRows
│   │   ├── BackupService.php         # CSV export before delete (preloaded + DB-query variants)
│   │   ├── SnapshotService.php       # Snapshot current values into override_value table (optional)
│   │   └── NotificationService.php   # Email report after detect run
│   └── Media/                        # Business logic
│       ├── CleanupDisabledCacheService.php
│       ├── CleanupOrphanCacheService.php
│       ├── ResizeDisabledService.php
│       ├── RestoreFromGcsService.php
│       ├── RetrieveOriginalsService.php
│       ├── ManualBackupService.php
│       └── GcsService.php
├── Ui/
│   ├── Component/Listing/Column/AttributeOverrideValueActions.php
│   └── (Region listing column + form data provider)
├── WebImages/                        # merged from MagestyApps_WebImages
│   ├── Helper/ImageHelper.php        # svg/webp extension lists from config.xml
│   ├── Model/
│   │   ├── AssetFactory.php          # media-gallery sync asset factory
│   │   ├── File/Uploader.php         # + UploaderFactory.php (Page Builder)
│   │   └── Image/Adapter/Gd2.php     # GD2 subclass wired via dev/image/adapters/GD2/class
│   └── Plugin/                       # 11 plugins widening upload/serve whitelists
│       ├── App/MediaPlugin.php
│       ├── Controller/Adminhtml/Wysiwyg/{DirectivePlugin,Images/ThumbnailPlugin}.php
│       ├── Design/Backend/ImagePlugin.php
│       ├── File/{UploaderPlugin,Validator/NotProtectedExtensionPlugin}.php
│       ├── Product/Gallery/ProcessorPlugin.php
│       ├── Swatches/Helper/MediaPlugin.php
│       └── Wysiwyg/Images/StoragePlugin.php
├── etc/
│   ├── module.xml / di.xml / acl.xml / config.xml
│   ├── db_schema.xml                 # All CA tables (image_resize_log + 3 override tables)
│   └── adminhtml/
│       ├── menu.xml                  # Utilities → Region Manager, Attribute Override
│       ├── routes.xml
│       └── system.xml                # Config sections: general + media_cleanup + attribute_override
└── view/adminhtml/
    ├── requirejs-config.js           # image-uploader mixin (Web Images)
    ├── layout/
    │   ├── utilities_attributeoverride_index.xml
    │   ├── utilities_attributeoverride_values_index.xml
    │   ├── utilities_media_manualbackup_index.xml
    │   └── utilities_media_retrieve_index.xml
    ├── templates/
    │   ├── attributeoverride/manual_fix.phtml
    │   └── media/
    │       ├── manual_backup.phtml
    │       └── retrieve.phtml
    ├── email/
    │   └── attribute_override_detect_report.html
    ├── layout/ (region)
    ├── ui_component/
    │   ├── utilities_attributeoverride_value_listing.xml
    │   └── (region listing + form)
    └── web/
        ├── css/source/_module.less   # Web Images admin styles
        └── js/form/element/image-uploader-mixin.js
```

### Key Service Classes

**Attribute Override:**

| Class | Responsibility |
|-------|---------------|
| `Service/AttributeOverride/DetectService.php` | Find all `store_id > 0` rows for configured attributes (no intended-table lookup) |
| `Service/AttributeOverride/FixService.php` | Orchestrate: load store-view rows → backup preloaded → DELETE → cache clear |
| `Service/AttributeOverride/PriceOverrideService.php` | `loadStoreViewRows()` + `deleteStoreViewRows()` on `catalog_product_entity_decimal` |
| `Service/AttributeOverride/ImageOverrideService.php` | `loadStoreViewRoles/Positions()` + `deleteStoreViewRoles/Positions()` |
| `Service/AttributeOverride/CustomAttributeOverrideService.php` | `loadStoreViewRows()` + `deleteStoreViewRows()` on EAV tables (routes by backend_type) |
| `Service/AttributeOverride/BackupService.php` | CSV export before deletion — preloaded-row variants (no double-query) |
| `Service/AttributeOverride/NotificationService.php` | Email report after detect run (Magento TransportBuilder) |
| `Service/AttributeOverride/SnapshotService.php` | Optional: snapshot current store-view values into `carpetavenue_attribute_override_value` |

**Media:**

| Class                                           | Responsibility                                                                   |
|-------------------------------------------------|----------------------------------------------------------------------------------|
| `Service/Media/CleanupDisabledCacheService.php` | Scan → stage → [zip → GCS SDK] → raw upload (SDK) → delete → staging cleanup    |
| `Service/Media/CleanupOrphanCacheService.php`   | Scan → stage → [zip → GCS SDK] → raw upload (SDK) → delete → staging cleanup    |
| `Service/Media/ResizeDisabledService.php`       | Stage → [zip → GCS SDK] → raw upload (SDK) → resize/re-quantify → DB log → staging cleanup |
| `Service/Media/RestoreFromGcsService.php`       | Query DB → download from GCS (SDK) → `markRestored()`                            |
| `Service/Media/RetrieveOriginalsService.php`    | Validate SKUs → DB lookup → download from GCS (SDK) → ZIP → serve               |
| `Service/Media/ManualBackupService.php`         | Parse/validate CSV → stage files → GCS upload → write result CSV                 |
| `Service/Media/GcsService.php`                  | `uploadZip()`, `uploadRawFiles()`, `fmtBytes()` — PHP SDK only, curl_multi batch |
| `Model/ImageResizeLogRepository.php`            | DB upsert/query for `carpetavenue_image_resize_log`                               |
| `Helper/Config.php`                             | Reads all admin config values with typed getters                                  |

### DB Tables: Attribute Override

Three tables added by `db_schema.xml` for the Attribute Override feature.

#### `carpetavenue_override_event_log`

Detect CLI run log — one row per violation found.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int PK AI | |
| `entity_id` | int | product entity_id |
| `store_id` | smallint | the store view that has the override |
| `attribute_type` | varchar(20) | `price` / `image_role` / `image_position` / `custom` |
| `attribute_code` | varchar(255) | EAV attribute code (or gallery file path for image_position) |
| `old_value` | text | the store-view override value (the row that was/will be deleted) |
| `new_value` | text | null — after fix Magento uses global value |
| `auto_fixed` | tinyint | 1 = deleted in the same detect run |
| `run_id` | varchar(40) | UUID grouping all rows from one CLI run |
| `dry_run` | tinyint | 1 = detect ran with `--dry-run` (no writes) |
| `created_at` | timestamp | |

#### `carpetavenue_override_backup_log`

Tracks backup CSV files written before each fix run.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int PK AI | |
| `attribute_type` | varchar(20) | |
| `attribute_code` | varchar(255) | |
| `store_id` | smallint | 0 = all stores backed up in one file |
| `scope` | varchar(10) | `all` / `sku` / `id` |
| `scope_values` | text | comma-sep SKUs or IDs |
| `csv_path` | varchar(512) | path under `var/carpetavenue/override_backup/` |
| `row_count` | int | |
| `status` | varchar(20) | `pending` / `done` / `failed` |
| `created_at` | timestamp | |

#### `carpetavenue_attribute_override_value`

Optional reference table. Stores per-store-view intended values populated by `SnapshotService` or CSV import. Not used by the main detect/fix flow.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int PK AI | |
| `entity_id` | int | |
| `store_id` | smallint | |
| `attribute_type` | varchar(20) | |
| `attribute_code` | varchar(255) | |
| `value` | text | |
| `created_at` / `updated_at` | timestamp | |

Unique key: `(entity_id, store_id, attribute_type, attribute_code)`

---

### DB Table: `carpetavenue_image_resize_log`

Tracks every image considered by `ResizeDisabledService`. Written on live runs only (not dry-run). Used by `RestoreFromGcsService` and `RetrieveOriginalsService` for GCS path lookup.

| Column               | Type        | Notes                                           |
|----------------------|-------------|-------------------------------------------------|
| `id`                 | int PK AI   |                                                 |
| `product_id`         | int         |                                                 |
| `sku`                | varchar     |                                                 |
| `image_type`         | varchar(16) | `main` (role image) or `gallery`                |
| `local_path`         | varchar     | Unique — absolute path on disk                  |
| `db_path`            | varchar     | Relative path from DB gallery value             |
| `bucket_remote_path` | varchar     | Full GCS URI of staged original (`gs://...`)    |
| `before_size/width/height` | int   | Metrics before resize                          |
| `after_size/width/height`  | int   | Metrics after resize (null for skipped/error)   |
| `status`             | varchar(16) | `pending` / `converted` / `skipped` / `error` / `restored` |
| `skip_reason`        | text        | Human-readable reason for skipped/error rows    |
| `created_at`         | timestamp   |                                                 |
| `updated_at`         | timestamp   | Auto-updated on write                           |
| `restored_at`        | timestamp   | Set by `markRestored()`                         |

### GCS Upload Strategy

| Upload type       | Method                         | When                                         |
|-------------------|--------------------------------|----------------------------------------------|
| ZIP archive       | `GcsService::uploadZip()`      | When `--zip --bucket` set                    |
| Raw staging files | `GcsService::uploadRawFiles()` | When `--bucket` set, BEFORE delete/resize    |
| Manual backup     | `GcsService::uploadRawFiles()` | Called by `ManualBackupService::execute()`   |

`uploadRawFiles()` uses `curl_multi` for parallel uploads (no `pcntl`). Progress is logged every ~5% of total files. If upload fails, the downstream destructive step does not run.

### Staging Cleanup Behaviour

After a successful live run where all files were uploaded to GCS with zero errors, the local staging directory is deleted automatically. Staging is **kept** if:

- No GCS bucket was configured (local-only staging).
- Any upload error occurred.
- Run was dry-run.

### Progress Format

All service loops emit progress in the format `N/total (X%)` on each significant action:

- **GCS upload** (`GcsService::uploadRawFiles`): logged every ~5% of files.
- **Delete loops** (disabled-cache, orphan-cache): logged per file with counter prefix.
- **Resize loop** (`ResizeDisabledService`): logged per file with counter prefix.
- **Restore loop** (`RestoreFromGcsService`): logged per file with counter prefix.

### Admin Routes

**Attribute Override:**

| Route | Controller | Action |
|-------|-----------|--------|
| `utilities/attributeoverride/index` | `AttributeOverride/Index` | Render manual fix form |
| `utilities/attributeoverride/dryrun` | `AttributeOverride/DryRun` | POST AJAX — dry run preview |
| `utilities/attributeoverride/runfix` | `AttributeOverride/RunFix` | POST — backup + delete overrides |

**Media:**

| Route                              | Controller                         | Action               |
|------------------------------------|------------------------------------|----------------------|
| `utilities/media_manualbackup/index`    | `ManualBackup/Index`          | Render form          |
| `utilities/media_manualbackup/process` | `ManualBackup/Process`         | POST — process CSV   |
| `utilities/media_manualbackup/downloadresult` | `ManualBackup/DownloadResult` | Stream result CSV |
| `utilities/media_retrieve/index`   | `Retrieve/Index`                   | Render form          |
| `utilities/media_retrieve/download`| `Retrieve/Download`                | POST — stream ZIP    |

### ManualBackupService — Path Validation Rules

`ManualBackupService::parseAndValidateCsv()` enforces:

1. CSV must have `image_path` header (case-insensitive).
2. Each path: no `..`, no null bytes, no absolute paths (`/` or `\` prefix).
3. Characters: `^[a-zA-Z0-9_\-\.\/]+$` only.
4. Extension must be in: `jpg jpeg png gif webp svg bmp tiff tif`.

Invalid rows are included in the result CSV with `backup=no` and a descriptive `note`.

### Runtime Directories (auto-created, `0750`)

```
var/log/carpetavenue/
├── disabled_product_cache_cleanup/{staging/, *.log, .lock}
├── orphan_cache_cleanup/{staging/, *.log, .lock}
├── disabled_product_image_resize/{staging/, *.log, .lock}
├── restore_from_gcs/{*.log, .lock}
└── manual_backup_delete/{staging_TIMESTAMP/, input_*.csv, result_*.csv, run_*.log, latest_result.txt}

var/carpetavenue/
├── gcs/                  # GCS credentials JSON (uploaded via admin config)
└── retrieve/             # Temporary ZIP files for Retrieve Originals downloads
```

### Security Notes

- All SQL values parameterised via `->where('field = ?', $val)` — no string interpolation in queries.
- No OS-level command execution — all GCS operations via PHP SDK (`google/cloud-storage`) only.
- `flock()` prevents concurrent runs of the same CLI operation.
- Custom `--staging-dir` validated: must be absolute, no null bytes.
- Staging/log dirs created at `0750` (not world-readable).
- GCS key file validated as readable before live run proceeds.
- Delete/resize step only executes after GCS upload is confirmed successful.
- CSV path validation rejects traversal, null bytes, absolute paths, invalid characters, and unsupported extensions.
- Retrieve Originals: SKUs validated against `/^[a-zA-Z0-9_\-\.]+$/`, max 20, max 255 chars; DB query uses parameterised `IN (?)`.
- GCS credentials never exposed in form HTML — taken from server-side config only.
- Manual Backup UI shows no bucket or prefix fields — both taken silently from config; only a CSV file upload is presented to the operator.

### ACL Resources

```
CarpetAvenue_Core::carpetavenue
└── CarpetAvenue_Utilities::utilities
    ├── CarpetAvenue_Utilities::region_manager
    ├── CarpetAvenue_Utilities::media_cleanup      ← covers all Media/Retrieve/ManualBackup controllers
    └── CarpetAvenue_Utilities::attribute_override ← covers DryRun + RunFix admin controllers
```

### Config.php — Path Map

**Attribute Override methods:**

| Method | Config path (under `carpetavenue_utilities/`) |
|--------|-----------------------------------------------|
| `isAttributeOverridePriceEnabled()` | `attribute_override/price/enabled` |
| `getAttributeOverridePriceAttributes(): array` | `attribute_override/price/attributes` |
| `isAttributeOverrideImageEnabled()` | `attribute_override/image/enabled` |
| `isAttributeOverrideImageRoles()` | `attribute_override/image/override_roles` |
| `isAttributeOverrideImagePosition()` | `attribute_override/image/override_position` |
| `isAttributeOverrideCustomEnabled()` | `attribute_override/custom/enabled` |
| `getAttributeOverrideCustomAttributes(): array` | `attribute_override/custom/attributes` |
| `isDetectPriceEnabled()` | `attribute_override/detect/price_enabled` |
| `getDetectPriceAttributes(): array` | `attribute_override/detect/price_attributes` |
| `isDetectImageEnabled()` | `attribute_override/detect/image_enabled` |
| `isDetectImageRoles()` | `attribute_override/detect/image_roles` |
| `isDetectImagePosition()` | `attribute_override/detect/image_position` |
| `isDetectCustomEnabled()` | `attribute_override/detect/custom_enabled` |
| `getDetectCustomAttributes(): array` | `attribute_override/detect/custom_attributes` |
| `getProtectedStoreIds(): array` | `attribute_override/protected_stores/store_ids` |
| `isAutoFixEnabled()` | `attribute_override/auto_fix/enabled` |
| `isAutoFixLogOnlyWhenDisabled()` | `attribute_override/auto_fix/log_only_when_disabled` |
| `isEmailNotificationEnabled()` | `attribute_override/notification/email_enabled` |
| `getNotificationEmailTo(): array` | `attribute_override/notification/email_to` (exploded) |
| `getNotificationEmailFrom(): string` | `attribute_override/notification/email_from` |

**General / Media methods:**

| Method                       | Config path (under `carpetavenue_utilities/`) |
|------------------------------|-----------------------------------------------|
| *(Web Images — read by `WebImages\Helper\ImageHelper`, defaults only, no system.xml)* | `webimages/extensions/vector`, `webimages/extensions/web_image` |
| `isMasterEnabled()`          | `general/enabled`                             |
| `getGcsBucket()`             | `general/gcs_bucket`                          |
| `getGcsCredentials()`        | `general/gcs_credentials`                     |
| `isManualBackupEnabled()`    | `media_cleanup/manual_backup/enabled`         |
| `getManualBackupGcsPrefix()`  | `media_cleanup/manual_backup/gcs_prefix`      |
| `isDisabledCacheEnabled()`   | `media_cleanup/disabled_cache/enabled`        |
| `isOrphanCacheEnabled()`     | `media_cleanup/orphan_cache/enabled`          |
| `isDisabledResizeEnabled()`  | `media_cleanup/disabled_resize/enabled`       |
| `isDisabledResizeRequalifySmall()` | `media_cleanup/disabled_resize/requalify_small` |
| *(+ all limit/id/size getters)* | `media_cleanup/{group}/{field}`            |

### Dependencies

| Module                 | Reason                                                        |
|------------------------|---------------------------------------------------------------|
| `Magento_Backend`      | Admin panel                                                   |
| `Magento_Directory`    | Region table                                                  |
| `Magento_Catalog`      | Product media gallery queries; Web Images gallery processor    |
| `Magento_Ui`           | Region grid/form UI components                                |
| `Magento_Cms`          | Web Images — WYSIWYG storage / directive / thumbnail plugins  |
| `Magento_MediaStorage` | Web Images — uploader, validator, media-serving plugins       |
| `Magento_Theme`        | Web Images — logo / favicon / image backend model plugins     |
| `Magento_Swatches`     | Web Images — swatch media plugin                              |
| `google/cloud-storage` | GCS upload/download (Composer package)                        |
| `ext-zip`              | ZIP archive creation for CLI + admin features                 |

`Magento_PageBuilder` and `Magento_MediaGallerySynchronization` are referenced by Web Images
`di.xml` argument overrides but are not sequenced — the overrides are inert if those modules are
absent.
