# ISSUE-006 — Admin order item **Price** column is multiplied by the currency rate when base ≠ display currency

| Field | Value |
|---|---|
| **Status** | 🟡 WORKAROUND IN PLACE — admin tax display set to *Including Tax*; core defect unpatched |
| **First observed** | 2026-09-10 |
| **Last re-checked** | 2026-09-10 |
| **Affects** | `magento/product-community-edition` 2.4.9, `magento/module-tax` 100.4.9, `magento/module-weee` 100.4.9 |
| **Requires** | A store whose **base currency differs from its display currency**. This install: base `EUR`, display `JPY`, rate ~170. |
| **Origin** | Adobe/Magento core — `Tax\Block\Item\Price\Renderer::getItemDisplayPriceExclTax()` runs a base→display currency conversion on `sales_order_item.price`, which is **already** stored in the order currency |
| **Trigger data** | Every order in the store, regardless of how it was created (checkout or import). Verified on natively-placed orders `CA-20200000000001` and `CA-20200000000002`. |
| **Fix applied here** | Config, not code: `tax/sales_display/price = 2` and `tax/sales_display/subtotal = 2` (*Including Tax*), seeded by the data patch `CarpetAvenue\Japan\Setup\Patch\Data\ConfigureJapanTaxDisplay`. The incl-tax accessor does not convert, so the column renders the stored value. |
| **Upstream ticket** | None linked. Search `magento/magento2` for `getItemDisplayPriceExclTax` + `convertAndRound` before each upgrade. |

---

## 1. Symptom

Admin → **Sales → Orders → view an order**. With **Orders, Invoices, Credit Memos
Display Settings → Display Price** set to *Excluding Tax* (Magento's default), the
per-line **Price** column shows the unit price multiplied by the store's currency
rate, then rounded up by this store's JPY ceiling rule:

| Order | Origin | `sales_order_item.price` | Price column shows |
|---|---|---|---|
| `CA-20200000000001` | checkout | 21,000 | **¥3,570,000** |
| `CA-20200000000002` | checkout | 44,000 | **¥7,480,000** |
| `CA-20200000000002` | checkout | 18,100 | **¥3,077,000** |
| `CA-2021078383` | CSV import | 231,818.18 | **¥39,410,000** |

`21000 × 170 = 3,570,000`. For the last row,
`231818.18 × 170 = 39,409,090.6`, which
`CarpetAvenue\Productify\Plugin\Currency\JPCurrencyConvert::afterConvert()` then
ceilings to the configured JPY threshold → `39,410,000`.

**Original Price**, **Subtotal**, **Tax Amount**, **Discount Amount** and **Row Total**
are all correct. Only the unit **Price** column is wrong.

---

## 2. Root cause (verified against on-disk 2.4.9 source)

Three sibling accessors feed the same admin item row. Only one of them converts:

```php
// vendor/magento/module-tax/Block/Item/Price/Renderer.php:209-212
public function getItemDisplayPriceExclTax()
{
    return $this->priceCurrency->convertAndRound($this->getItem()->getPrice());
}
```

```php
// vendor/magento/module-weee/Block/Item/Price/Renderer.php:99-114
public function getUnitDisplayPriceInclTax()
{
    $priceInclTax = $this->getItem()->getPriceInclTax();   // no conversion
    ...
}
```

```php
// vendor/magento/module-weee/Block/Item/Price/Renderer.php:270-292
public function getRowDisplayPriceExclTax()
{
    ...
    $rowTotalExclTax = (float)$item->getRowTotal();        // no conversion
    ...
}
```

`Magento\Framework\Pricing\PriceCurrencyInterface::convertAndRound()` converts **from
the store's base currency into the current display currency**, using the *store's live
rate*. But `sales_order_item.price` is persisted in the **order currency** already —
`base_price` is the base-currency copy. Converting `price` therefore applies the rate a
second time.

Note it does not consult the order's own `sales_order.base_to_order_rate` at all, so the
order's historical rate is irrelevant to the bug. `Magento\Weee\Block\Adminhtml\Items\Price\Renderer`
is the block actually bound to the `column_price` slot (`module-weee`'s
`sales_order_item_price.xml` wins over `module-tax`'s and `module-sales`'s), and it
delegates excl-tax to the Tax renderer above.

### 2.1 Proof local code is not at fault

- Reproduced on orders created through **normal checkout**, never touched by
  `CarpetAvenue_ExportImport` (`CA-20200000000001`, `CA-20200000000002`).
- The stored data is correct and self-consistent: `price 231818.18` /
  `price_incl_tax 255000` / `tax_amount 23181.82`, and `231818.18 + 23181.82 = 255000`.
- The wrong figure exists only in the rendered output — no row in `sales_order_item`
  holds a value above 1,000,000:
  ```sql
  select count(*) from sales_order_item where price > 1000000 or original_price > 1000000;
  -- 0
  ```
- `JPCurrencyConvert` only rounds a value already produced by
  `Currency::convert()`; with `convertAndRound()` removed from the path the ceiling has
  nothing to act on. It amplifies the defect, it does not cause it.

---

## 3. Workaround in force

`tax/sales_display/price` and `tax/sales_display/subtotal` set to `2`
(*Including Tax* — `Magento\Tax\Model\Config::DISPLAY_TYPE_INCLUDING_TAX`).

Seeded at the default scope by the data patch
`CarpetAvenue\Japan\Setup\Patch\Data\ConfigureJapanTaxDisplay`, so it travels with
the code to every environment — it is **not** a manual `config:set` any more. That
patch seeds the whole JP tax-display set (`tax/display/*`, `tax/cart_display/*`,
`tax/sales_display/*`, `tax/defaults/country`); its class docblock points back at
this file. Applied to a pre-existing install with:

```bash
php bin/magento setup:upgrade --keep-generated
php bin/magento cache:flush
```

Admin path: **Stores → Configuration → Sales → Tax → Orders, Invoices, Credit Memos
Display Settings**. The patch writes the default scope only, so a website/store-view
override still wins — an override back to *Excluding Tax* re-exposes this defect.

This is the right setting for this store on its own merits — it is a tax-inclusive JP
store, and the sibling install (`upgrade-m246`) already displays incl-tax — so the
workaround costs nothing. It also sidesteps the defect completely, because the incl-tax
accessor returns the stored value untouched.

**Caveat:** an order whose `price_incl_tax` is NULL renders blank/zero under this
setting. Orders imported by a pre-2026-09-10 version of `CarpetAvenue_ExportImport`
have NULL there, because that version did not carry the `*_incl_tax` columns; on this
install `CA-2020078383` is affected. Re-importing such an order with the current module
version populates them.

### 3.1 Alternatives rejected

| Alternative | Why rejected |
|---|---|
| Plugin `getItemDisplayPriceExclTax()` to return `$item->getPrice()` unconverted | Correct fix, but the block is resolved through `Magento_Weee`'s override and used by the order, invoice and credit-memo screens plus the frontend `zone` variants. A store-wide price-display override for a column we do not need to show is more risk than a config flag. Revisit if *Excluding Tax* is ever required. |
| Set the store's base currency to `JPY` | Changes accounting for every existing order and every base amount in the database. Far beyond the scope of a display bug. |
| Disable `JPCurrencyConvert` | Removes the rounding, not the ×170. The Price column would read `¥39,409,091` instead of `¥39,410,000`. |

---

## 4. Re-check procedure (run after every Magento / PHP upgrade)

1. Confirm the code still converts:
   ```bash
   docker exec ca-jp-phpfpm-1 sh -c "grep -n 'convertAndRound' \
     /var/www/html/vendor/magento/module-tax/Block/Item/Price/Renderer.php"
   ```
   Expect a hit inside `getItemDisplayPriceExclTax()`. **No hit → the defect may be
   fixed upstream; continue to step 3.**

2. Confirm which block owns the column (the excl-tax delegation chain):
   ```bash
   docker exec ca-jp-phpfpm-1 sh -c "grep -rn 'column_price' \
     /var/www/html/vendor/magento/module-*/view/adminhtml/layout/sales_order_item_price.xml"
   ```

3. Measure it directly. Renders both accessors for a known order without needing an
   admin session:
   ```bash
   cat > /tmp/issue006.php <<'PHP'
   <?php
   use Magento\Framework\App\Bootstrap;
   require '/var/www/html/app/bootstrap.php';
   $om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
   $om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
   $repo = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
   $scb  = $om->create(\Magento\Framework\Api\SearchCriteriaBuilder::class);
   $r    = $om->create(\Magento\Weee\Block\Item\Price\Renderer::class);
   $list = $repo->getList($scb->addFilter('increment_id', 'CA-20200000000001')->create())->getItems();
   $o = reset($list);
   foreach ($o->getAllItems() as $it) {
       $r->setItem($it);
       printf("price(db)=%s exclTax=%s inclTax=%s\n",
           $it->getPrice(), $r->getUnitDisplayPriceExclTax(), $r->getUnitDisplayPriceInclTax());
   }
   PHP
   docker cp /tmp/issue006.php ca-jp-phpfpm-1:/tmp/issue006.php
   docker exec ca-jp-phpfpm-1 php /tmp/issue006.php
   docker exec ca-jp-phpfpm-1 rm -f /tmp/issue006.php
   ```
   **Fixed** when `exclTax` equals `price(db)`. **Still broken** when
   `exclTax = price(db) × rate` (≈170×).

4. If fixed: revert the workaround only if *Excluding Tax* is actually wanted —
   `config:set tax/sales_display/price 1` — and update this file's status. Otherwise
   leave the config as is and record the re-check date above.
