# ISSUE-001 — `ScopeCodeResolver` null array offset (PHP 8.5)

| Field | Value |
|---|---|
| **Status** | 🔴 OPEN — unfixed upstream |
| **First observed** | 2026-08-08 |
| **Last re-checked** | 2026-08-08 |
| **Affects** | `magento/product-community-edition` 2.4.9, `magento/framework` 103.0.9, `magento/module-theme` 101.1.9 |
| **Requires** | PHP 8.5+ (not reproducible on PHP ≤ 8.4) |
| **Origin** | Adobe/Magento core — **not** CarpetAvenue code, not the `carpetavenue/rugs` theme |
| **Fix applied here** | Workaround only — CLI (`config:set`). Core defect left unpatched. |
| **Upstream ticket** | None linked yet. Search `magento/magento2` for `ScopeCodeResolver` + `null array offset` before each upgrade. |

---

## 1. Symptom

Opening **Admin → Content → Design → Configuration → Edit** on the **Global (Default)**
row throws instead of rendering the form:

```text
Deprecated Functionality: Using null as an array offset is deprecated, use an empty string instead
in /var/www/html/vendor/magento/framework/App/Config/ScopeCodeResolver.php on line 62
```

Repro URL:

```
/admin/theme/design_config/edit/scope/default/key/<form_key_hash>/
```

Entry point in the stack trace:

```
#1 Magento\Framework\App\Config\ScopeCodeResolver->resolve()
   called at [vendor/magento/module-theme/Model/Design/Config/DataProvider.php:117]
#2 Magento\Theme\Model\Design\Config\DataProvider->getMeta()
...
#15 Magento\Backend\Model\View\Result\Page->setActiveMenu()
#16 Magento\Theme\Controller\Adminhtml\Design\Config\Edit->execute()
```

---

## 2. Root cause (verified against on-disk 2.4.9 source)

### 2.1 Caller passes `null`

`vendor/magento/module-theme/Model/Design/Config/DataProvider.php:110-119`

```php
$request = $this->getRequest()->getParams();
if (!isset($request['scope'])) {
    return $meta;
}

$scope = $request['scope'];                                     // 'default'
$scopeCode = $this->getScopeCodeResolver()->resolve(
    $scope,
    isset($request['scope_id']) ? $request['scope_id'] : null   // null — the Global row has no scope_id
);
```

The Global/Default design-config row carries **no `scope_id`** in the URL, so `$scopeCode`
is literally `null`. Website and Store View rows pass a real id and are unaffected.

### 2.2 `null` survives to the array write

`vendor/magento/framework/App/Config/ScopeCodeResolver.php:41-65`

```php
41:    public function resolve($scopeType, $scopeCode)
42:    {
43:        if (isset($scopeCode, $this->resolvedScopeCodes[$scopeType][$scopeCode])) {
44:            return $this->resolvedScopeCodes[$scopeType][$scopeCode];   // guarded — null short-circuits
45:        }
46:
47:        if ($scopeType !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
48:            $scopeResolver = $this->scopeResolverPool->get($scopeType);
49:            $resolverScopeCode = $scopeResolver->getScope($scopeCode);
50:        } else {
51:            $resolverScopeCode = $scopeCode;        // 'default' branch → stays null
52:        }
53:
54:        if ($resolverScopeCode instanceof ScopeInterface) {
55:            $resolverScopeCode = $resolverScopeCode->getCode();
56:        }
57:
58:        if ($scopeCode === null) {
59:            $scopeCode = $resolverScopeCode;        // null = null — no-op, does NOT rescue
60:        }
61:
62:        $this->resolvedScopeCodes[$scopeType][$scopeCode] = $resolverScopeCode;   // ← $arr[null]
63:
64:        return $resolverScopeCode;
65:    }
```

Line 43 defends the **read** with `isset($scopeCode, ...)`. Line 62 has no equivalent
guard on the **write**. For `scopeType = 'default'` the null-rescue at 58-60 is a
tautology, so line 62 executes `$array[null] = …`.

### 2.3 PHP 8.5 deprecated that

`$array[null]` has always silently coerced to `$array[""]`. PHP 8.5 emits `E_DEPRECATED`
for it. Confirmed in this container:

```console
$ docker exec carpient-phpfpm-1 php -r 'error_reporting(E_ALL); $a=[]; $a[null]=1;'
Deprecated: Using null as an array offset is deprecated, use an empty string instead in Command line code on line 1

$ docker exec carpient-phpfpm-1 php -r 'echo PHP_VERSION;'
8.5.6
```

This is why the page worked on PHP 8.3/8.4 and broke on the 8.5 upgrade.
`magento/framework` 103.0.9 declares `"php": "~8.3.0||~8.4.0||~8.5.0"` — 8.5 is claimed
as supported, so this is a genuine upstream gap, not an unsupported-platform problem.

### 2.4 Why a deprecation becomes a fatal

`vendor/magento/framework/App/Bootstrap.php:256-262` registers the handler
**unconditionally, in every deploy mode**:

```php
public function run(AppInterface $application)
{
    try {
        try {
            \Magento\Framework\Profiler::start('magento');
            $this->initErrorHandler();      // ← always
```

`vendor/magento/framework/App/ErrorHandler.php:45-60` converts anything still in the
`error_reporting()` mask into a thrown `\Exception`:

```php
$errorNo = $errorNo & error_reporting();
if ($errorNo == 0) {
    return false;
}
$msg = isset($this->errorPhrases[$errorNo]) ? $this->errorPhrases[$errorNo] : "Unknown error ({$errorNo})";
$msg .= ": {$errorStr} in {$errorFile} on line {$errorLine}";
throw new \Exception($msg);
```

`E_DEPRECATED => 'Deprecated Functionality'` is at `ErrorHandler.php:32` — that string is
the prefix on the reported message.

**Correction to a common assumption:** this is *not* developer-mode-only.
This install runs `MAGE_MODE=default` (`app/etc/env.php`) and still fatals, because no
ini file sets `error_reporting` (`php -i` → `error_reporting => no value`), so PHP's
default `E_ALL` applies. Switching to production mode does **not** help — the exception is
still thrown, production only changes how the resulting error page looks.

---

## 3. Confirmed NOT caused by local code

```console
$ grep -rlE "ScopeCodeResolver|Design\\\\Config\\\\DataProvider|design_config_form" app/code app/design
(no matches)
```

No CarpetAvenue / IXPF / Evalent module, no plugin, no preference, and no
`carpetavenue/rugs` theme file touches `ScopeCodeResolver` or the Theme design-config
DataProvider. Local code is exonerated.

---

## 4. Fix chosen for this project — CLI (`config:set`)

**Chosen deliberately over patching vendor.** Understand the trade-off before relying on it:

> This does **not** fix the deprecation. The admin Design Configuration page for the
> Global scope stays broken. It routes around the broken page by writing the same
> `core_config_data` rows the form would have written.

### 4.1 Read current state

```bash
# effective value at default scope
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento config:show design/theme/theme_id"

# theme_id lookup
docker exec carpient-db-1 mariadb -umagento -pmagento magento -N -B \
  -e "select theme_id, theme_path, theme_title from theme where area='frontend';"
```

Values on the **local dev** install as of 2026-08-08:

| theme_id | theme_path | title |
|---|---|---|
| 1 | `Magento/blank` | Magento Blank |
| 3 | `Magento/luma` | Magento Luma |
| 4 | `carpetavenue/rugs` | CarpetAvenue Rugs |
| 5 | `carpient/rugs` | Carpient Rugs |

Existing `design/theme/theme_id` rows: `default/0 → 4`, `websites/1 → 5`.

**IDs differ per environment.** Production (2026-08-22) has the same themes at
`6` = `carpient/rugs` (the active one, set at default scope only) and `7` =
`carpetavenue/rugs` (assigned nowhere, no files on disk). Re-read the IDs from
the target database; never carry one across.

### 4.2 Write

Scope codes on this install: website `base` (id 1); stores `en`, `de`, `es`, `fr`, `it`,
`hu`, `nl`, `pl`, `pt`, `fi`.

```bash
# global / default scope
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento config:set design/theme/theme_id 4"

# website scope
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento config:set --scope=websites --scope-code=base design/theme/theme_id 4"

# store view scope
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento config:set --scope=stores --scope-code=en design/theme/theme_id 4"
```

### 4.3 Mandatory follow-up — `config:set` skips the design index

The admin form saves through `Magento\Theme\Model\DesignConfigRepository`, which updates
both `core_config_data` **and** the `design_config_grid` index. `config:set` writes only
`core_config_data`, so the admin grid shows stale themes until reindexed
(`indexer id="design_config_grid"` — `vendor/magento/module-theme/etc/indexer.xml:10`).

```bash
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento indexer:reindex design_config_grid"
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush"
docker restart carpient-phpfpm-1 carpient-phpfpm-xdebug-1
```

### 4.4 Other design settings reachable the same way

The whole Design Configuration form is plain config paths — anything the Global row would
have edited can be set by CLI:

```
design/theme/theme_id
design/head/{default_title,title_prefix,title_suffix,default_description,default_keywords,includes,shortcut_icon,demonotice}
design/header/{logo_src,logo_width,logo_height,logo_alt,welcome,translate_title}
design/footer/{copyright,absolute_footer,report_bugs}
design/pagination/{pagination_frame,pagination_frame_skip,anchor_text_for_previous,anchor_text_for_next}
design/watermark/{image_size,image_imageOpacity,image_position, …}
design/search_engine_robots/{default_robots,custom_instructions}
design/email/{logo,logo_alt,logo_width,logo_height,header_template,footer_template}
```

Verify any path before use:

```bash
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento config:show design/"
```

---

## 5. Alternatives NOT taken (recorded for when the CLI route stops being enough)

### 5.1 Composer patch — the correct fix

Requires `cweagans/composer-patches`, which is **not** currently installed and not in
`composer.json`. Without patch tooling any hand edit to `vendor/` is destroyed by the next
`composer install`.

Correct diff against 2.4.9 / framework 103.0.9:

```diff
--- a/vendor/magento/framework/App/Config/ScopeCodeResolver.php
+++ b/vendor/magento/framework/App/Config/ScopeCodeResolver.php
@@ -55,7 +55,9 @@
         if ($scopeCode === null) {
             $scopeCode = $resolverScopeCode;
         }
 
-        $this->resolvedScopeCodes[$scopeType][$scopeCode] = $resolverScopeCode;
+        if ($scopeCode !== null) {
+            $this->resolvedScopeCodes[$scopeType][$scopeCode] = $resolverScopeCode;
+        }
 
         return $resolverScopeCode;
```

Skipping the write when `$scopeCode` is still null loses nothing: the cache read at line 43
is already gated on `isset($scopeCode, …)`, so a null-keyed entry could never be read back.

> ⚠️ Any patch found online that targets `$this->codes[$scopeType][$scopeCode]` or inserts
> `$scopeCode = $scopeCode ?? '';` at the top of `resolve()` was written against an older
> Magento. 2.4.9 uses `$this->resolvedScopeCodes` and a different method body — such a
> patch will not apply. Also note `composer patch:apply` only exists in
> `cweagans/composer-patches` v2; v1 applies patches during `composer install`/`update`.

### 5.2 DI plugin — rejected

An `around` plugin on `ScopeCodeResolver::resolve()` could return early for the
`default` + `null` case. Rejected: `resolve()` sits on the scoped-config read path and is
called constantly. Adding an interceptor there costs more in steady-state request time
than the bug costs.

### 5.3 Muting `E_DEPRECATED` — rejected

Setting `error_reporting = E_ALL & ~E_DEPRECATED` in
`/usr/local/etc/php/conf.d/zz-magento.ini` would silence it, but also hides every other
PHP 8.5 deprecation across the codebase during the 8.5 migration. Not worth it.

---

## 6. Re-check procedure after a Magento upgrade

Run all four. The issue is fixed only if **step 1 shows a guard** or **step 4 renders**.

```bash
# 1. is the write still unguarded?
docker exec carpient-phpfpm-1 sh -c \
  "grep -n 'resolvedScopeCodes\[\$scopeType\]\[\$scopeCode\] =' /var/www/html/vendor/magento/framework/App/Config/ScopeCodeResolver.php"
#    STILL BROKEN if the assignment is at top level of resolve()
#    FIXED      if wrapped in a null check, or if $scopeCode is coerced to '' before it

# 2. does the caller still pass null?
docker exec carpient-phpfpm-1 sh -c \
  "grep -n -A4 'getScopeCodeResolver()->resolve' /var/www/html/vendor/magento/module-theme/Model/Design/Config/DataProvider.php"
#    FIXED if the null fallback became '' (e.g. \$request['scope_id'] ?? '')

# 3. version check — record these in the table at the top of this file
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php -r '\$l=json_decode(file_get_contents(\"composer.lock\"),true);
foreach(\$l[\"packages\"] as \$p){ if(in_array(\$p[\"name\"],[\"magento/product-community-edition\",\"magento/framework\",\"magento/module-theme\"])) echo \$p[\"name\"],\" \",\$p[\"version\"],PHP_EOL; }'"

# 4. functional check — must return 200 and contain the design form, not an error report
#    (log in to admin first; substitute this machine's base_url)
open "https://carpient.devs/admin/theme/design_config/"
#    then click Edit on the Global row
```

If steps 1–2 show the guard landed: flip **Status** to 🟢 FIXED, record the version that
fixed it, and delete the CLI workaround note from any runbook that references it.
If still broken: bump **Last re-checked** and move on.

---

## 7. Verification log

| Date | Magento | PHP | Result |
|---|---|---|---|
| 2026-08-08 | 2.4.9 / framework 103.0.9 | 8.5.6 | 🔴 Reproduced. Root cause confirmed at `ScopeCodeResolver.php:62`. Local code cleared. CLI workaround documented, not yet exercised. |

All findings above were produced by read-only inspection of the running container
(`sed`/`grep` on `vendor/`, `SELECT` on the DB, a standalone `php -r` probe). No fix was
applied, no cache flushed, no config written.
