# Plan: Attribute Override System (Image / Price / Custom)

**Module:** `CarpetAvenue_Utilities`
**Config section:** `carpetavenue_utilities` → group `attribute_override`

---

## Problem Summary

Magento 2 multistore: attributes scoped at **website** level propagate across all store views in that website.
Affected attribute types:
- **Price** → `price`, `special_price`, `cost` → stored in `catalog_product_entity_decimal`
- **Image** → roles (base/small/thumbnail) + gallery position → stored in `catalog_product_entity_media_gallery` + `catalog_product_entity_media_gallery_value`
- **Custom** → any other website-scope EAV attribute (e.g. `ca_*` custom attributes) → stored in `catalog_product_entity_varchar/int/text/decimal`

We **cannot** change Magento core attribute scope. Instead: **detect rows with `store_id > 0` → backup → DELETE them → Magento falls back to global (`store_id = 0`) value**.

---

## Core Concept: Delete-as-Default

A store-view-specific row (`store_id > 0`) for a website-scope attribute means someone (import, admin save, sync) wrote an override value for that store. This prevents the product from using the global value.

**Violation** = any row with `store_id > 0` for a configured attribute (excluding protected stores).
**Fix** = `DELETE` that row → Magento automatically uses `store_id = 0` (global/admin) value instead.

No "intended value table" needed for the main detect/fix flow.

---

## Architecture Overview

```
Admin Settings (system.xml)
├── Override Config      ← which attributes to watch per type
│   ├── Price section    (yes/no + which price attributes)
│   ├── Image section    (yes/no + roles/position toggles)
│   └── Custom section   (yes/no + which website-scope attributes)
│
├── Detect Config        ← separate attribute selection for scheduled scan
│   ├── Price section
│   ├── Image section
│   └── Custom section
│
├── Auto Fix             ← yes/no: detect CLI also deletes violations when found
│
├── Protected Stores     ← store IDs to NEVER delete overrides from (e.g. Japan)
│
└── Notification         ← email address(es), future: Slack webhook

CLI Commands (run via Jenkins 2×/day)
├── carpetavenue:attribute-override:detect   ← scan for store_id>0 rows, email report
│   └── --dry-run: report only, no writes, no email (stdout only)
│
└── carpetavenue:attribute-override:fix      ← backup CSV → DELETE store-view rows
    └── --dry-run: show what would be deleted, no backup, no writes
        --type=price|image|custom
        --attribute=<code>
        --store=<store_id|0>   0 or omit = all non-protected stores
        --scope=all|sku|id
        --values=<sku1,sku2 or id1,id2>

Admin Page: Manual Fix Tool
├── Step 1: Select type + attribute code
├── Step 2: Select scope (all products / SKU list / ID list)
├── Step 3: Store view selector (specific store required for admin page)
├── [x] Dry Run mode      ← shows preview table (current_value → will use global)
└── Step 4: Backup CSV → Apply fix (when dry run off)

Custom DB tables
├── carpetavenue_attribute_override_value    ← optional: snapshot/intended values (not used in main fix flow)
├── carpetavenue_override_event_log          ← detect run log (violations found)
└── carpetavenue_override_backup_log         ← CSV backup history
```

---

## Admin Settings Groups (system.xml additions)

New top-level group: `attribute_override` (sortOrder 300), sibling to `media_cleanup`.

### 1. Override Settings — `attribute_override/price`

| Field | Type | Notes |
|-------|------|-------|
| `enabled` | Yes/No | Master toggle for price override |
| `attributes` | multiselect | Source: price-type attributes with website scope (price, special_price, cost + any decimal website-scope) |

### 2. Override Settings — `attribute_override/image`

| Field | Type | Notes |
|-------|------|-------|
| `enabled` | Yes/No | Master toggle for image override |
| `override_roles` | Yes/No | Watch base/small/thumbnail roles per store view |
| `override_position` | Yes/No | Watch gallery image position per store view |

### 3. Override Settings — `attribute_override/custom`

| Field | Type | Notes |
|-------|------|-------|
| `enabled` | Yes/No | Master toggle |
| `attributes` | multiselect | Source model: all EAV attributes with scope = website, excluding price/image types |

### 4. Detect Settings — `attribute_override/detect`

Separate group with same three sub-groups (`price`, `image`, `custom`) but independent attribute selections.
Rationale: you may want to fix all fields but scan only a subset via scheduled CLI.

| Field | Type | Notes |
|-------|------|-------|
| `price/enabled` | Yes/No | |
| `price/attributes` | multiselect | Same source as override price |
| `image/enabled` | Yes/No | |
| `image/override_roles` | Yes/No | |
| `image/override_position` | Yes/No | |
| `custom/enabled` | Yes/No | |
| `custom/attributes` | multiselect | Same source as override custom |

### 5. Protected Stores — `attribute_override/protected_stores`

| Field | Type | Notes |
|-------|------|-------|
| `store_ids` | multiselect | Store views whose overrides are NEVER deleted (e.g. Japan store). Respected by both detect and fix. |

### 6. Auto Fix — `attribute_override/auto_fix`

| Field | Type | Notes |
|-------|------|-------|
| `enabled` | Yes/No | When detect CLI finds violations, also delete them in same run |
| `log_only_when_disabled` | Yes/No | When auto_fix=No, still write violations to log table |

### 7. Notification — `attribute_override/notification`

| Field | Type | Notes |
|-------|------|-------|
| `email_enabled` | Yes/No | Send email report after detect run |
| `email_to` | text | Comma-separated recipient addresses |
| `email_from` | text | Sender address (default: store email) |
| `slack_enabled` | Yes/No | _(placeholder — not implemented in Phase 1)_ |
| `slack_webhook` | text | _(placeholder)_ |

---

## Database Schema (db_schema.xml additions)

### `carpetavenue_attribute_override_value`
Optional: stores per-store-view intended values (used by SnapshotService/ImportCommand, not main fix flow).

| Column | Type | Notes |
|--------|------|-------|
| `id` | int PK AI | |
| `entity_id` | int | product entity_id |
| `store_id` | smallint | store view ID |
| `attribute_type` | varchar(20) | `price` / `image_role` / `image_position` / `custom` |
| `attribute_code` | varchar(255) | EAV attribute code; for image_position: gallery file path |
| `value` | text | serialized intended value |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Unique key: `(entity_id, store_id, attribute_type, attribute_code)`

### `carpetavenue_override_event_log`
Detect CLI run log — violations found.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int PK AI | |
| `entity_id` | int | |
| `store_id` | smallint | |
| `attribute_type` | varchar(20) | |
| `attribute_code` | varchar(255) | |
| `old_value` | text | the store-view override value that exists (to be deleted) |
| `new_value` | text | null = after fix, Magento uses global value |
| `auto_fixed` | tinyint | 1 = deleted in same detect run |
| `run_id` | varchar(40) | UUID grouping all rows from one CLI run |
| `dry_run` | tinyint | 1 = detect ran with --dry-run (no writes) |
| `created_at` | timestamp | |

### `carpetavenue_override_backup_log`
Track manual/auto backup CSV files.

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
| `created_at` | timestamp | |
| `status` | varchar(20) | `pending` / `done` / `failed` |

---

## Source Models

### `Model/Config/Source/WebsiteScopePriceAttributes.php`
Query `eav_attribute` JOIN `catalog_eav_attribute`: `is_global = 1` (website scope in Magento = value 1), `frontend_input` in `(price)` or `backend_type = decimal` AND `entity_type = catalog_product`.

### `Model/Config/Source/WebsiteScopeCustomAttributes.php`
Same but exclude price/decimal types used for price and exclude media_image / image types. Returns all remaining website-scope product attributes.

> Config paths will use `showInWebsite="1"` so store-view selection works correctly in Stores > Config scope switcher.

---

## CLI Commands

**No background PHP observer.** Detection and deletion are CLI-driven, triggered by Jenkins 2×/day (or on demand).

---

### `carpetavenue:attribute-override:detect`

Scan DB for violations: find all rows with `store_id > 0` for configured attributes (excluding protected stores).

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | false | Print violations to stdout. No DB writes, no email sent. |
| `--type` | all | Limit to `price`, `image`, or `custom`. |
| `--store` | all non-protected | Store view ID(s) to scan. |

**Logic:**
1. Generate `run_id` (UUID).
2. For each enabled detect group (price / image / custom):
   - Query all `store_id > 0` rows from respective DB table.
   - Exclude protected store IDs.
   - Every matching row is a violation.
3. If `--dry-run`: print violation table to stdout (columns: entity_id, sku, store_id, type, attribute, current_value, after_fix), exit (no DB writes).
4. Write violations to `carpetavenue_override_event_log` with `run_id`, `dry_run=0`.
   - `old_value` = current store-view override value (the row that will be deleted)
   - `new_value` = null (Magento will use global after deletion)
5. If `auto_fix` enabled: run fix (backup CSV → DELETE rows → clear cache). Set `auto_fixed=1`.
6. If `notification/email_enabled`: send email report with:
   - Summary: total violations found, total deleted, total pending.
   - Attached or inline table: `entity_id`, `sku`, `store_id`, `attribute_code`, `store_view_value`, `fixed`.

**Jenkins cron example:**
```
0 6,18 * * * /var/www/magento/bin/magento carpetavenue:attribute-override:detect
```

---

### `carpetavenue:attribute-override:fix`

Delete store-view overrides for a given scope. Always backs up current values to CSV first (unless `--dry-run`).

**Options:**

| Option | Required | Description |
|--------|----------|-------------|
| `--dry-run` | no | Show what would be deleted. No backup, no writes. Outputs table to stdout. |
| `--type` | yes | `price`, `image`, or `custom` |
| `--attribute` | yes (custom/price) | Attribute code. Not needed for `image` (use `--image-type`). |
| `--image-type` | for image | `roles`, `position`, or `all` |
| `--scope` | no | `all` (default), `sku`, or `id` |
| `--values` | if scope≠all | Comma-separated SKUs or entity IDs |
| `--store` | no | Store view ID. **0 or omit = all non-protected stores**. Specific ID = only that store. |
| `--no-backup` | no | Skip CSV backup step (use with caution) |

**Logic:**
1. If `--dry-run`: query affected store-view rows, print table (`entity_id`, `sku`, `store_id`, `attribute`, `current_value`, `after_fix`=`(global)`), exit.
2. Resolve product set (all / by SKU / by ID).
3. Load current store-view rows from DB (respecting store + protected store filters).
4. Backup current rows → write CSV to `var/carpetavenue/override_backup/{type}_{attribute}_{store}_{ts}.csv`.
5. Log to `carpetavenue_override_backup_log`.
6. `DELETE` the store-view rows via direct DB write (bypass Magento product save to avoid triggering cascades).
7. Clear EAV cache for affected entity IDs.
8. Print summary: processed / deleted / errors.

---

## Manual Fix — Admin Page

**Route:** `utilities/attributeoverride/index`
**Menu:** Under existing CarpetAvenue Utilities menu

### UI Flow

```
[Select Type]        [Price ▼] [Image ▼] [Custom Attribute ▼]
[Select Attribute]   (dynamic — depends on type selection)
[Apply To]           (•) All Products  ( ) Specific SKUs  ( ) Specific IDs
[SKUs/IDs field]     (textarea, visible when specific selected)
[Store View]         (select — specific store required for admin page)
[x] Dry Run          (checkbox — default: ON for safety)

[ Run Dry Run / Preview ]   → AJAX (when dry run checked):
                               Returns table: entity_id | sku | store_id | attribute | current_value | after_fix=(global)
                               No writes. No backup.

[ Run Fix ]          → POST (dry run unchecked):
                       1. Query store-view rows from DB tables
                       2. Write backup CSV to var/carpetavenue/override_backup/{type}_{attr}_{ts}.csv
                       3. Log to carpetavenue_override_backup_log
                       4. DELETE store-view rows
                       5. Clear product cache for affected products
                       6. Return: deleted count, error count, CSV download link

UI enforces: Run Fix button only enabled after dry run has been executed at least once
for current selection (prevent accidental writes).
```

### Backup CSV Format

**Price backup** (`catalog_product_entity_decimal`):
```
entity_id,sku,store_id,attribute_code,value
```

**Image backup** (`catalog_product_entity_media_gallery_value`):
```
entity_id,sku,store_id,file,label,position,disabled
```

**Custom attribute backup** (`catalog_product_entity_{type}`):
```
entity_id,sku,store_id,attribute_code,value
```

---

## File Structure (new files)

```
app/code/CarpetAvenue/Utilities/
├── etc/
│   ├── config.xml                          (add defaults for new groups)
│   └── adminhtml/system.xml                (add attribute_override groups)
│   NOTE: no events.xml needed — no PHP observer
│
├── Model/
│   ├── Config/Source/
│   │   ├── WebsiteScopePriceAttributes.php
│   │   └── WebsiteScopeCustomAttributes.php
│   ├── ResourceModel/
│   │   ├── AttributeOverrideValue.php
│   │   ├── AttributeOverrideValue/Collection.php
│   │   ├── OverrideEventLog.php
│   │   └── OverrideBackupLog.php
│   ├── AttributeOverrideValue.php          (model — used by SnapshotService/ImportCommand)
│   ├── OverrideEventLog.php
│   ├── OverrideBackupLog.php
│   └── AttributeOverrideValueRepository.php
│
├── Service/
│   └── AttributeOverride/
│       ├── DetectService.php               (find all store_id>0 rows for configured attributes)
│       ├── PriceOverrideService.php        (loadStoreViewRows + deleteStoreViewRows)
│       ├── ImageOverrideService.php        (loadStoreViewRoles/Positions + deleteStoreViewRoles/Positions)
│       ├── CustomAttributeOverrideService.php  (loadStoreViewRows + deleteStoreViewRows)
│       ├── BackupService.php               (CSV export before delete — preloaded row variants)
│       ├── FixService.php                  (orchestrator: load → backup → DELETE → cache clear)
│       ├── SnapshotService.php             (optional: snapshot current values into override_value table)
│       └── NotificationService.php         (email report after detect run)
│
├── Console/Command/AttributeOverride/
│   ├── DetectCommand.php                   (bin/magento carpetavenue:attribute-override:detect)
│   ├── FixCommand.php                      (bin/magento carpetavenue:attribute-override:fix)
│   ├── ImportCommand.php                   (import intended values into override_value table)
│   └── SnapshotCommand.php                 (snapshot current values into override_value table)
│
├── Controller/Adminhtml/
│   └── AttributeOverride/
│       ├── Index.php
│       ├── DryRun.php                      (AJAX: dry run preview table — requires specific store_id)
│       └── RunFix.php                      (POST: backup + delete — requires specific store_id)
│
├── Block/Adminhtml/
│   └── AttributeOverride/
│       └── ManualFix.php
│
└── view/adminhtml/
    ├── layout/
    │   └── utilities_attributeoverride_index.xml
    └── templates/
        └── attributeoverride/manual_fix.phtml
```

---

## Config Defaults (config.xml additions)

```xml
<attribute_override>
    <price>
        <enabled>0</enabled>
        <attributes></attributes>
    </price>
    <image>
        <enabled>0</enabled>
        <override_roles>0</override_roles>
        <override_position>0</override_position>
    </image>
    <custom>
        <enabled>0</enabled>
        <attributes></attributes>
    </custom>
    <detect>
        <price_enabled>0</price_enabled>
        <price_attributes></price_attributes>
        <image_enabled>0</image_enabled>
        <image_roles>0</image_roles>
        <image_position>0</image_position>
        <custom_enabled>0</custom_enabled>
        <custom_attributes></custom_attributes>
    </detect>
    <protected_stores>
        <store_ids></store_ids>
    </protected_stores>
    <auto_fix>
        <enabled>0</enabled>
        <log_only_when_disabled>1</log_only_when_disabled>
    </auto_fix>
    <notification>
        <email_enabled>0</email_enabled>
        <email_to></email_to>
        <email_from></email_from>
        <slack_enabled>0</slack_enabled>
        <slack_webhook></slack_webhook>
    </notification>
</attribute_override>
```

---

## Helper/Config.php additions

New getter methods:
- `isAttributeOverridePriceEnabled(): bool`
- `getAttributeOverridePriceAttributes(): array`
- `isAttributeOverrideImageEnabled(): bool`
- `isAttributeOverrideImageRoles(): bool`
- `isAttributeOverrideImagePosition(): bool`
- `isAttributeOverrideCustomEnabled(): bool`
- `getAttributeOverrideCustomAttributes(): array`
- `isDetectPriceEnabled(): bool`
- `getDetectPriceAttributes(): array`
- `isDetectImageEnabled(): bool`
- `isDetectCustomEnabled(): bool`
- `getDetectCustomAttributes(): array`
- `isAutoFixEnabled(): bool`
- `isEmailNotificationEnabled(): bool`
- `getNotificationEmailTo(): array`   ← explode(',', value)
- `getNotificationEmailFrom(): string`
- `getProtectedStoreIds(): array`     ← explode(',', value) — stores excluded from deletion

---

## Implementation Phases

### Phase 1 — Config & DB Schema ✅
- Add `attribute_override` groups to `system.xml` (override, detect, protected_stores, auto_fix, notification)
- Add defaults to `config.xml`
- Add 3 new tables to `db_schema.xml`
- Add source models for attribute multiselects
- Add getter methods to `Helper/Config.php`

### Phase 2 — Detect CLI + Notification ✅
- `DetectService.php` → find all `store_id > 0` rows for configured attributes (no intended table lookup)
- `NotificationService.php` → send email report (Magento TransportBuilder)
- `DetectCommand.php` → wires service + `--dry-run` option (stdout table, no writes)
- When `auto_fix` enabled: DetectCommand calls `FixService` to delete violations

### Phase 3 — Fix CLI ✅
- `PriceOverrideService.php` — `loadStoreViewRows()` + `deleteStoreViewRows()`
- `ImageOverrideService.php` — `loadStoreViewRoles/Positions()` + `deleteStoreViewRoles/Positions()`
- `CustomAttributeOverrideService.php` — `loadStoreViewRows()` + `deleteStoreViewRows()`
- `BackupService.php` — preloaded row backup methods (no double-query)
- `FixService.php` — orchestrate: load rows → backup → DELETE → cache clear
- `FixCommand.php` — `--dry-run` prints delete preview, else runs full fix; `--store=0` = all non-protected stores

### Phase 4 — Manual Fix Admin Page
- `Controller/Adminhtml/AttributeOverride/{Index,DryRun,RunFix}.php`
- `Block/Adminhtml/AttributeOverride/ManualFix.php` + template
- Layout XML
- DryRun AJAX endpoint → calls DetectService for selected scope/store, returns HTML table (current_value → global)
- RunFix POST → calls FixService, returns summary + CSV download link
- JS: disable Run Fix button until dry run executed for current form state

### Phase 5 — Optional: Snapshot / Import
- `SnapshotService.php` / `SnapshotCommand.php` → snapshot current store-view values into `carpetavenue_attribute_override_value` (for audit/reference, not required for fix flow)
- Admin grid or CSV import to populate the optional intended-value table

---

## Key Magento Internals

| Concern | Detail |
|---------|--------|
| Attribute scope "website" | `eav_attribute.is_global = 1` in Magento 2 (0=store, 1=website, 2=global) |
| Price table | `catalog_product_entity_decimal` — columns: `value_id`, `attribute_id`, `store_id`, `entity_id`, `value` |
| Image gallery | `catalog_product_entity_media_gallery` (file paths) + `catalog_product_entity_media_gallery_value` (per-store: position, label, disabled) |
| Image roles | `catalog_product_entity_varchar` with attribute codes `image`, `small_image`, `thumbnail`, `swatch_image` |
| Custom EAV | `catalog_product_entity_{varchar,int,text,decimal,datetime}` — all have `store_id` column |
| Global fallback | DELETE row where `store_id > 0` → Magento automatically reads `store_id = 0` row |
| Protected stores | Configured in admin → `getProtectedStoreIds()` → excluded from all detect/fix operations |
| Cache clear | `TypeListInterface::invalidate()` for `catalog`, `full_page`, `block_html` after deletions |
| Avoid save loop | Not needed: direct DB DELETE bypasses Magento product save, no observer triggered |
