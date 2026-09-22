# Evalent_InvoicePaymentMods

Offline **"Invoice Payment by e-mail"** payment method (`evinvoicepayment`) plus
all of its store-specific customisations for CarpetAvenue.

This module is self-contained: it registers the payment method itself **and**
the layer of modifications on top of it. It was formed by merging the upstream
`Evalent_InvoicePaymentMethod` module into `Evalent_InvoicePaymentMods` — the two
were previously split (a base method module + a mods module that overrode it via
DI preferences). Everything now lives under the single `Evalent\InvoicePaymentMods`
namespace.

## Purpose

Lets a customer place an order without paying online — the shop invoices them by
e-mail and they pay against that invoice. On top of the plain offline method the
module adds automatic invoice creation/sending, receipt e-mails, custom invoice &
credit-memo PDF layouts, and a set of admin-configurable instruction texts.

## Features

- **Payment method** `evinvoicepayment` — offline, appears at checkout with its own
  KnockoutJS renderer/template. Supports customer-group and country restrictions and
  optional "billing = shipping address" validation.
- **Auto invoice** — on `checkout_submit_all_after`, if enabled, creates (and
  optionally captures + e-mails) the invoice automatically.
- **Custom invoice PDF** — overrides `Magento\Sales\Model\Order\Pdf\Invoice` with a
  CarpetAvenue layout: extra text blocks (villkor/referens), customer e-mail line,
  vertical watermark text, extra details box, and a configurable footer
  (none / full-width / three-column). EU-tax handling and a Japanese font
  (`view/cid0jp.ttf`) for the `jp` store.
- **Packing slip PDF font** — overrides `Magento\Sales\Model\Order\Pdf\Shipment` so the
  shipment/packing-slip PDF renders with `view/cid0jp.ttf` instead of core's
  `lib/internal/GnuFreeFont/FreeSerif*.ttf`, which has no CJK glyphs and turned every
  Japanese string into `.notdef` boxes. Covers the admin "Print Shipments" action and
  the IXPF Syncer bulk-PDF endpoints, which both render through that class.
- **Receipt e-mail** — swaps the invoice e-mail template to a "receipt" template on
  receipt/capture actions (guest + registered).
- **Grand-total tax control** — toggle showing "Grand Total incl/excl tax" + "Tax"
  totals in the invoice PDF.
- **Admin instruction texts** — separate instruction blocks for payment information,
  reminders, and shipping e-mails.
- **Reprint receipt button** on the admin invoice view.

## Configuration

**Stores → Configuration → Sales → Payment Methods → Evalent Invoice Payment**
(`payment/evinvoicepayment/*`). Key fields: `active`, `title`, `auto_invoice`,
`auto_send_invoice`, `order_status`, `invoice_status_value`, customer-group /
country restrictions, `instructions*`, `email_template_receipt*`, `pdf_footer_*`,
`show_grand_total_tax_invoice_pdf`.

## Structure

| Path | Role |
|------|------|
| `Model/Payment/EvInvoicePayment.php` | Base offline payment method |
| `Model/Payment.php` | Active method (extends base; adds `is_active` event + info block) |
| `Model/EvInvoiceConfigProvider/EvInvoiceConfigProviderBase.php` | Base checkout config provider |
| `Model/EvInvoiceConfigProvider/EvInvoiceConfigProvider.php` | Active config provider (store-scoped) |
| `Model/Config/Source/*` | Admin dropdown sources (customer groups, invoice status) |
| `Model/Config/Source/PdfFooterTypes.php` | Footer style options |
| `Helper/Data.php` | Invoice creation / e-mail sending |
| `Observer/CheckoutAllSubmitAfterObserver.php` | Auto-invoice trigger |
| `Observer/BeforeOrderPaymentSaveObserver.php` | Sets payment instructions, clears stray Adyen data |
| `Observer/AfterInvoicePaid.php` | Post-payment handling |
| `Overwrite/Plugin/Invoice.php` | Invoice PDF renderer (extends base `Plugin/Invoice.php`) |
| `Overwrite/Plugin/DefaultInvoice.php` | Invoice line-item PDF renderer |
| `Overwrite/Plugin/Shipment.php` | Packing slip PDF renderer (Japanese font only) |
| `Overwrite/Tax/Model/Sales/Pdf/Grandtotal.php` | Grand-total PDF total |
| `Plugin/PluginCheckEmail.php` | Stores customer e-mail in checkout session |
| `Plugin/Helper/Data.php` | Sets order status after auto-invoice |
| `Plugin/Model/Order/Email/Container/InvoiceIdentity.php` | Swaps to receipt e-mail template |
| `Plugin/Block/Adminhtml/Order/View.php` | Adds reprint-receipt button |
| `Block/Info/Instructions.php` | Payment info block (context-aware instructions) |
| `view/frontend/...` | Checkout renderer JS, template, layout, receipt e-mail |
| `view/cid0jp.ttf` | Japanese PDF font (invoice + packing slip) |

## Dependencies

Sequenced after `Magento_Payment`, `Magento_Sales`, `Magento_Checkout`,
`Magento_Customer`, `Magento_Tax`.
