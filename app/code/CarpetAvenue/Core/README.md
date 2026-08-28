# CarpetAvenue\_Core

> Foundation module for all CarpetAvenue custom extensions.  
> **Package:** `CarpetAvenue_Core` · **Author:** Strativ Magento Team · **© Carpet Avenue GmbH**

---

## Manual

### What is this module?

`CarpetAvenue_Core` is the shared foundation that every other CarpetAvenue module depends on.
It provides:

- A top-level **CarpetAvenue** admin menu with a **Miscellaneous → Documentation** hub
- **Custom CSS/JS** injection into storefront `<head>` (per store view)
- **Company JSON-LD Schema** admin field
- **Slack Notification** integration via Incoming Webhooks

### Admin Configuration

**Stores → Configuration → Carpet Avenue**

#### Custom CSS/JS — `Head Includes`

| Field               | Scope             | Description                                                                |
|---------------------|-------------------|----------------------------------------------------------------------------|
| Global CSS / JS     | Default / Website | Injected into `<head>` on every page. No `<style>`/`<script>` tags needed. |
| Store View CSS / JS | Store only        | Applied to a specific store view only.                                     |

#### Company Schema

Paste raw JSON-LD into **Company Schema Code**. Tags are wrapped automatically.

#### Notification → Slack Communication

Global on/off + two pre-configured channels:

| Channel     | Purpose                                          |
|-------------|--------------------------------------------------|
| **Default** | General messages, order events, module fallback. |
| **Watcher** | Cron jobs, system events, monitoring alerts.     |

Each channel: `Enabled` · `Bot Username` · `Channel Name` · `Webhook URL` · `Icon URL`

### Setting up Slack

1. Go to [api.slack.com/apps](https://api.slack.com/apps) → **Create New App → From scratch**.
2. Left sidebar → **Incoming Webhooks** → toggle **On** → **Add New Webhook** → choose channel.
3. Copy the generated URL (`https://hooks.slack.com/services/…`).
4. Paste into **Stores → Config → Carpet Avenue → Notification → Slack → Webhook URL**.
5. Set `Enabled = Yes` at both the master switch and channel level.
6. Flush config cache: `bin/magento cache:flush config`

### Sending Slack Messages

```php
// Simple text → Default channel
$this->slack->send('*Order placed* #100042 :tada:');

// Watcher alert
$this->slack->toWatcher()->send('Cron `import` finished in 3.2 s');

// Rich Block Kit message
$this->slack->toDefault()->sendBlocks([
    $this->slack->headerBlock('New Order Received'),
    $this->slack->fieldsBlock(['Order #' => '100042', 'Total' => '€129.00']),
    $this->slack->actionsBlock([['text' => 'View Order', 'url' => $url, 'action_id' => 'view', 'style' => 'primary']]),
]);
```

### Slack Troubleshooting

| Symptom                 | Likely Cause           | Fix                                         |
|-------------------------|------------------------|---------------------------------------------|
| No message sent         | Master switch off      | Enable **Notification → Slack → Enabled**   |
| Specific channel silent | Channel-level disabled | Enable the channel group                    |
| HTTP 4xx in logs        | Wrong/expired webhook  | Regenerate at api.slack.com/apps            |
| `channel_not_found`     | Channel name typo      | Check name; run `/invite @BotName` in Slack |
| Config still stale      | Cache not flushed      | `bin/magento cache:flush config`            |

---

## Developer

### Architecture

```
CarpetAvenue_Core/
├── Block/
├── Logger/
│   └── Logger.php            # Shared dynamic logger — used by all CarpetAvenue modules
│   ├── Adminhtml/Doc/        # Documentation hub + generic viewer blocks
│   ├── Config/               # Config field renderers
│   ├── Header/               # Admin header blocks
│   └── System/               # System config fieldset hint blocks
├── Controller/Adminhtml/Doc/ # Doc hub (Index) + generic viewer (View) controllers
├── Helper/
│   ├── Performance.php       # Execution time measurement
│   ├── Product.php           # Product image helpers
│   ├── Slack.php             # Slack sender — Block Kit fluent API
│   ├── SlackConfig.php       # Reads slack system config values
│   ├── SysConfig.php         # Generic store-scoped config getter
│   ├── UpsertBulk.php        # Bulk MySQL UPSERT helper
│   └── URL.php               # Store URL helpers
├── etc/adminhtml/
│   ├── menu.xml              # Top-level CarpetAvenue menu + Miscellaneous > Documentation
│   ├── routes.xml            # frontName: carpetavenue
│   └── system.xml            # Config fields
├── etc/
│   ├── acl.xml
│   └── module.xml
└── view/adminhtml/
    ├── layout/               # carpetavenue_doc_index.xml, carpetavenue_doc_view.xml
    └── templates/doc/        # index.phtml (hub), viewer.phtml (two-tab MD renderer)
```

### Documentation System

The Documentation Hub reads `README.md` from any module and splits it at `## Manual` / `## Developer` headings into two tabs. To add a new module's docs:

1. Ensure the module's `README.md` has `## Manual` and `## Developer` sections.
2. Add one entry to `$chapters` in `Core/view/adminhtml/templates/doc/index.phtml`.
3. No new controller, block, or layout needed — the generic viewer handles it.

URL pattern: `carpetavenue/doc/view?module=CarpetAvenue_Core[&tab=developer]`

### Helper Reference

#### `SysConfig.php`
```php
$this->sysConfig->getConfig('general/store_information/name'); // store-scoped value
```

#### `URL.php`
```php
$this->url->getBaseUrl();                               // https://carpetavenue.com/
$this->url->getMediaUrl();                              // …/pub/media/
$this->url->getUrl('catalog/product/view', ['id'=>42]); // full secure URL
```

#### `Performance.php`
```php
$start   = $this->performance->executionTime();          // start
$elapsed = $this->performance->executionTime($start);    // ms elapsed
$sec     = $this->performance->executionTime($start,'s');// seconds
```

#### `Product.php`
```php
$map = $this->product->getProductSecondImage([42, 55]); // entity_id → image URL map
$this->product->isEnableHoverImage();                   // bool
```

#### `UpsertBulk.php`
```php
$this->upsertBulk->upsert($rows, 'cataloginventory_stock_item');
// Single INSERT … ON DUPLICATE KEY UPDATE for the full batch
```

#### `SlackConfig.php`
```php
$this->slackConfig->isEnabled();           // bool — master switch
$cfg = $this->slackConfig->defaultChannel();
$cfg = $this->slackConfig->watcherChannel();
// $cfg = ['enabled', 'bot_username', 'channel', 'webhook_url', 'icon_url']
```
Config path pattern: `carpetavenue_notification/slack_communication/{channel}/{field}`

#### `Slack.php` — Block Kit factory

| Method                                | Description                              |
|---------------------------------------|------------------------------------------|
| `toDefault()` / `toWatcher()`         | Target channel (returns clone)           |
| `withWebhook(url)`                    | One-off webhook override (returns clone) |
| `send(text, extraBlocks)`             | mrkdwn text + optional extra blocks      |
| `sendBlocks(blocks, fallback)`        | Full Block Kit payload                   |
| `sendAttachment(text, color, fields)` | Legacy coloured attachment               |
| `sectionBlock(text)`                  | mrkdwn section                           |
| `headerBlock(text)`                   | Large bold title                         |
| `dividerBlock()`                      | Horizontal rule                          |
| `contextBlock(lines[])`               | Small grey context text                  |
| `fieldsBlock(key=>value[])`           | Two-column bold key/value                |
| `actionsBlock(buttons[])`             | Link button row                          |

### ACL Resources

```
CarpetAvenue_Core::carpetavenue
├── CarpetAvenue_Core::core
│   ├── CarpetAvenue_Core::custom_code
│   ├── CarpetAvenue_Core::notification
│   └── CarpetAvenue_Core::documentation
└── CarpetAvenue_Core::miscellaneous
    └── CarpetAvenue_Core::miscellaneous_documentation
```

---

#### `Logger/Logger.php` — Dynamic shared logger

All CarpetAvenue logs land under `var/log/carpetavenue/`. Three parameters control where and how:

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$logDir` | `string` | `''` | Sub-path under `var/log/carpetavenue/`. Empty = root. |
| `$logFile` | `string` | `'carpetavenue'` | Filename prefix (no extension). |
| `$dayWise` | `bool` | `true` | `true` = new file per day · `false` = single growing file |

**Log path formula:**
- Day-wise: `var/log/carpetavenue/{logDir}/{logFile}_YYYY-MM-DD.log`
- Full file: `var/log/carpetavenue/{logDir}/{logFile}.log`

**Methods:** `error()` · `warning()` · `info()` · `debug()` · `log()` (alias for `info`)
All methods accept `string|array|object` — arrays/objects are serialised via `print_r`.

---

##### Step 1 — Create logger classes in your module

One class per logger. Each extends `CoreLogger` and hard-codes its own `logDir`, `logFile`, and `dayWise`.

```php
// app/code/CarpetAvenue/Orderify/Logger/Logger.php
// Global module logger — all general Orderify logs
namespace CarpetAvenue\Orderify\Logger;

use CarpetAvenue\Core\Logger\Logger as CoreLogger;

class Logger extends CoreLogger
{
    public function __construct()
    {
        parent::__construct(
            logDir:  'orderify',
            logFile: 'orderify',
            dayWise: true
            // → var/log/carpetavenue/orderify/orderify_YYYY-MM-DD.log
        );
    }
}
```

```php
// app/code/CarpetAvenue/Orderify/Logger/QueueLogger.php
// Service-wise — queue only, day-wise rotation
namespace CarpetAvenue\Orderify\Logger;

use CarpetAvenue\Core\Logger\Logger as CoreLogger;

class QueueLogger extends CoreLogger
{
    public function __construct()
    {
        parent::__construct(
            logDir:  'orderify/queue',
            logFile: 'queue',
            dayWise: true
            // → var/log/carpetavenue/orderify/queue/queue_YYYY-MM-DD.log
        );
    }
}
```

```php
// app/code/CarpetAvenue/Orderify/Logger/IndexerLogger.php
// Service-wise — indexer only, single growing file (no day rotation)
namespace CarpetAvenue\Orderify\Logger;

use CarpetAvenue\Core\Logger\Logger as CoreLogger;

class IndexerLogger extends CoreLogger
{
    public function __construct()
    {
        parent::__construct(
            logDir:  'orderify/indexer',
            logFile: 'indexer',
            dayWise: false
            // → var/log/carpetavenue/orderify/indexer/indexer.log
        );
    }
}
```

---

##### Step 2 — Inject into any class via constructor

```php
// app/code/CarpetAvenue/Orderify/Service/QueueService.php
namespace CarpetAvenue\Orderify\Service;

use CarpetAvenue\Orderify\Logger\QueueLogger;

class QueueService
{
    public function __construct(
        private readonly QueueLogger $logger
    ) {}

    public function run(): void
    {
        $this->logger->info('Queue started');
        $this->logger->error(['code' => 500, 'msg' => 'Job failed']);
        $this->logger->debug('Processed 42 items');
    }
}
```

```php
// app/code/CarpetAvenue/Orderify/Cron/IndexerCron.php
namespace CarpetAvenue\Orderify\Cron;

use CarpetAvenue\Orderify\Logger\IndexerLogger;

class IndexerCron
{
    public function __construct(
        private readonly IndexerLogger $logger
    ) {}

    public function execute(): void
    {
        $this->logger->info('Indexer cron started');
    }
}
```

Magento ObjectManager resolves `QueueLogger` / `IndexerLogger` automatically — no `di.xml` needed.

---

##### Path reference

| Logger class | `logDir` | `logFile` | `dayWise` | Resulting path |
|---|---|---|---|---|
| `Logger` | `'orderify'` | `'orderify'` | `true` | `var/log/carpetavenue/orderify/orderify_YYYY-MM-DD.log` |
| `QueueLogger` | `'orderify/queue'` | `'queue'` | `true` | `var/log/carpetavenue/orderify/queue/queue_YYYY-MM-DD.log` |
| `IndexerLogger` | `'orderify/indexer'` | `'indexer'` | `false` | `var/log/carpetavenue/orderify/indexer/indexer.log` |

---

### Dependencies

| Module            | Reason                                      |
|-------------------|---------------------------------------------|
| `Magento_Backend` | Admin panel integration                     |
| `Magento_Catalog` | Product media config (`Helper/Product.php`) |
