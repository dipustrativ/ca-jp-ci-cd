# CarpetAvenue\_VAT

> Unified home for every tax / VAT customization on the CarpetAvenue store — VIES
> VAT-number validation, shipping-VAT rules, cross-border-trade net pricing,
> store-country rate origin, and EUR-store price cleanup. **Every feature is behind
> its own on/off switch and no-ops when disabled.**
> **Package:** `CarpetAvenue_VAT` · **Author:** Strativ Magento Team · **© Carpet Avenue GmbH**

---

## Manual

### What is this module?

`CarpetAvenue_VAT` consolidates tax/VAT behavior that used to be scattered across
paid modules (`Geissweb_Euvat`) and wrappers (`Evalent_PriceMods`,
`CarpetAvenue_Vies`) in the old store. It has **no dependency on Geissweb or any
paid VAT library** — the free EU VIES service is the default. Features:

- **Customer groups + tax classes** — establishes the standard B2B layout:
  `0 NOT LOGGED IN` / `1 Consumer` / `2 Business` / `3 Tax-Free`, and a VAT-exempt
  customer tax class wired to the Tax-Free group. Idempotent data patches.
- **VIES VAT validation** — validate an EU VAT number, with **two switchable
  providers**: the free official **EU VIES SOAP** service, or the paid
  **viesapi.eu** library. Off by default; informational (no dynamic group change).
- **Shipping-not-tax-free** — force shipping to be taxed even on an otherwise
  tax-free order, for a configured customer group or VAT-validated address.
- **Custom tax calculation (CBT net pricing)** — customers in configured groups
  receive net prices by swapping their customer tax class during rate lookup.
- **Store-country rate origin** — compute the origin tax rate from the store's
  default country instead of the shipping origin (fixes wrong tax deduction on
  tax-free orders).
- **EUR store pricing cleanup** — nightly cron that removes non-default-store
  price overrides; only for stores that actually have EUR store views.

> **Tax rates / classes / rules are seeded with native Magento import/export**
> (Stores → Tax Zones and Rates → Import/Export). This module intentionally ships
> **no** custom CSV tooling.

---

### Admin Configuration

**Path:** Stores → Configuration → CarpetAvenue → **VAT / Tax**
(`carpetavenue_vat`, scope: default / website / store)

#### VIES VAT Validation (`vies`)

| Field | Description |
|---|---|
| **Enabled** | Master toggle. Nothing in the VIES feature runs unless Yes. Default: **No**. |
| **Provider** | `EU VIES SOAP` (free, no account) or `viesapi.eu` (paid). Shown when Enabled. Default: `eu_soap`. |
| **viesapi.eu Test Mode** | Use viesapi.eu demo credentials (no ID/Key). Shown for provider = viesapi. Default: **Yes**. |
| **viesapi.eu ID** | Account ID. Shown for provider = viesapi + Test Mode = No. |
| **viesapi.eu Key** | Account key (encrypted). Shown for provider = viesapi + Test Mode = No. |

#### Shipping VAT (`shipping_vat`)

| Field | Description |
|---|---|
| **Force Shipping Not Tax-Free** | Master toggle for taxing shipping on tax-free orders. Default: **No**. |
| **Target Customer Group** | Customers in this group always get taxed shipping. Others fall back to the address's VAT validity. Shown when the toggle is Yes. |

#### Custom Tax Calculation (`tax_calc`)

| Field | Description |
|---|---|
| **Use Store Country As Rate Origin** | Origin rate from the store default country instead of the shipping origin. Default: **No**. |
| **Disable Cross-Border-Trade For Groups** | Multiselect. Customers in these groups receive **net** prices. |
| **Net Price Tax Class** | Customer tax class applied to the groups above. **Both this and the group list must be set or the plugin no-ops.** |

#### EUR Store Pricing (`eur_pricing`)

| Field | Description |
|---|---|
| **Null Pricing In EUR Stores** | Enables the nightly cleanup cron (below). Default: **No**. |

---

### EUR Store Pricing Cron

`carpetavenue_vat_clean_non_null_store_values` — runs daily at **03:00**
(`0 3 * * *`). When **Null Pricing In EUR Stores** is Yes, it deletes
`catalog_product_entity_int` rows whose `store_id` is outside the allowed set
(default + EUR store view), so EUR store views inherit the default price instead
of stale per-store overrides. When the flag is No the cron **exits immediately**.

---

### Installation

```bash
bin/magento module:enable CarpetAvenue_VAT
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Data patches create/rename the four customer groups (keeping ids `1/2/3`) and the
Tax-Free customer tax class. **Requirements:** PHP 8.1+, Magento 2.4.x. The
viesapi.eu provider additionally needs `composer require viesapi/viesapi-php`
(only exercised when provider = `viesapi`; the EU SOAP provider needs nothing).

---

## Testing / QA — what to actually verify

> Golden rule: **with every switch OFF, tax must behave exactly like stock
> Magento.** Every plugin/observer checks its own flag first and returns early.

### Groups & tax classes (after `setup:upgrade`)

1. Customers → Customer Groups shows **NOT LOGGED IN / Consumer / Business /
   Tax-Free** with ids **0 / 1 / 2 / 3**.
2. Stores → Tax Zones and Rates → Tax Classes → a VAT-exempt customer tax class
   exists and is assigned to **Tax-Free**.
3. Re-run `setup:upgrade` → **no duplicate** groups/classes (patches idempotent).

### Everything-off baseline

4. Fresh install / all switches No → place a normal order → tax total identical to
   stock Magento. No plugin should alter rate, shipping tax, or class.

### VIES

5. Enable, provider = **EU VIES SOAP**, validate a known-valid EU VAT (e.g. a
   published test number) → returns **valid**; an invalid number → **invalid**;
   confirm no PHP error when the EU service is briefly unreachable (graceful
   error result, not an exception).
6. Switch provider = **viesapi.eu**, Test Mode = Yes → validation still returns a
   result through the viesapi path (demo creds, no ID/Key).
7. Set Enabled = No → `VatValidationService` returns a no-op result and makes **no
   outbound call**.

### Shipping-not-tax-free

8. Toggle On, set Target Customer Group = Business → cart as a Business customer on
   an otherwise tax-free order → **shipping is taxed**.
9. Same order as a non-target group with a VAT-validated address → shipping taxed;
   without VAT validity → not taxed.
10. Toggle Off → shipping tax reverts to stock behavior for all groups.

### Custom tax calculation (CBT net pricing)

11. Add a group to **Disable CBT For Groups** AND set **Net Price Tax Class** → a
    customer in that group sees **net** prices (rate looked up against the net
    class).
12. Set only one of the two fields → plugin **no-ops** (prices unchanged) — both
    are required.
13. **Use Store Country As Rate Origin** On → origin rate resolves from the store
    default country; verify a previously wrong tax deduction on a tax-free order
    is corrected. Off → stock origin behavior.

### EUR pricing cron

14. Flag On → run
    `bin/magento cron:run --group=default` (or wait for 03:00) → non-allowed
    `store_id` price overrides removed. Flag Off → cron logs nothing / exits.

### Quality gates

```bash
vendor/bin/phpcs --standard=Magento2 app/code/CarpetAvenue/VAT
vendor/bin/phpstan analyse app/code/CarpetAvenue/VAT
```
Both must be clean (0 errors).

---

## Developer

### Architecture

```
CarpetAvenue_VAT/
├── Api/
│   └── ViesValidatorInterface.php          # Contract: validate(country, vatNumber): ViesResult
├── Cron/
│   └── CleanNonNullStoreValues.php         # Daily 03:00 — deletes non-allowed store price overrides (gated)
├── Model/
│   ├── Config.php                          # Typed single-source reader for every config path
│   ├── Config/Source/ViesProvider.php      # Dropdown: eu_soap / viesapi
│   ├── ShippingVatManager.php              # Decides "tax shipping anyway?" — per-request memoized
│   ├── Tax/Calculation.php                 # Preference — overrides protected getRateOriginRequest (store-country origin)
│   └── Vies/
│       ├── ViesResult.php                  # Immutable DTO (valid/invalid/error factory)
│       ├── EuSoapValidator.php             # Free EU VIES REST/SOAP call (Curl, no lib)
│       └── ViesApiValidator.php            # viesapi.eu lib path (guarded class_exists)
├── Plugin/Tax/
│   ├── HelperDataPlugin.php                # beforeGetShippingPrice → includingTax=false (shipping-not-taxfree)
│   ├── ConfigPlugin.php                    # afterShippingPriceIncludesTax → false
│   └── CalculationPlugin.php               # aroundGetRateRequest → CBT net class swap (group memoized)
├── Service/
│   └── VatValidationService.php            # Selects active provider by config; no-ops when VIES disabled
├── Setup/Patch/Data/
│   ├── SetupCustomerGroups.php             # Idempotent rename → Consumer/Business/Tax-Free (ids 1/2/3)
│   └── SetupTaxClasses.php                 # VAT-exempt customer tax class → Tax-Free group (after groups)
└── etc/
    ├── module.xml                          # sequence: Core, Customer, Tax, TaxImportExport, Quote, Checkout
    ├── config.xml                          # Defaults — all switches 0, VIES provider eu_soap, test_mode 1
    ├── di.xml                              # Tax\Calculation preference, VIES provider registry, 3 tax plugins
    ├── crontab.xml                         # carpetavenue_vat_clean_non_null_store_values @ 0 3 * * *
    ├── acl.xml                             # CarpetAvenue_VAT::vat under CarpetAvenue_Core::carpetavenue
    └── adminhtml/
        ├── menu.xml                        # CarpetAvenue → VAT / Tax
        └── system.xml                      # Section carpetavenue_vat — 4 groups
```

### Key Classes

| Class | Responsibility |
|---|---|
| `Model/Config.php` | Typed getter per config path; `disableCbtGroups()` returns `int[]`. Every feature gate reads from here. |
| `Model/Tax/Calculation.php` | Class **preference** (not a plugin) because `getRateOriginRequest()` is protected — swaps origin country to the store default when enabled. |
| `Plugin/Tax/CalculationPlugin.php` | `aroundGetRateRequest` — swaps customer tax class to the net class for CBT groups. No-ops unless both group list + net class are set. Caches customer→group lookups per request. |
| `Model/ShippingVatManager.php` | Single decision point for taxing shipping; used by both shipping plugins. Per-store memoized. |
| `Service/VatValidationService.php` | Fronts the VIES providers; returns the active one from `vies/provider`; no-op result when `vies/enabled=0`. |
| `Model/Vies/EuSoapValidator.php` / `ViesApiValidator.php` | The two interchangeable VIES backends. |

### Config Paths

| Setting | Path |
|---|---|
| VIES enabled | `carpetavenue_vat/vies/enabled` |
| VIES provider | `carpetavenue_vat/vies/provider` |
| viesapi test mode / id / key | `carpetavenue_vat/vies/{test_mode,viesapi_id,viesapi_key}` |
| Force shipping not tax-free | `carpetavenue_vat/shipping_vat/shipping_not_taxfree` |
| Shipping target group | `carpetavenue_vat/shipping_vat/target_group` |
| Store-country rate origin | `carpetavenue_vat/tax_calc/store_country_origin` |
| Disable CBT for groups | `carpetavenue_vat/tax_calc/disable_cbt_groups` |
| Net price tax class | `carpetavenue_vat/tax_calc/excluding_tax_class` |
| Null pricing in EUR stores | `carpetavenue_vat/eur_pricing/null_pricing_in_eur_stores` |

### Customer group ids (locked)

| id | Group | Notes |
|---|---|---|
| 0 | NOT LOGGED IN | Magento default — untouched |
| 1 | Consumer | Renamed from `General` |
| 2 | Business | Referenced by `CarpetAvenue_B2B` |
| 3 | Tax-Free | **Must stay 3** — hardcoded in `Productify/Block/Pricing/Render/Amount.php` + `Proforma` |

### DI Highlights

- `preference` `Magento\Tax\Model\Calculation` → `CarpetAvenue\VAT\Model\Tax\Calculation`.
- `VatValidationService` `providers` array: `eu_soap` → `EuSoapValidator`, `viesapi` → `ViesApiValidator`.
- Plugins on `Magento\Tax\Helper\Data`, `Magento\Tax\Model\Config`, `Magento\Tax\Model\Calculation`.

### ACL Resources

```
CarpetAvenue_Core::carpetavenue
└── CarpetAvenue_VAT::vat        ← guards the config section
```

### Dependencies

| Module / Package | Reason |
|---|---|
| `CarpetAvenue_Core` | Admin tab + shared ACL root |
| `Magento_Tax` | Calculation preference + Helper/Config plugins |
| `Magento_Customer` | Group repository (setup patches), group source models |
| `Magento_TaxImportExport` | Native rate CSV import/export (used via admin, no custom code) |
| `Magento_Quote` / `Magento_Checkout` | Quote/checkout session for shipping-VAT decision |
| `viesapi/viesapi-php` | Optional — only the viesapi.eu provider (guarded `class_exists`) |
</content>
</invoke>
