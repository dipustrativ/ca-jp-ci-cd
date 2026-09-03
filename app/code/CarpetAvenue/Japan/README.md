# CarpetAvenue_Japan

Home for Japan-specific (ja_JP store) logic for the CarpetAvenue Magento store.

Use this module for anything that is only true because the store operates in
Japan — locale/market rules that don't belong in the general catalog, order or
checkout modules. Examples of what fits here:

- Japanese address / name formatting (kana fields, postal-code lookup)
- JPY-specific display or rounding rules not already in `Productify` pricing
- Japan tax / consumption-tax specifics not covered by `CarpetAvenue_VAT`
- Japan-only shipping, carrier or delivery-date behaviour
- Locale-conditional emails, labels or content

Sequences after `CarpetAvenue_Core` (the shared foundation every CarpetAvenue
module builds on). Add further `<sequence>` entries in `etc/module.xml` as the
logic here starts to depend on other modules.

## Structure

Standard Magento 2 module layout — add directories as needed:

- `etc/` — `di.xml`, `events.xml`, `adminhtml/`, `frontend/`, config
- `Model/`, `Block/`, `ViewModel/`, `Observer/`, `Plugin/`, `Controller/`
- `Setup/Patch/Data/` — data patches
- `view/frontend/`, `view/adminhtml/` — templates, layout, web assets
- `Test/Unit/` — PHPUnit tests

Run `setup:upgrade` after enabling.

## Carpet Finder

A home/CMS carpet finder ported from the base store's `Evalent_CarpetFinder`
(home page + widget scope only). It shows two "steps" of quick-filter buttons
that deep-link into the **all-carpets** root category:

- **Price step** — pretty price buckets per currency (JPY `[50000, 130000]`),
  links `?price=<from>-<to>` (values in base currency).
- **Size step** — common room sizes, links `?width2=<cm>&height2=<cm>` range
  filters. Depends on the `width2` / `height2` decimal attributes being
  populated (ERP-sourced) and read by the Productify layered nav.

### Config

Stores → Config → **CarpetAvenue → Japan → Carpet Finder**
(section `japan`, group `carpet_finder`):

| Path | Meaning |
|---|---|
| `japan/carpet_finder/enabled` | Master on/off |
| `japan/carpet_finder/step_price_enabled` | Show price step |
| `japan/carpet_finder/step_size_enabled` | Show size step |
| `japan/carpet_finder/mobile_hide` | Hide on mobile |

### How it renders

`carpet_finder.xml` **declares** the `carpetfinder` block (orphan — not placed).
`cms_index_index.xml` / `cms_page_view.xml` apply that handle so the block is
available on CMS pages. It is **not** auto-placed anywhere — drop the
**"Carpet Finder block"** widget into a CMS page's content (WYSIWYG → Insert
Widget) to render it where you want. The root category comes from
`Evalent_MegaMenu` (`catalog/navigation/top_menu_root_category_id`).

### Differences from the base module (intentional)

- **No Amasty** — this store uses the custom `CarpetAvenue_Productify` layered
  nav. The Amasty-coupled category-page steps (`AttributeStep`, `SizeStep`),
  the AJAX `Plugin/Category/View`, and `carpetfinder.js` were **not** ported.
- **No `catalog_product_save_before` observer / import plugin** — populating
  `height2` / `width2` is handled upstream (ERP/IXPF sync), not here.
- Config moved from `catalog/carpet_finder/*` to `japan/carpet_finder/*`.
