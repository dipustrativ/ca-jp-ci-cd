# ISSUE-008 — `locations.each is not a function` on every Stores → Configuration page

| | |
|---|---|
| **Affects** | `paypal/module-braintree-core` 4.8.0 (bundled with Magento 2.4.9 CE), every `adminhtml_system_config_edit` page |
| **Status** | 🟡 WORKAROUND — the seven `PayPal_Braintree*` modules disabled (2026-09-23), **not yet verified on a running instance** |
| **First seen** | 2026-09-23 (carried over from the sister install, see *Provenance*) |
| **Last re-checked** | 2026-09-23 |

## Provenance

This defect and its workaround were diagnosed and verified end-to-end on the **carpient**
install first. That install runs the same Magento 2.4.9 CE and the same
`paypal/module-braintree-core` 4.8.0, and the root cause is entirely in vendor code
identical in both trees, so the finding transfers. What is recorded below as *verified
here* was re-checked against this repo; what was verified only there is marked as such.

## What breaks

Opening **any** section of Stores → Configuration in the admin sometimes throws, in the
browser console:

```
system.min.js:12 Uncaught TypeError: locations.each is not a function
    at system.min.js:12:1024
    at Object.execCb (require.min.js:122:195)
    at Module.check (require.min.js:62:495)
    at Module.<anonymous> (require.min.js:79:35)
```

**Intermittent — a reload usually clears it.** That intermittency is the tell: it is a
load-order race, not a data problem.

Blast radius is small but real: the throw happens inside the requirejs callback, so
everything that file registers is lost for that page load — `window.applyForAll`,
`window.resetAll`, `window.applyButton`, `window.hidePaypalSections`,
`window.braintreeValidator`, and the Pay Later field handling. Nothing outside the
Braintree config section uses them, so on every other section the only damage is the red
console line. On the Braintree section itself the "Apply to all", "Reset" and "Validate
Credentials" buttons are dead until reload.

Braintree is **not used on this store** — the live payment methods are `Adyen_Payment`,
`Evalent_InvoicePaymentMods` and `Magento_OfflinePayments`. Braintree was never even
configured here (see the audit table). The script loads anyway (see below), so the error
reaches pages that have nothing to do with Braintree.

## Verified root cause

### 1. The script is loaded on every config page, unconditionally

`vendor/paypal/module-braintree-core/view/adminhtml/layout/adminhtml_system_config_edit.xml`

```xml
<page ...>
    <head>
        <css src="PayPal_Braintree::styles.css"/>
        <script src="PayPal_Braintree::js/system.js" />
    </head>
</page>
```

`adminhtml_system_config_edit` is the handle for *every* configuration section. There is
no `ifconfig`, no "is Braintree enabled" check, and a disabled payment method does not
disable the module's layout XML. Confirmed verbatim in this tree, 2026-09-23.

### 2. The script uses Prototype's `Array#each` without depending on Prototype

`vendor/paypal/module-braintree-core/view/adminhtml/web/js/system.js:1`

```js
require(['jquery', 'Magento_Ui/js/modal/alert', 'mage/translate', 'domReady!'], function ($, alert, $t) {
```

…and then, at `:236-239` (top level of that callback — this is the one in the stack trace):

```js
    let locations = ['checkout', 'productpage', 'cart'];

    window.hidePaypalSections();
    locations.each(function (loc) {
```

`Array#each` is **not** a JavaScript method. It comes from Prototype.js:

`lib/web/prototype/prototype.js:1376`

```js
  Object.extend(arrayProto, Enumerable);
```

where `Enumerable.each` is defined at `lib/web/prototype/prototype.js:881`. Until that
file has executed, `[].each` is `undefined`.

Five more call sites in the same file have the same defect and fail the same way once
reached: `:144`, `:168`, `:191`, `:222` (each with a nested `buttonTypes.each` on the
following line), plus the `:239` one above. All nine `.each(` call sites confirmed present
in this tree, 2026-09-23.

### 3. Why it is intermittent

Prototype is an ordinary async AMD module in Magento 2, not a blocking `<script>`:

`vendor/magento/module-theme/view/base/requirejs-config.js:69`

```js
    paths: {
        ...
        'prototype': 'legacy-build.min',
```

`legacy-build.min.js` is 214 KB and is fetched only because *something else* on the page
asks for it — on a config page that is
`vendor/magento/module-config/view/adminhtml/templates/system/config/js.phtml:14`:

```js
require([
    'prototype'
], function () {
```

So two independent requirejs requests race:

| | Waits for | |
|---|---|---|
| Braintree `system.js` | `jquery`, `Magento_Ui/js/modal/alert`, `mage/translate`, `domReady!` | **does not wait for `prototype`** |
| `system/config/js.phtml` | `prototype` → `legacy-build.min.js` (214 KB) | |

`domReady!` resolves at `DOMContentLoaded`. Whether the 214 KB legacy bundle has finished
downloading, parsing and executing by then depends on cache state, network and how heavy
the section is. Warm cache → Prototype wins → no error. Cold cache, slow response, or a
section with many other JS requests → `domReady!` wins → `[].each` is still `undefined` →
throw. A reload warms `legacy-build.min.js` in the browser cache, which is why "reload
fixes it".

Adding `'prototype'` to that dependency array is the entire upstream fix.

## Proof local code is not at fault

- Both the defective file and the layout that loads it are vendor files.
- **No local code touches the admin side of Braintree at all.**
  `grep -rilI braintree app/code app/design` returns eleven files — nine under
  `Mageplaza/Osc/view/**frontend**/` (checkout payment-method mixins, their CSS,
  templates and one `i18n` string) and two frontend theme overrides of
  `Mageplaza_Osc/web/js/view/review/placeOrder.js`. Filtering that grep for `adminhtml`
  returns **nothing**.
- Narrowing to the literal string `PayPal_Braintree`, the entire local codebase holds
  exactly **two** references, both in
  `app/code/Mageplaza/Osc/view/frontend/requirejs-config.js:56,59` — and both are requirejs
  **mixin keys** on `PayPal_Braintree/js/view/payment/{method-renderer/paypal,adapter}`.
  A mixin is only fetched when its target module is requested, so they are inert on admin
  pages, and they stay inert — not 404 — once the Braintree modules are disabled. Nothing
  here `define()`s or `require()`s a `PayPal_Braintree` path.
- The `braintree`-named symbol the theme overrides *do* load,
  `Mageplaza_Osc/js/model/braintree-paypal`, is a 30-line `define(['ko'], …)` observable
  holder living inside `Mageplaza_Osc` itself. It names Braintree but does not depend on
  it, and `Mageplaza_Osc` stays enabled, so it keeps resolving.
- No local module declares `adminhtml_system_config_edit` head scripts that could reorder
  requirejs, and no local code touches `Array.prototype`.
- No first-party or Magento core module depends on `PayPal_Braintree` — a scan of every
  `etc/module.xml` under `vendor/` and `app/code/` finds it referenced only by the seven
  `paypal/module-braintree-*` packages themselves.
- The failing symbol resolves to `lib/web/prototype/prototype.js`, which is Magento's own
  vendored Prototype 1.7, untouched here.

## Fix in force

**The seven `PayPal_Braintree*` modules are disabled** in `app/etc/config.php` (tracked,
so the same state deploys to production). With the modules off, Magento never reads
`adminhtml_system_config_edit.xml`, the `<script>` node is gone from the merged layout,
and the race cannot happen.

```php
// app/etc/config.php:404-410
'PayPal_Braintree' => 0,
'PayPal_BraintreeCustomerBalance' => 0,
'PayPal_BraintreeGiftCard' => 0,
'PayPal_BraintreeGiftCardAccount' => 0,
'PayPal_BraintreeGiftWrapping' => 0,
'PayPal_BraintreeGraphQl' => 0,
'PayPal_BraintreeReward' => 0,
```

**`Magento_Paypal` and `Magento_PaypalGraphQl` are deliberately left enabled** — they are
core PayPal, unrelated to the `paypal/module-braintree-*` packages, and nothing in this
issue touches them.

`bin/magento module:disable` writes exactly this and nothing else, so editing the file by
hand is equivalent — the CLI only adds a reverse-dependency check (and there are no
reverse dependencies, see the audit). The equivalent CLI form:

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento module:disable \
  PayPal_Braintree PayPal_BraintreeGraphQl PayPal_BraintreeCustomerBalance \
  PayPal_BraintreeGiftCard PayPal_BraintreeGiftCardAccount \
  PayPal_BraintreeGiftWrapping PayPal_BraintreeReward"
```

After the edit, on a running local instance:

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php -d memory_limit=2G bin/magento setup:upgrade --keep-generated"
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && rm -f generated/metadata/*.php && php bin/magento cache:flush"
docker restart carpetavenue-jp-phpfpm-1 carpetavenue-jp-phpfpm-xdebug-1
```

The FPM restart is not optional here — `opcache.validate_timestamps = 0` means edited PHP
keeps serving as old bytecode until the container restarts.

### Reversing it

**Fully reversible.** `module:enable` on the same seven names plus `setup:upgrade` puts
back `sales_order.dispute_status`, `sales_order_grid.dispute_status`,
`braintree_transaction_details` and `braintree_credit_prices`, and the `<script>` node
reappears in the merged layout. Round-tripped and verified on the carpient install.
Stored configuration is never touched in either direction — disabling a module does not
delete its `core_config_data` rows — and this install has none to lose anyway.

### The one cost — a DDL on `sales_order`

`PayPal_Braintree` declares in `vendor/paypal/module-braintree-core/etc/db_schema.xml`:

```xml
<table name="sales_order">
    <column xsi:type="varchar" name="dispute_status" length="45" nullable="true" comment="Braintree Dispute Status"/>
</table>
<table name="sales_order_grid">
    <column xsi:type="varchar" name="dispute_status" length="45" nullable="true" comment="Braintree Dispute Status"/>
</table>
```

plus the tables `braintree_transaction_details` and `braintree_credit_prices`. All four are
in the module's `db_schema_whitelist.json`, so the declarative-schema pass in
`setup:upgrade` **drops** them once the modules are off.

**On this install that DDL is close to free.** The `2026-09-03` JP database dump
(`../jp_db_backup.sql`) has **zero rows** in `sales_order`, `sales_order_grid` and
`sales_order_payment`, and both braintree tables dump as empty. `ALTER TABLE` on an empty
table is instant and there is no `dispute_status` value anywhere to lose. Re-confirm
against live production data before the deploy rather than trusting that dump, which is
three weeks old and predates any real order volume.

**On production the DDL is not optional and not deferrable.** The Dokploy stack's
run-once `upgrade` service (`docker/docker-compose.dokploy.yml:75-82`) executes

```yaml
    command: >
      sh -c "... php bin/magento maintenance:enable &&
      php -d memory_limit=2G bin/magento setup:upgrade --keep-generated &&
      php bin/magento maintenance:disable"
```

on **every** deploy. So the drop happens on the first deploy that carries this
`app/etc/config.php`, automatically, already wrapped in maintenance mode — but with no
`--safe-mode`. Skipping `setup:upgrade` does not avoid it, it only moves it to a later,
unrelated release. **Take a DB dump before that deploy.** If the column data ever matters,
`setup:upgrade --safe-mode=1` dumps it first and `--data-restore` puts it back.

## Alternatives rejected

- **requirejs `map` override of `PayPal_Braintree/js/system`** to a local copy with
  `'prototype'` added to the deps — fixes the real bug but forks a 250-line vendor file
  that must be re-diffed on every Braintree release, to repair a feature this store does
  not use.
- **Patch the file with `cweagans/composer-patches`** — smallest correct diff (one array
  entry), but this repo has no patching pipeline today; adding one for an unused payment
  method is the wrong first patch to introduce.
- **Remove the packages with `composer remove`** — they are pulled in transitively by
  `magento/product-community-edition`; removing them means a `replace` entry and fighting
  the metapackage on every upgrade.
- **Accept it as cosmetic** — this was the first decision taken on the carpient install.
  Reversed once the blast-radius audit came back empty: the error is noise on every admin
  config page a staff member opens, and the cost of removing it is one tracked config line.

## What disabling Braintree touches here — nothing but the schema

Audited in this tree on 2026-09-23. Database rows come from the `2026-09-03` JP dump
(`../jp_db_backup.sql`) because no local instance is running; everything else is read from
the working tree. Recorded so nobody re-runs it.

| Checked | Result |
|---|---|
| Reverse module dependencies | None. `PayPal_Braintree` is referenced in `etc/module.xml` only by its six `paypal/module-braintree-*` siblings — no Magento core, no `app/code` module |
| The six sibling modules | Bridges to `Magento_CustomerBalance`, `Magento_GiftCard`, `Magento_GiftCardAccount`, `Magento_GiftWrapping`, `Magento_Reward` — all **Adobe Commerce only**, and none of the five appears in `app/etc/config.php`. Already inert on this CE store |
| Local code | Eleven files mention braintree, all frontend. Only **two** name `PayPal_Braintree`, both requirejs `mixins` keys (`app/code/Mageplaza/Osc/view/frontend/requirejs-config.js:56,59`). A mixin is fetched only when its target is requested, so with Braintree gone they are never loaded — no 404, no console error |
| `Mageplaza_Osc/js/model/braintree-paypal` | Loaded by both theme `placeOrder.js` overrides, so worth checking: it is `define(['ko'], …)` with no Braintree dependency, and lives in `Mageplaza_Osc`, which stays enabled. Keeps resolving |
| Theme | No `app/design/frontend/carpetavenue/rugs/PayPal_Braintree` override exists (nor under the dormant `carpient/rugs`) |
| Live payment methods | `Adyen_Payment`, `Evalent_InvoicePaymentMods`, `Magento_OfflinePayments`. `Magento_Paypal`/`Magento_PaypalGraphQl` stay enabled and are untouched by this change |
| Order / quote data | The `2026-09-03` dump has **zero** `INSERT` statements for `sales_order`, `sales_order_grid` and `sales_order_payment` (3 `quote` rows). No order exists that could use a `braintree%` method or lose a grid renderer |
| Stored configuration | **Zero** `payment/braintree*` rows in `core_config_data` — the dump contains no `braintree` config path at all. The method was never configured, not even to a default. (The carpient install had 27–59 cosmetic rows; this one has none) |
| Braintree tables | `braintree_transaction_details` and `braintree_credit_prices` both dump as `LOCK`/`UNLOCK` with no `INSERT` — empty |
| `dispute_status` columns | Present on `sales_order` and `sales_order_grid` in the dump, on zero rows |
| `setup_module` | Records `PayPal_Braintree` at `4.6.1` while `composer.lock` ships `4.8.0` — i.e. `setup:upgrade` has not run since the Magento upgrade. Expect the first post-change `setup:upgrade` to do the braintree drop and this row's removal in one pass |
| Message queue | `queue` holds one row, `braintree.account.update`. Orphaned, consumed by nothing once the module is off |
| Cron | Loses `braintree_credit_price` and the `braintreertau` group's `braintree_rtau`. Both are no-ops without credentials |
| Webhook route | `/braintree/webhook` starts returning 404. Braintree is not configured to call it |
| Composer | Nothing to change — disabling only edits `app/etc/config.php`. `braintree/braintree_php` and the seven packages stay in `vendor/`, unused |
| Schema | The one real change. See **Fix in force → The one cost** above |

## Not part of this issue

The other console lines that appear alongside it on admin pages are unrelated and
harmless — Adobe Experience Platform Launch telemetry shipped by `Magento_AdminAnalytics`
(`vendor/magento/module-admin-analytics/view/adminhtml/templates/tracking.phtml`):

```
my digital data: Object
launch-<hash>.min.js:3 grabbing user: <hash>
launch-<hash>.min.js:4 🚀 container.buildInfo.environment is deprecated. Please use `container.environment.stage` instead
```

Turn it off, if wanted, at **Stores → Configuration → Advanced → Admin → Admin Usage →
Enable Admin Usage Tracking = No**. Not an error; do not chase it.

## Outstanding — verify on a running instance

The workaround is committed but **has not been exercised on a running CA_JP instance**:
no `carpetavenue-jp-*` containers or volumes exist on the development machine, and the
stack shares host ports 80/443/3306/6379/9200 with the carpient stack, so only one can run
at a time. Run steps 4–6 of the re-check procedure the next time this stack is up, and add
a row to the verification log.

## Re-check procedure

After any Magento upgrade, or any `paypal/module-braintree-core` version bump:

```bash
# 1. Does the file still use Prototype's Array#each?
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && \
  grep -cF '.each(function' vendor/paypal/module-braintree-core/view/adminhtml/web/js/system.js"
```

`0` → the `.each` calls are gone (rewritten as `forEach`/`_.each`) → **FIXED**.
Non-zero → continue to step 2.

```bash
# 2. Does its require() now declare 'prototype'?
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && \
  head -1 vendor/paypal/module-braintree-core/view/adminhtml/web/js/system.js"
```

**FIXED** if the dependency list contains `'prototype'`.
**STILL BROKEN** if it is still `['jquery', 'Magento_Ui/js/modal/alert', 'mage/translate', 'domReady!']`.

```bash
# 3. Behavioural check — cold cache is what reproduces it.
#    Admin → Stores → Configuration → any section, DevTools open,
#    "Disable cache" ticked in the Network tab, hard reload 5x.
```

**STILL BROKEN** if `Uncaught TypeError: locations.each is not a function` appears on any
of the five loads. With a warm cache it will often pass even while broken — a single
clean load proves nothing.

```bash
# 4. Is the workaround still in force?
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento module:status --enabled" | grep -c Braintree
```

`0` → still disabled, the error cannot occur whatever upstream did. Non-zero → something
re-enabled the modules (a `config.php` merge, most likely) and steps 1–3 decide whether
that matters.

```bash
# 5. Definitive check that the script is not in the admin config page, no login needed.
#    Asserts on the MERGED layout for the handle, so it is immune to browser caching.
docker exec -i carpetavenue-jp-phpfpm-1 sh -c 'cat > /tmp/check-admin-layout.php' <<'EOF'
<?php
use Magento\Framework\App\Bootstrap;
require '/var/www/html/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$om->get(\Magento\Framework\App\AreaList::class)->getArea('adminhtml')
   ->load(\Magento\Framework\App\Area::PART_DESIGN);
$layout = $om->create(\Magento\Framework\View\LayoutInterface::class);
$layout->getUpdate()->addHandle('adminhtml_system_config_edit')->load();
echo "BRAINTREE NODES: " . preg_match_all('/braintree/i', $layout->getUpdate()->asString()) . "\n";
EOF
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php /tmp/check-admin-layout.php 2>/dev/null"
```

`BRAINTREE NODES: 0` → the `<script>` and `<css>` nodes are gone, the race is impossible.
`2` → the modules are enabled again and the defect is live.

```bash
# 6. Checkout is unaffected — the OSC mixins must stay inert, not 404.
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush" >/dev/null
BASE=$(docker exec carpetavenue-jp-db-1 mariadb -umagento -pmagento magento -N -B \
  -e "select value from core_config_data where path='web/secure/base_url';" | head -1)
for p in "" "checkout/cart" "customer/account/login"; do
  printf '%s -> %s\n' "$BASE$p" "$(curl -sko /dev/null -w '%{http_code}' "$BASE$p")"
done
```

All `200`, and the rendered HTML should contain zero `braintree` strings.

If upstream has instead added an `ifconfig` or moved the script to a Braintree-only
handle, the blast radius shrinks to the Braintree section even with the modules enabled —
note that in the log and consider re-enabling them.

## Verification log

| Date | Magento | Braintree | Result | Note |
|---|---|---|---|---|
| 2026-09-23 | 2.4.9 | 4.8.0 | 🔴 Confirmed in source | Root cause re-read in this tree: `system.js:1` deps omit `prototype`; nine `.each(` call sites at `:144/:145/:168/:169/:191/:192/:222/:225/:239`; `adminhtml_system_config_edit.xml` loads the script with no `ifconfig`; `prototype` mapped to async `legacy-build.min` at `module-theme/view/base/requirejs-config.js:69`. Same defect as carpient ISSUE-007, same package version. |
| 2026-09-23 | 2.4.9 | 4.8.0 | ℹ️ Blast radius | Audited what disabling the seven modules touches here — see the audit table. No reverse dependencies, no Adobe Commerce sibling targets installed, no theme override, only two local `PayPal_Braintree` references (both inert mixin keys). From the `2026-09-03` dump: **zero** `payment/braintree*` config rows, zero orders, both braintree tables empty. |
| 2026-09-23 | 2.4.9 | 4.8.0 | 🟡 Workaround applied | Seven `PayPal_Braintree*` entries set to `0` in `app/etc/config.php:404-410`; `php -l` clean. **Not yet run against a running instance** — no `carpetavenue-jp-*` containers or volumes exist on this machine and the stack's host ports collide with the running carpient stack. The equivalent change was verified end-to-end on carpient (merged admin layout 2 braintree nodes → 0, schema drop clean, all storefront and admin routes `200`, reversal round-tripped). See **Outstanding**. |
