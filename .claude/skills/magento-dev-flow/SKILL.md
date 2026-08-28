---
name: magento-dev-flow
description: CarpetAvenue Magento build → verify → ship workflow. Use when implementing, testing, or shipping any change in this repo — especially country-conditional features (cart, checkout/OSC, order storage, emails, invoice PDF) with an admin on/off switch. Covers the exact docker/cache/di:compile commands, the bootstrap-script verification pattern, and the commit + Bitbucket PR flow. Triggers on "implement this Magento feature", "verify this in the app", "test the observer/plugin/pdf", "add an admin toggle", "commit and open a PR".
---

# CarpetAvenue Magento Dev Flow

Repeatable flow for building, verifying, and shipping a change in this project.
Follow `.claude/rules/magento2.md` for coding standards. Respond in caveman mode.

## 0. Environment facts

- App container: `magento-app-1`, workdir `/var/www/html`. Run CLI:
  `docker exec -w /var/www/html magento-app-1 php bin/magento <cmd>`
- DB container: `magento-db-1`, db `carpetavenue`, user `root`.
  `docker exec magento-db-1 mysql -uroot -pUQUEJOYAWB carpetavenue -e "<sql>"`
  (filter noise: `| grep -v "Warning: Using a password"`)
- Deploy mode is `developer` BUT DI is **compiled** (Compiled factory active).
  → **NEVER** `rm -rf generated/code/*` without recompiling — deleting an
  interceptor subtree causes `Class "...\Interceptor" not found`. See
  [[project-compiled-di-dev-mode]].
- Host has `pdftotext` (poppler); the container does not.

## 1. Implement

- Custom code under `app/code/<Vendor>/<Module>/`; theme under
  `app/design/frontend/Evalent/carpet/<Module>/`.
- Prefer plugin / observer / ViewModel over core edits. Match the file you touch.
- IXPF_Syncer REST endpoints follow their own convention — see
  [[project-ixpf-syncer-rest-api]] / `.claude/rules/ixpf_syncer_api.md`.

## 2. Apply changes (the golden commands)

Pick the minimum that matches what you changed:

| Changed | Command |
|---|---|
| New/changed **class, plugin, di.xml, constructor signature** | `bin/magento setup:di:compile` |
| **db_schema.xml** (new column/table) | `bin/magento setup:db-declaration:generate-whitelist --module-name=<Vendor_Module>` then `bin/magento setup:upgrade --keep-generated` |
| **config.xml / system.xml / events.xml / layout / di** | `bin/magento cache:clean` (config, layout, block_html, full_page) |
| **.phtml / .less / .html / .js (theme)** | clear preprocessed: `rm -rf var/view_preprocessed/* pub/static/frontend/*` (dev mode auto-recompiles; browser needs hard reload) |

After di:compile always `cache:clean`. Never `cache:flush` from code.

## 3. Verify in the real app (bootstrap-script pattern)

Do not trust "it compiles". Run it. Write a throwaway script **inside the
container** (host↔container FS sync is unreliable), run, then delete.

```bash
docker exec -w /var/www/html magento-app-1 sh -c 'cat > pub/_check.php <<PHP
<?php
use Magento\Framework\App\Bootstrap;
require __DIR__."/../app/bootstrap.php";
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode("frontend"); // or adminhtml
$om->get(\Magento\Store\Model\StoreManagerInterface::class)->setCurrentStore(3); // 3 = FR
// ... exercise the code, echo results ...
PHP
php pub/_check.php 2>&1 | grep -vi "Undefined property\|PerformanceMods"; rm -f pub/_check.php'
```

Patterns proven this repo:
- **Observer/plugin on quote→order**: there is NO `sales_convert_quote_item_to_order_item`
  event in M2 — use an **after-plugin on `Magento\Quote\Model\Quote\Item\ToOrderItem::convert`**.
  Test by calling `convert($quoteItem, ...)` directly (it dispatches nothing).
- **Order item extra column**: real `sales_order_item` column → `setData()` persists
  automatically on order save; read via `$orderItem->getData('col')`.
- **Invoice PDF** (`Magento\Sales\Model\Order\Pdf\Invoice`, preferenced to
  `Evalent\InvoicePaymentMods\Overwrite\Plugin\Invoice`): render then verify text —
  `$pdf = $om->create(Invoice::class)->getPdf([$invoice]); file_put_contents('/tmp/t.pdf', $pdf->render());`
  copy out `docker cp magento-app-1:/tmp/t.pdf /tmp/t.pdf` and
  `pdftotext -layout /tmp/t.pdf - | grep -c "<needle>"`. `strings` won't find it
  (compressed content stream). `_drawFooter()` runs per page via `newPage()`;
  `$this->currentInvoice` gives the order in that scope.
- **Currency**: store EUR is configured to display **0 decimals**, so
  `priceCurrency->format()` rounds sub-euro values to 0. For 2-decimal money use
  `NumberFormatter(locale, CURRENCY)` with MIN/MAX fraction digits = 2. In KO/JS use
  `amount.toFixed(2).replace('.', ',')`.

Always test the **negative** case too (non-FR / switch off), not just the happy path.

## 4. Country-conditional feature checklist

When a feature is France-only (or any country) with an admin master switch, gate
**every** surface — a single config path, read through one helper:

- Config: `etc/config.xml` default + `etc/adminhtml/system.xml` field under the
  module's section (CarpetAvenue tab). One `.../enabled` flag.
- One config reader (`Helper` extending `AbstractHelper`, `isEnabled()`) reused by
  PHP + templates.
- **Cart** (`Magento_Checkout/.../cart/item/default.phtml`): ViewModel injected via
  `checkout_cart_item_renderers.xml` on ALL renderer blocks that use `default.phtml`
  (default, simple, virtual, configurable, grouped).
- **OSC checkout** (Mageplaza_Osc): expose a flag in the
  `Magento\Checkout\Model\DefaultConfigProvider::getConfig` after-plugin
  (`window.checkoutConfig.<flag>`), read it in the KO summary mixin
  (`checkout-summary-mixin.js`) + template `container/summary/item/details.html`.
  Reactive shipping country: `Magento_Checkout/js/model/quote` → `quote.shippingAddress().countryId`.
- **Order storage**: after-plugin on `ToOrderItem::convert`, guarded by country + switch.
- **Emails** (order/invoice/shipment/creditmemo `email/items/.../default.phtml`):
  guard block with `$this->helper(<ConfigHelper>)->isEnabled()`.
- **Invoice PDF**: guard with the same config path via `$this->_scopeConfig`.
- Make the product attribute available in cart/quote context by adding it to
  `etc/catalog_attributes.xml` `quote_item` group.

## 5. Ship

1. `git status --short` — stage ONLY files for this change; never blanket `git add .`
   (this repo has unrelated dirty `.gitignore` / `developer/*`). List them back to
   the user and get confirmation before committing.
2. Conventional commit (`feat(module): ...`), body explaining what + why, ending:
   `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
3. Feature branch → push `git push -u origin <branch>`.
4. Remote is **Bitbucket** (no `gh`). Give the PR link with base preset:
   `https://bitbucket.org/carpet-avenue/project_magento-carpetavenue/pull-requests/new?source=<branch>&dest=develop`
   plus a ready title + description (include Post-Deploy steps: setup:upgrade,
   di:compile, cache:flush).

## Cleanup

Remove any debug `error_log`/`var/log/*.log` probes and throwaway `pub/*.php`
scripts before shipping.
