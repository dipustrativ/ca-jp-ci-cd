# ISSUE-007 — MSI order screens fatal on an order item with `product_id = NULL`: `null` array offset in `GetSkusByProductIdsCache` (PHP 8.5)

| Field | Value |
|---|---|
| **Status** | 🟡 WORKAROUND IN PLACE — around-plugin in `CarpetAvenue_Orderify`; core defect unpatched |
| **First observed** | 2026-09-10 (report `R-700008290`) |
| **Last re-checked** | 2026-09-10 |
| **Affects** | `magento/product-community-edition` 2.4.9, `magento/module-inventory-catalog` 1.3.4, `magento/module-inventory-sales` 1.3.4, `magento/module-inventory-sales-api` 1.2.6, `magento/module-inventory-shipping` 1.2.6, `magento/module-inventory-shipping-admin-ui` 1.2.7 |
| **Requires** | PHP 8.5+ (same root family as [ISSUE-001](ISSUE-001-scopecoderesolver-null-array-offset-php85.md), [ISSUE-002](ISSUE-002-uniqueattributevalidator-null-array-offset-php85.md), [ISSUE-005](ISSUE-005-order-item-null-product-id-deprecation.md)) |
| **Origin** | Adobe/Magento core — `InventorySales\Model\GetSkuFromOrderItem` feeds a `NULL` product id into `GetSkusByProductIds::execute([null])` and indexes the result by the same `NULL` |
| **Trigger data** | Any `sales_order_item` row with `product_id IS NULL`. On this install, 8 items across 8 orders (see §2.2) — all from `CarpetAvenue_ExportImport` order imports of SKUs absent from this catalog. |
| **Fix applied here** | `CarpetAvenue\Orderify\Plugins\InventorySales\GuardOrderItemSku` — `aroundExecute` returns `$orderItem->getSku()` before the MSI lookup when `product_id` is empty |
| **Upstream ticket** | None linked. Search `magento/magento2` for `GetSkuFromOrderItem` + `null array offset` before each upgrade. |

**This is the second half of [ISSUE-005](ISSUE-005-order-item-null-product-id-deprecation.md).**
That issue guarded `Order\Item::getProduct()`; the MSI SKU resolver reaches the same NULL
id by a different route and was never covered by it. Read ISSUE-005 §2.1 ("Why a NULL
`product_id` existed") first — the trigger data and the PHP 8.5 mechanics are identical
and are not repeated here.

---

## 1. Symptom

```text
R-700008290    Deprecated Functionality: Using null as an array offset is deprecated, use
  an empty string instead in
  /var/www/html/vendor/magento/module-inventory-catalog/Model/GetSkusByProductIdsCache.php on line 53
```

Per [ISSUE-001 §2.4](ISSUE-001-scopecoderesolver-null-array-offset-php85.md) core's
`ErrorHandler` (`vendor/magento/framework/App/ErrorHandler.php:61`) rethrows
`E_DEPRECATED` as `\Exception`. That class is **not** `NoSuchEntityException`, so the
`catch` core wrote for exactly this case (§2.1) never fires and the request dies.

Affected surfaces — every core caller of `GetSkuFromOrderItemInterface`, i.e. every admin
action that needs an item's *stock* SKU:

| Caller | Admin surface |
|---|---|
| `InventoryShippingAdminUi\Ui\DataProvider\SourceSelectionDataProvider` | New Shipment (source selection grid) |
| `InventoryShipping\Model\InventoryRequestFromOrderFactory`, `GetItemsToDeductFromShipment`, `GetSourceSelectionResultFromInvoice` | Ship / Invoice submit |
| `InventorySales\Model\GetItemsToCancelFromOrderItem` | Cancel order |
| `InventorySales\Plugin\SalesInventory\ProcessReturnQtyOnCreditMemoPlugin`, `Plugin\Sales\OrderManagement\DeductSourceItemQuantityOnRefundPlugin`, `Model\ReturnProcessor\GetInvoicedItemsPerSourceByPriority` | Credit memo / refund |
| `InventoryShippingAdminUi\Model\IsOrderSourceManageable` | Order view **Ship** button — reached only for source-managed product types, so it short-circuits first on this install (§3) |

The plain order-view page is *not* affected: `IsOrderSourceManageable::execute()` already
null-checks `getProduct()` at line 71 and `continue`s, so with ISSUE-005's guard in place
it never reaches the SKU lookup. That is why this surfaced only after ISSUE-005 was fixed.

---

## 2. Root cause (verified against on-disk 2.4.9 source)

### 2.1 The unguarded call

`vendor/magento/module-inventory-sales/Model/GetSkuFromOrderItem.php`

```php
// line 48-68
public function execute(OrderItemInterface $orderItem): string
{
    $itemSku = $orderItem->getSku();
    ...
    try {
        if ($this->isSourceItemManagementAllowedForProductType->execute($orderItem->getProductType())) {
            $itemSku = $this->getSkusByProductIds->execute(
                [$orderItem->getProductId()]          // <- [null]
            )[$orderItem->getProductId()];            // <- read at offset null
        }
    } catch (NoSuchEntityException $e) {
        $itemSku = $orderItem->getSku();
    }

    return $itemSku;
}
```

`vendor/magento/module-inventory-catalog/Model/GetSkusByProductIdsCache.php`

```php
// line 43-55
public function execute(array $productIds): array
{
    $skusByIds = [];
    $loadIds = [];
    foreach ($productIds as $productId) {
        $sku = $this->cache->get((int) $productId);
        if ($sku !== null) {
            $skusByIds[$productId] = $sku;
        } else {
            $loadIds[] = $productId;
            $skusByIds[$productId] = null;            // line 53 — write at offset null
        }
    }
```

Two separate null offsets: the write at `GetSkusByProductIdsCache.php:53` (the one in the
report) and the read back at `GetSkuFromOrderItem.php:61`. Both are silent `[""]`
coercions on PHP ≤ 8.4 and `E_DEPRECATED` on 8.5 — see
[ISSUE-001 §2.3](ISSUE-001-scopecoderesolver-null-array-offset-php85.md) for the `php -r`
proof.

**Core's intended outcome is already correct.** `GetSkusByProductIds` (the uncached
sibling, line 47) throws `NoSuchEntityException` when an id resolves to nothing, and
`GetSkuFromOrderItem` catches it and falls back to `$orderItem->getSku()` — the SKU
stored on the order row at purchase time. The deprecation just fires *before* that throw.
Verified: with the error handler out of the way (CLI), unguarded core returns exactly the
stored SKU, `VAN2112641` (§7). So the workaround returns the same value core would.

### 2.2 Trigger data on this install

```console
$ SELECT o.entity_id, o.increment_id, i.item_id, i.sku, i.product_type
  FROM sales_order_item i JOIN sales_order o ON o.entity_id = i.order_id
  WHERE i.product_id IS NULL;
5   CA-20211000000001  5   VAN2112641  simple
10  CA-2020078383      18  VAN229608   simple
16  CA-2021078383      28  VAN229608   simple
18  CA-2022078383      30  VAN229608   simple
19  CA-2020078382      31  ACA5116     simple
20  CA-2020078381      32  AWZ1077     simple
21  CA-2020078380      33  CDR8080     simple
22  CA-2020078379      34  VAN514472   simple
```

All `simple` — the product type for which
`IsSourceItemManagementAllowedForProductType::execute()` returns `true`, so the branch is
always taken. Origin of the NULLs: ISSUE-005 §2.1 route 2 (order import, SKU not in this
catalog, `Validator` reports a warning by design).

---

## 3. Confirmed NOT caused by local code

```console
$ grep -rn "GetSkusByProductIds\|GetSkuFromOrderItem" app/code app/design
(only the fix in §4 — nothing before it)
```

No CarpetAvenue / IXPF / Evalent module plugs the MSI SKU resolver or the id→SKU cache.
The trigger data is produced by `CarpetAvenue_ExportImport` (intentionally and legally —
ISSUE-005 §2.1, §5.4), but the crash on **reading** it is core's.

---

## 4. Fix in force — `aroundExecute` null-id short-circuit

`app/code/CarpetAvenue/Orderify/Plugins/InventorySales/GuardOrderItemSku.php`

```php
public function aroundExecute(
    GetSkuFromOrderItemInterface $subject,
    callable $proceed,
    OrderItemInterface $orderItem
): string {
    $productId = $orderItem->getProductId();

    if ($productId === null || $productId === '') {
        return (string) $orderItem->getSku();
    }

    return $proceed($orderItem);
}
```

```xml
<!-- app/code/CarpetAvenue/Orderify/etc/di.xml -->
<type name="Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface">
    <plugin name="carpetavenue_orderify_guard_order_item_sku"
            type="CarpetAvenue\Orderify\Plugins\InventorySales\GuardOrderItemSku"
            sortOrder="20"/>
</type>
```

`Magento_InventorySalesApi` and `Magento_InventoryConfigurableProduct` added to
`CarpetAvenue_Orderify`'s `module.xml` sequence.

Three decisions worth keeping:

- **Declared on the interface, not the impl** — core's own
  `InventoryConfigurableProduct` plugin does the same, and it survives a `preference`
  swap of the implementation.
- **`sortOrder="20"`** puts this *inside* that configurable plugin (no `sortOrder`, so 0),
  which for `configurable` items returns `product_options['simple_sku']` and never reaches
  the id lookup. Ordering it outermost would silently take that path away.
- **It lives in `Orderify`, not `ExportImport`** — same reasoning as ISSUE-005 §4: an item
  can lose its product with no import involved, and disabling the import module must not
  reintroduce the crash.

> **Interceptor gotcha (cost time on ISSUE-005 too):** `generated/` interceptors only
> override methods that had a plugin at generation time, and `generated/metadata/` caches
> the plugin list separately. After adding the plugin:
>
> ```bash
> docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && \
>   rm -rf generated/code/Magento/InventorySales generated/code/Magento/InventorySalesApi \
>          generated/metadata/* var/cache/* && php bin/magento cache:flush"
> docker restart ca-jp-phpfpm-1 ca-jp-phpfpm-xdebug-1   # opcache.validate_timestamps=0
> ```

---

## 5. Alternatives NOT taken

### 5.1 Composer patch on `GetSkusByProductIdsCache` + `GetSkuFromOrderItem` — the correct fix

```diff
--- a/vendor/magento/module-inventory-catalog/Model/GetSkusByProductIdsCache.php
+++ b/vendor/magento/module-inventory-catalog/Model/GetSkusByProductIdsCache.php
@@ -53 +53 @@
-                $skusByIds[$productId] = null;
+                $skusByIds[$productId ?? ''] = null;
--- a/vendor/magento/module-inventory-sales/Model/GetSkuFromOrderItem.php
+++ b/vendor/magento/module-inventory-sales/Model/GetSkuFromOrderItem.php
@@ -59,3 +59,3 @@
-            )[$orderItem->getProductId()];
+            )[$orderItem->getProductId() ?? ''];
```

Blocked the same way as ISSUE-001 §5.1 / ISSUE-002 §5.1 / ISSUE-005 §5.1:
`cweagans/composer-patches` is **absent** from `composer.json` (verified 2026-09-10), so a
hand edit to `vendor/` dies on the next `composer install`. Note it takes *two* hunks —
patching only the reported line 53 leaves the read at `GetSkuFromOrderItem.php:61`.

### 5.2 Plugin on `GetSkusByProductIdsCache::execute()` to filter out null ids — rejected

Fixes only the write at line 53. The caller then reads the returned array at offset
`null` (line 61) and raises the identical deprecation, so the page still dies — one layer
further in. Also a hot indexer/storefront path.

### 5.3 Guard inside `CarpetAvenue_ExportImport` instead — rejected

The importer is not in the call stack; the crash happens on any later read of an
already-imported order. Same layering argument as §4 and ISSUE-005 §5.4: read-side
robustness is the right place.

### 5.4 Backfill `product_id` / muting `E_DEPRECATED` — rejected

ISSUE-005 §5.3 and §5.5 — the products genuinely do not exist, and muting hides the rest
of the PHP 8.5 migration.

---

## 6. Re-check procedure after a Magento or PHP upgrade

```bash
# 1. is the write at line 53 still unguarded?
docker exec ca-jp-phpfpm-1 sh -c \
  "grep -n 'skusByIds\[\\\$productId\]' /var/www/html/vendor/magento/module-inventory-catalog/Model/GetSkusByProductIdsCache.php"
#    STILL BROKEN if it reads  $skusByIds[$productId] = null;
#    FIXED      if it reads    ...[$productId ?? '']...  (or a null guard precedes it)

# 2. is the read-back still unguarded?
docker exec ca-jp-phpfpm-1 sh -c \
  "sed -n '48,68p' /var/www/html/vendor/magento/module-inventory-sales/Model/GetSkuFromOrderItem.php"
#    FIXED if getProductId() is null-checked before execute([...]) / the [$productId] read

# 3. versions — record in the header table
docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && php -r '\$l=json_decode(file_get_contents(\"composer.lock\"),true);
foreach(\$l[\"packages\"] as \$p){ if(in_array(\$p[\"name\"],[\"magento/product-community-edition\",\"magento/module-inventory-catalog\",\"magento/module-inventory-sales\",\"magento/module-inventory-sales-api\"])) echo \$p[\"name\"],\" \",\$p[\"version\"],PHP_EOL; }'"

# 4. is trigger data still present?
docker exec ca-jp-db-1 mariadb -umagento -pmagento magento -N -B -e "
  SELECT o.entity_id, o.increment_id, i.item_id, i.sku, i.product_type
  FROM sales_order_item i JOIN sales_order o ON o.entity_id = i.order_id
  WHERE i.product_id IS NULL;"

# 5. functional check — a bootstrap script (app/bootstrap.php, area adminhtml) that:
#    a) calls $om->get(GetSkuFromOrderItemInterface::class)->execute($item) on an item
#       from step 4  -> must return the stored sku with no deprecation (guard works);
#    b) calls the same on core's class built with `new` (bypasses the interceptor, so no
#       plugin): new \Magento\InventorySales\Model\GetSkuFromOrderItem(
#           $om->get(GetSkusByProductIdsCache::class),
#           $om->get(IsSourceItemManagementAllowedForProductTypeInterface::class))
#       -> must still print the line-53 deprecation (workaround still load-bearing).
#    then load New Shipment for an order from step 4:
#    /admin/admin/order_shipment/new/order_id/<entity_id>/
```

If steps 1 **and** 2 show guards landed: flip **Status** to 🟢 FIXED, record the version,
and delete the plugin + its `di.xml` entry in the same commit. Step 1 alone is not enough
(§5.2).

---

## 7. Verification log

| Date | Magento | PHP | Result |
|---|---|---|---|
| 2026-09-10 | 2.4.9 / module-inventory-catalog 1.3.4 / module-inventory-sales 1.3.4 | 8.5.6 | 🟡 Reproduced with core's `GetSkuFromOrderItem` instantiated by hand (no interceptor → no plugin) against order `CA-20211000000001` item 5: `Deprecated: Using null as an array offset ... GetSkusByProductIdsCache.php on line 53`, and core's own fallback still returned the stored sku `VAN2112641` — confirming the guard reproduces core's intended value. Through the DI-resolved `GetSkuFromOrderItemInterface`, all 8 NULL-`product_id` items (§2.2) returned their stored SKU with **no** deprecation; `IsOrderSourceManageable::execute()` returned `false` for all 8 orders. Local code cleared (§3). `phpcs --standard=Magento2` clean on the new plugin. Core unpatched. |

Not yet exercised: the admin **New Shipment** / credit-memo screens end-to-end in a
browser. The service that every one of them calls is covered above, and none of them can
reach the id lookup once the guard returns first.
