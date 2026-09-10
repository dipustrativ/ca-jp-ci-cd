# ISSUE-005 — Admin order view redirects to the grid when an order item has `product_id = NULL` (PHP 8.5)

| Field | Value |
|---|---|
| **Status** | 🟡 WORKAROUND IN PLACE — around-plugin in `CarpetAvenue_Orderify`; core defect unpatched |
| **First observed** | 2026-09-10 |
| **Last re-checked** | 2026-09-10 |
| **Affects** | `magento/product-community-edition` 2.4.9, `magento/module-sales` 103.0.9, `magento/module-catalog` 104.0.9, `magento/module-inventory-shipping-admin-ui` 1.2.7 |
| **Requires** | PHP 8.5+ (same root family as [ISSUE-001](ISSUE-001-scopecoderesolver-null-array-offset-php85.md) and [ISSUE-002](ISSUE-002-uniqueattributevalidator-null-array-offset-php85.md)) |
| **Origin** | Adobe/Magento core — `Order\Item::getProduct()` passes a `NULL` id straight into `ProductRepository::getById()` |
| **Trigger data** | Any `sales_order_item` row with `product_id IS NULL` — product deleted after purchase, or an order imported with a SKU absent from this catalog. On this install: order `CA-20211000000001` (`entity_id` 5), item 5, sku `VAN2112641`. |
| **Fix applied here** | `CarpetAvenue\Orderify\Plugins\Sales\Order\GuardItemProduct` — `aroundGetProduct` returns `null` before the repository call when `product_id` is empty. |
| **Upstream ticket** | None linked. Search `magento/magento2` for `getProduct` + `getById(null)` / `null array offset` before each upgrade. |

---

## 1. Symptom

Opening **Sales → Orders → CA-20211000000001** in the admin bounces straight back to the
order grid. No admin message explains it. `var/log/exception.log`:

```text
main.CRITICAL: Exception: Deprecated Functionality: Using null as an array offset is
deprecated, use an empty string instead in
/var/www/html/vendor/magento/module-catalog/Model/ProductRepository.php on line 311
```

Repro: `https://<base_url>/admin/sales/order/view/order_id/5/`.

The failing chain, top frames verbatim from the log:

```text
#0 vendor/magento/module-catalog/Model/ProductRepository.php(311): ErrorHandler->handler(8192, 'Using null as a...')
#1 vendor/magento/module-sales/Model/Order/Item.php(686):                Magento\Catalog\Model\ProductRepository->getById(NULL)
#2 vendor/magento/module-inventory-shipping-admin-ui/Model/IsOrderSourceManageable.php(71): Order\Item->getProduct()
#3 vendor/magento/module-inventory-shipping-admin-ui/Block/Adminhtml/Order/View/ShipButton.php(69): IsOrderSourceManageable->execute()
#4 vendor/magento/framework/View/Element/AbstractBlock.php(288):          ShipButton->_prepareLayout()
...
#15 vendor/magento/module-sales/Controller/Adminhtml/Order.php(151):      Backend\Model\View\Result\Page->setActiveMenu('Magento_Sales::...')
#16 vendor/magento/module-sales/Controller/Adminhtml/Order/View.php(30):  Order->_initAction()
```

It throws inside **layout generation**, before any block renders — hence a redirect
rather than a half-rendered page with an error block.

Per [ISSUE-001 §2.4](ISSUE-001-scopecoderesolver-null-array-offset-php85.md) the core
`ErrorHandler` converts `E_DEPRECATED` into a thrown `\Exception`. Production mode does
not set `error_reporting` either, so this is **not** a developer-mode-only trap in
principle — developer mode is simply where it was found.

---

## 2. Root cause (verified against on-disk 2.4.9 source)

`vendor/magento/module-sales/Model/Order/Item.php` — `getProduct()` guards a *missing*
product but not a *null id*:

```php
// line 682-693
public function getProduct()
{
    if (!$this->hasData('product')) {
        try {
            $product = $this->productRepository->getById($this->getProductId());
        } catch (\Magento\Framework\Exception\NoSuchEntityException $noEntityException) {
            $product = null;
        }
        $this->setProduct($product);
    }
    return $this->getData('product');
}
```

`vendor/magento/module-catalog/Model/ProductRepository.php` — `getById()` uses the id as
an array key before it ever loads anything:

```php
// line 308-311
public function getById($productId, $editMode = false, $storeId = null, $forceReload = false)
{
    $cacheKey = $this->getCacheKey([$editMode, $storeId === null ? $storeId : (int) $storeId]);
    if (!isset($this->instancesById[$productId][$cacheKey]) || $forceReload) {
```

`$this->instancesById[null]` has always silently coerced to `[""]`; PHP 8.5 emits
`E_DEPRECATED` for it (see ISSUE-001 §2.3 for the `php -r` proof and why 8.3/8.4 were
fine). The deprecation fires *before* the `NoSuchEntityException` that `getProduct()` is
prepared to catch, so core's own null-handling never gets a chance to run.

**Core already treats a missing product as legal.** `getProduct()` is documented
`@return \Magento\Catalog\Model\Product|null`, and callers null-check — e.g.
`IsOrderSourceManageable::execute()` at line 71:

```php
$productType = $orderItem->getProduct() ? $orderItem->getProduct()->getTypeId() : '';
```

So the defect is narrow: core is missing a null-id short-circuit, not missing
null-product support.

### Why a NULL `product_id` existed

`sales_order_item.product_id` is a nullable column, and an order item legitimately
outlives its product. Two producers on this install:

1. **Product deleted after purchase** — core nulls nothing, but a reused/rebuilt catalog
   leaves the id dangling; `getById()` then throws `NoSuchEntityException`, which is the
   *handled* path. Not this order.
2. **Order import** — `CarpetAvenue_ExportImport`'s
   `Model/Order/Importer.php::resolveProductId()` deliberately writes `null` when
   `item_sku` is not in the catalog. `Model/Order/Validator.php` reports it as a
   **warning**, not an error: *"SKU "%1" not found in catalog — item will be imported
   without a product link."* Importing historic orders whose products were never in this
   catalog is a supported use case, so the null stays.

Order `CA-20211000000001` came from route 2. `VAN2112641` is not in
`catalog_product_entity` at all.

---

## 3. Confirmed NOT caused by local code

```console
$ grep -rn "instancesById\|aroundGetById\|Order\\\\Item::getProduct" app/code app/design
(no matches — before the fix in §4)
```

No CarpetAvenue / IXPF / Evalent module plugs `ProductRepository::getById()` or
`Order\Item::getProduct()`. The trigger is a nullable core column + unguarded core code.

Local code *does* produce the trigger data (§2, route 2) — but that behaviour is
intentional and legal; the crash on **reading** it is core's.

---

## 4. Fix in force — `aroundGetProduct` null-id short-circuit

`app/code/CarpetAvenue/Orderify/Plugins/Sales/Order/GuardItemProduct.php`

```php
public function aroundGetProduct(Item $subject, callable $proceed)
{
    $productId = $subject->getProductId();

    if ($productId === null || $productId === '') {
        return null;
    }

    return $proceed();
}
```

```xml
<!-- app/code/CarpetAvenue/Orderify/etc/di.xml -->
<type name="Magento\Sales\Model\Order\Item">
    <plugin name="carpetavenue_orderify_guard_item_product"
            type="CarpetAvenue\Orderify\Plugins\Sales\Order\GuardItemProduct"
            sortOrder="10"/>
</type>
```

Returns exactly what core would have returned had the deprecation not intervened, so
every existing null-check downstream keeps working. Fixes the whole surface at once
(order view, invoice, shipment, credit-memo, PDF renderers) rather than the one block
that happened to fault first.

It lives in `Orderify` — the order-management module — not `ExportImport`: an item can
lose its product without any import, and disabling the import module must not
reintroduce the crash.

> **Gotcha that cost time here:** `generated/code/.../Order/Item/Interceptor.php` only
> overrides methods that had a plugin **at generation time**. `getProduct()` had none, so
> after adding the plugin the stale interceptor silently ignored it — `dev:di:info` listed
> the plugin while the deprecation kept firing. `generated/metadata/` caches the plugin
> list separately. Both must go:
>
> ```bash
> docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && \
>   rm -rf generated/code/Magento/Sales/Model/Order/Item generated/metadata/* var/cache/* && \
>   php bin/magento cache:flush"
> docker restart ca-jp-phpfpm-1 ca-jp-phpfpm-xdebug-1   # opcache.validate_timestamps=0
> ```

---

## 5. Alternatives NOT taken

### 5.1 Composer patch on `ProductRepository::getById()` — the correct fix

```diff
--- a/vendor/magento/module-catalog/Model/ProductRepository.php
+++ b/vendor/magento/module-catalog/Model/ProductRepository.php
@@ -308,7 +308,7 @@
     public function getById($productId, $editMode = false, $storeId = null, $forceReload = false)
     {
         $cacheKey = $this->getCacheKey([$editMode, $storeId === null ? $storeId : (int) $storeId]);
-        if (!isset($this->instancesById[$productId][$cacheKey]) || $forceReload) {
+        if (!isset($this->instancesById[$productId ?? ''][$cacheKey]) || $forceReload) {
```

Blocked the same way as ISSUE-001 §5.1 / ISSUE-002 §5.1: `cweagans/composer-patches` is
**absent** from `composer.json` (verified 2026-09-10), so a hand edit to `vendor/` dies on
the next `composer install`. This patch is also the wider fix — it would cover *every*
`getById(null)` caller, not just order items.

### 5.2 Plugin on `ProductRepository::getById()` instead — rejected

A `beforeGetById` returning early on a null id would also work and cover all callers, but
`getById()` is a hot per-request path on the storefront and the interface contract has no
"null id" case to return — it would have to throw `NoSuchEntityException`, changing
behaviour for callers that currently get a deprecation-turned-exception. Guarding at the
order item keeps the blast radius to admin order screens.

### 5.3 Backfill `product_id` / create the missing SKU — rejected

The product genuinely does not exist. Pointing the item at some other product falsifies
order history, and creating `VAN2112641` to satisfy a page render pollutes the catalog.
Also does not scale: the next import of a historic order reintroduces the crash.

### 5.4 Making the importer reject unknown SKUs — rejected

Would turn `Validator`'s warning into a blocking error, defeating the module's stated
purpose (importing historic orders whose products predate this catalog). Read-side
robustness is the right layer.

### 5.5 Muting `E_DEPRECATED` — rejected

Same reasoning as ISSUE-001 §5.3: hides every other PHP 8.5 deprecation during the 8.5
migration.

---

## 6. Re-check procedure after a Magento or PHP upgrade

```bash
# 1. is the array offset still unguarded in core?
docker exec ca-jp-phpfpm-1 sh -c \
  "grep -n 'instancesById\[\\\$productId\]' /var/www/html/vendor/magento/module-catalog/Model/ProductRepository.php"
#    STILL BROKEN if it reads  $this->instancesById[$productId][$cacheKey]
#    FIXED      if it reads    ...[$productId ?? '']...  (or a null guard precedes it)

# 2. does Order\Item::getProduct() now guard the id itself?
docker exec ca-jp-phpfpm-1 sh -c \
  "sed -n '680,695p' /var/www/html/vendor/magento/module-sales/Model/Order/Item.php"
#    FIXED if getProductId() is null-checked before the getById() call

# 3. versions — record in the header table
docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && php -r '\$l=json_decode(file_get_contents(\"composer.lock\"),true);
foreach(\$l[\"packages\"] as \$p){ if(in_array(\$p[\"name\"],[\"magento/product-community-edition\",\"magento/module-sales\",\"magento/module-catalog\",\"magento/module-inventory-shipping-admin-ui\"])) echo \$p[\"name\"],\" \",\$p[\"version\"],PHP_EOL; }'"

# 4. is trigger data still present?
docker exec ca-jp-db-1 mariadb -umagento -pmagento magento -N -B -e "
  SELECT o.increment_id, i.item_id, i.sku
  FROM sales_order_item i JOIN sales_order o ON o.entity_id = i.order_id
  WHERE i.product_id IS NULL;"

# 5. functional check — disable the plugin, load the order view, confirm it still breaks
#    (proves the workaround is still load-bearing), then re-enable:
#    /admin/sales/order/view/order_id/<entity_id from step 4>/
```

If steps 1 or 2 show a guard landed: flip **Status** to 🟢 FIXED, record the version, and
delete the plugin + its `di.xml` entry in the same commit.

---

## 7. Verification log

| Date | Magento | PHP | Result |
|---|---|---|---|
| 2026-09-10 | 2.4.9 / module-sales 103.0.9 / module-catalog 104.0.9 | 8.5.6 | 🟡 Reproduced on order `CA-20211000000001` (admin order view → redirect to grid). Root cause confirmed at `ProductRepository.php:311` via `Item.php:686`. Local code cleared. `GuardItemProduct` plugin added to `CarpetAvenue_Orderify`; stale `Order/Item/Interceptor.php` + `generated/metadata` removed. Order view then rendered in full (items, totals, comment history) with **no** new deprecation in `exception.log`. Core unpatched. |
