# CarpetAvenue\_B2B

> B2B account-approval workflow: new storefront accounts register as **Business**
> and **cannot place orders until an administrator approves them**. Everything is
> gated by a single master switch. Builds on the customer groups created by
> `CarpetAvenue_VAT`. Also ships the **"Become a Reseller"** storefront application
> form (stores submissions + emails the admin + an admin grid to review them).
> **Package:** `CarpetAvenue_B2B` · **Author:** Strativ Magento Team · **© Carpet Avenue GmbH**

---

## Manual

### What is this module?

Net-new capability (there was no approval flow anywhere in either store). Flow:

1. **Register** — a new storefront account is put in the **Business** group and
   flagged `is_approved = 0`.
2. **Browse & cart stay open** — prices are visible and add-to-cart works; only
   **purchasing** is blocked. The account dashboard shows a pending banner.
3. **Checkout blocked** — an unapproved customer is redirected away from checkout
   with the configured message; a hard `placeOrder` guard also throws on OSC/API
   paths so the block cannot be bypassed.
4. **Admin approves** — via the customer grid mass action or by editing the
   customer. On the `0 → 1` transition an approval email is sent.
5. **Customer can now buy** — the gate opens for that customer only.

Features:

- **`is_approved` customer attribute** — boolean, shown in the admin customer grid
  and edit form.
- **Default-to-Business registration** (toggle).
- **Checkout gate** — friendly redirect observer + authoritative place-order guard.
- **Pending banner** on the account dashboard.
- **Admin approval UI** — grid column + "Approve (B2B)" mass action.
- **Approval email** (toggle) via a configurable template/sender.

---

### Become a Reseller (application form)

A self-contained lead-capture flow, independent of the approval gate above
(mirrors `carpient.com/pages/become-a-carpient-reseller`).

- **Storefront page** — `GET /become-a-carpient-reseller` renders the application
  form (Name, Email, Company Name, Homepage, Type of Business, Sales Channels,
  Message). Layout, copy and fields mirror
  `carpient.com/pages/become-a-carpient-reseller`. Frontend route id `reseller`,
  frontName `become-a-carpient-reseller`.
- **Submit** — `POST /become-a-carpient-reseller/index/post` validates (required fields + email),
  **persists** the row to `carpetavenue_b2b_reseller_application` (status `new`,
  store + customer id captured), then **emails the admin**. On error the entered
  values are retained via the customer session and the form re-populates.
- **Admin notification email** — `carpetavenue_b2b_reseller_email_template` template,
  sent to the configured recipient (falls back to the General Contact address).
  Toggle + recipient/sender/template under config group **Reseller Applications**.
- **Admin listing** — **CarpetAvenue → Reseller → Reseller Applications** menu opens
  a UI grid of every submission (filter/sort/export). Each row has a **View** action
  opening a read-only detail page. ACL: `CarpetAvenue_B2B::reseller_applications`
  (under `CarpetAvenue_B2B::reseller`).
- **One application per email** — a resubmission from an email already on file is
  rejected with *"You have already submitted a reseller application with this email
  address…"* (checked in `Controller/Index/Post` via
  `ResourceModel\ResellerApplication::existsByEmail`); nothing is stored or emailed.
- **Fully translated** — all storefront strings (labels, prompts, options, messages,
  the button, the title) ship as `i18n/*.csv` for de_DE, es_ES, fr_FR, it_IT, hu_HU,
  nl_NL, pl_PL, pt_PT, fi_FI, da_DK, sv_SE. Each store view must have its
  `general/locale/code` set to the matching locale for the CSV to apply.
- **Google reCAPTCHA v3** — native Magento integration on the form. Frontend renders
  the invisible v3 widget (token minted on submit); the backend re-verifies the token
  in `Controller/Index/Post` (`IsCaptchaEnabledInterface` + `ValidatorInterface`) and
  rejects the submission — nothing is saved and no email is sent — if it fails.
  Controlled by a single master switch **Enable reCAPTCHA Verification**
  (`carpetavenue_b2b/reseller/recaptcha_enabled`, **default Yes**) in the module's
  Reseller Applications config group — Yes both renders the widget and enforces the
  backend check, No disables both. Uses the global v3 site/secret keys + score
  threshold from Security → Google reCAPTCHA Storefront; if those keys are missing,
  verification is skipped automatically.
- **Intro copy is a CMS block** — the description above the form is *not* hardcoded;
  it renders from the CMS block identified by config (default identifier
  `become_a_carpient_reseller_intro`, seeded by data patch `AddResellerIntroBlock`).
  Edit it under **Content → Blocks**; point the page at any other block via the
  config field. The `<h1>` page title stays in the template.

| Config field (`carpetavenue_b2b/reseller/*`) | Description |
|---|---|
| **Send Admin Notification Email** | Toggle the notification. Applications are stored either way. Default: **Yes**. |
| **Notification Recipient** | Admin email that receives applications. Blank → General Contact email. |
| **Notification Sender / Template** | Store email identity + template used for the notification. |
| **Intro CMS Block Identifier** | CMS block rendered as the page description. Default: `become_a_carpient_reseller_intro`. |
| **Enable reCAPTCHA Verification** | Master switch for Google reCAPTCHA v3 (widget + backend check). Default: **Yes**. Skipped if storefront reCAPTCHA keys are missing. |

---

### Admin Configuration

**Path:** Stores → Configuration → CarpetAvenue → **B2B**
(`carpetavenue_b2b`, scope: default / website / store)

| Field | Description |
|---|---|
| **Require Account Approval** | **Master switch.** When No, the whole gate no-ops and everyone checks out normally. Default: **Yes**. |
| **Register New Customers As Business** | Assign the Business group to storefront-created accounts. Default: **Yes**. |
| **Pending Approval Message** | Text shown to blocked customers (banner + checkout redirect). Shown when approval required. |
| **Send Email On Approval** | Send the approval email on the `0 → 1` transition. Default: **Yes**. |
| **Approval Email Sender** | Store email identity used as sender. Shown when email enabled. Default: `general`. |
| **Approval Email Template** | Template to send. Shown when email enabled. Default: `carpetavenue_b2b_general_email_template`. |

---

### Admin Approval

**Path:** Customers → All Customers

- **`Approved` column** — shows each customer's approval state.
- **Mass action "Approve (B2B)"** — select customers → Actions → Approve.
  Approval is written via the customer repository, which fires the email observer.
- Editing a customer and setting **Is Approved = Yes** works identically.

Requires ACL `CarpetAvenue_B2B::approve_customer` (under Customers → Manage).

---

### Installation

```bash
bin/magento module:enable CarpetAvenue_B2B
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Sequenced **after `CarpetAvenue_VAT`** (needs the Business group id `2`).
**Requirements:** PHP 8.1+, Magento 2.4.x, `CarpetAvenue_VAT`.

---

## Testing / QA — what to actually verify

> Golden rule: **`Require Account Approval = No` → the store behaves exactly like
> stock Magento** (register, checkout, order all work for everyone). Flip it On to
> activate the gate.

### Registration

1. Approval **On**, Default-to-Business **On** → register a new account → customer
   lands in the **Business** group with **`is_approved = 0`** (verify in admin grid
   / customer edit).
2. Default-to-Business **Off** → new account keeps Magento's default group.

### Pending banner & browsing

3. Log in as the unapproved customer → account dashboard shows the **Pending
   Approval Message**.
4. Product prices are visible and **add-to-cart works** (browsing is not blocked).

### Checkout gate (the important part)

5. Unapproved customer clicks **Checkout** → redirected to cart with the pending
   message (observer path — `checkout/index/index` and OSC).
6. **Bypass attempt:** trigger `placeOrder` directly (OSC / REST API) → the
   `PlaceOrderGuard` plugin **throws** and the order is **not** created. This is
   the authoritative block; step 5 is only UX.
7. Approval **Off** → the same customer checks out and orders successfully (gate
   fully disabled).

### Approval + email

8. Admin selects the customer → **Approve (B2B)** mass action → `is_approved` flips
   to `1`.
9. **Send Email On Approval** On → an "account approved" email lands in
   **mailcatcher** (`carpient-mailcatcher-1`, http://localhost:1080). Off → no
   email, approval still applies.
10. Approved customer now completes checkout with **no** redirect and **no** guard
    exception.

### Idempotency / regression

11. Re-run `setup:upgrade` → `is_approved` attribute not duplicated.
12. Approve an already-approved customer → **no** duplicate email (only the `0 → 1`
    transition sends).

### Quality gates

```bash
vendor/bin/phpcs --standard=Magento2 app/code/CarpetAvenue/B2B
vendor/bin/phpstan analyse app/code/CarpetAvenue/B2B
```
Both must be clean (0 errors).

---

## Developer

### Architecture

```
CarpetAvenue_B2B/
├── Block/
│   ├── Account/PendingNotice.php            # Banner block — shows message when gate active + unapproved
│   ├── Reseller/Form.php                    # Reseller form view model — options, post URL, retained values, intro CMS block, reCAPTCHA flag
│   └── Adminhtml/Reseller/View.php          # Admin single-application detail block
├── Controller/
│   ├── Adminhtml/Customer/MassApprove.php   # Customer grid mass action — approve via CustomerRepository (fires email)
│   ├── Adminhtml/Reseller/Index.php         # Admin grid page
│   ├── Adminhtml/Reseller/View.php          # Admin detail page (registers loaded application)
│   ├── Index/Index.php                      # Storefront GET — renders /become-a-carpient-reseller
│   └── Index/Post.php                       # Storefront POST — reCAPTCHA + validate + dedupe + save + notify
├── Model/
│   ├── Config.php                           # Typed config reader; BUSINESS_GROUP_ID = 2; approval + reseller getters
│   ├── ApprovalChecker.php                  # Single authority: isGateActive / isApproved / isBlocked
│   ├── ResellerApplication.php              # Application model
│   ├── ResourceModel/ResellerApplication.php            # + existsByEmail()
│   ├── ResourceModel/ResellerApplication/Collection.php
│   ├── Source/{BusinessType,SalesChannel,ApplicationStatus}.php  # Dropdown / status option sources
│   └── Email/
│       ├── ApprovalEmailSender.php          # Approval email (TransportBuilder)
│       └── ResellerApplicationSender.php    # Admin notification email
├── Observer/
│   ├── SetPendingOnRegister.php             # customer_register_success → Business group + is_approved=0
│   ├── BlockCheckout.php                    # predispatch → redirect unapproved customer (UX guard)
│   └── SendApprovalEmail.php                # customer_save_after_data_object → send on 0→1 transition
├── Plugin/Checkout/
│   └── PlaceOrderGuard.php                  # beforePlaceOrder on QuoteManagement → throws for unapproved (authoritative)
├── Ui/Component/Listing/Column/
│   └── ResellerActions.php                  # "View" action column for the applications grid
├── Setup/Patch/Data/
│   ├── AddIsApprovedAttribute.php           # is_approved customer EAV attr (grid + adminhtml_customer form)
│   └── AddResellerIntroBlock.php            # Seeds the become_a_carpient_reseller_intro CMS block (idempotent)
├── i18n/                                    # de_DE es_ES fr_FR it_IT hu_HU nl_NL pl_PL pt_PT fi_FI da_DK sv_SE
├── view/
│   ├── frontend/
│   │   ├── layout/customer_account.xml      # Injects PendingNotice into account dashboard
│   │   ├── layout/reseller_index_index.xml  # Reseller page (1column) + reCAPTCHA child block; removes default page title
│   │   ├── templates/account/pending_notice.phtml
│   │   ├── templates/reseller/form.phtml    # Reseller page + form (scoped CSS mirroring carpient.com)
│   │   └── email/
│   │       ├── account_approved.html        # Approval email body
│   │       └── reseller_application.html    # Admin notification body
│   └── adminhtml/
│       ├── layout/{carpetavenue_b2b_reseller_index,carpetavenue_b2b_reseller_view}.xml
│       ├── ui_component/{customer_listing,carpetavenue_b2b_reseller_listing}.xml
│       └── templates/reseller/view.phtml    # Admin detail view
└── etc/
    ├── db_schema.xml                        # carpetavenue_b2b_reseller_application table
    ├── module.xml                           # sequence: Core, VAT, Customer, Checkout, Quote, Sales, Cms, ReCaptcha{Ui,ValidationApi,FrontendUi}
    ├── config.xml                           # Defaults — approval + reseller (notify, intro_block, recaptcha_enabled) + recaptcha type v3
    ├── di.xml                               # placeOrder guard plugin + reseller grid data source
    ├── events.xml                           # customer_save_after_data_object → SendApprovalEmail
    ├── email_templates.xml                  # carpetavenue_b2b_general_email_template + carpetavenue_b2b_reseller_email_template
    ├── acl.xml                              # config / approve / approve_customer / reseller / reseller_applications
    ├── frontend/
    │   ├── events.xml                       # register_success + checkout/OSC predispatch → observers
    │   └── routes.xml                       # Frontend route id reseller, frontName become-a-carpient-reseller
    └── adminhtml/
        ├── menu.xml                         # CarpetAvenue → { Reseller → Reseller Applications, B2B Settings }
        ├── routes.xml                       # Admin route id/frontName: carpetavenue_b2b
        └── system.xml                       # Section carpetavenue_b2b → general + reseller groups
```

### Key Classes

| Class | Responsibility |
|---|---|
| `Model/ApprovalChecker.php` | The one place the "can this customer buy?" rule lives. `isBlocked()` = gate active AND not approved. Used by the observer, the guard, and the banner. |
| `Plugin/Checkout/PlaceOrderGuard.php` | `beforePlaceOrder` on `QuoteManagement` — the **authoritative** block covering native checkout, OSC, and REST. Throws `LocalizedException`. |
| `Observer/BlockCheckout.php` | UX-only redirect so unapproved customers don't hit a dead-end checkout screen. |
| `Observer/SetPendingOnRegister.php` | On `customer_register_success` sets Business group + `is_approved=0` (only when the gate is on). |
| `Observer/SendApprovalEmail.php` | Detects the `is_approved` `0 → 1` transition on save and sends the email. |
| `Model/Email/ApprovalEmailSender.php` | Builds/sends the transactional email via `TransportBuilder`. |

### Customer attribute

| Attribute | Type | Default | Where |
|---|---|---|---|
| `is_approved` | int (boolean input) | `0` | customer grid + `adminhtml_customer` form |

### Config Paths

| Setting | Path |
|---|---|
| Require account approval (master) | `carpetavenue_b2b/general/require_approval` |
| Register new customers as Business | `carpetavenue_b2b/general/default_to_business` |
| Pending approval message | `carpetavenue_b2b/general/pending_message` |
| Send email on approval | `carpetavenue_b2b/general/send_approval_email` |
| Approval email sender / template | `carpetavenue_b2b/general/{email_identity,email_template}` |
| Reseller admin notification (toggle) | `carpetavenue_b2b/reseller/notify_enabled` |
| Reseller recipient / sender / template | `carpetavenue_b2b/reseller/{recipient,email_identity,email_template}` |
| Reseller intro CMS block | `carpetavenue_b2b/reseller/intro_block` |
| Reseller reCAPTCHA (master toggle) | `carpetavenue_b2b/reseller/recaptcha_enabled` |

### Events, plugins & routes

| Type | Hook | Target |
|---|---|---|
| Observer (frontend) | `customer_register_success` | `SetPendingOnRegister` |
| Observer (frontend) | `controller_action_predispatch_checkout_index_index` | `BlockCheckout` |
| Observer (frontend) | `controller_action_predispatch_onestepcheckout_index_index` | `BlockCheckout` |
| Observer (global) | `customer_save_after_data_object` | `SendApprovalEmail` |
| Plugin | `Magento\Quote\Model\QuoteManagement::placeOrder` (before) | `PlaceOrderGuard` |
| Route (admin) | `carpetavenue_b2b/customer/massapprove` | `MassApprove` |
| Route (admin) | `carpetavenue_b2b/reseller/{index,view}` | `Adminhtml\Reseller\{Index,View}` |
| Route (frontend) | `become-a-carpient-reseller` (`/index/post`) | `Index\{Index,Post}` |

### ACL Resources

```
CarpetAvenue_Core::carpetavenue
└── CarpetAvenue_B2B::b2b
    ├── CarpetAvenue_B2B::config                 ← config section
    ├── CarpetAvenue_B2B::approve
    └── CarpetAvenue_B2B::reseller               ← "Reseller" menu parent
        └── CarpetAvenue_B2B::reseller_applications  ← grid + view + data source
Magento_Customer::manage
└── CarpetAvenue_B2B::approve_customer            ← required by MassApprove
```

### Dependencies

| Module / Package | Reason |
|---|---|
| `CarpetAvenue_Core` | Admin tab + shared ACL root |
| `CarpetAvenue_VAT` | Creates the Business group (id 2) this module assigns |
| `Magento_Customer` | Customer attribute, groups, repository, grid UI component |
| `Magento_Checkout` / `Magento_Quote` | Checkout predispatch events + `QuoteManagement::placeOrder` guard |
| `Magento_Sales` | Order placement path guarded by the plugin |
| `Magento_Cms` | Reseller intro CMS block (seed patch + render) |
| `Magento_ReCaptchaUi` / `Magento_ReCaptchaValidationApi` / `Magento_ReCaptchaFrontendUi` | reCAPTCHA v3 render + backend token verification on the reseller form |
</content>
