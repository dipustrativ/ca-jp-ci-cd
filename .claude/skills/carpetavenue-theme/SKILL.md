---
name: carpetavenue-theme
description: Frontend theme work on the CarpetAvenue Rugs storefront theme (app/design/frontend/carpetavenue/rugs, parent Magento/blank). Use for any LESS/CSS, layout XML, .phtml template, requirejs, or static-asset change to the storefront look. Covers the exact theme path, the grunt LESS watch loop, static-content:deploy, cache clean, and the FPM opcache / requirejs-config gotchas. Triggers on "theme", "LESS", "less styles", "layout xml", "phtml template", "storefront style", "grunt watch", "carpetavenue/rugs", "restyle", "fix the frontend look".
---

# CarpetAvenue Rugs Theme Flow

Repeatable flow for editing and deploying the storefront theme. Respond in caveman mode.

## 0. Theme facts

- **Active theme:** `app/design/frontend/carpetavenue/rugs` — title "CarpetAvenue
  Rugs", parent `Magento/blank`, registered `frontend/carpetavenue/rugs`.
- **`app/design/frontend/carpient/rugs` is the LEGACY codename theme.** Do NOT
  edit it unless a DB check proves it is the active one. CLAUDE.md still names
  `carpient/rugs` in old commands — treat that as stale; prefer `carpetavenue/rugs`.
- **Verify active theme before deploying** (containers must be up):
  ```bash
  docker exec carpient-db-1 mariadb -umagento -pmagento magento -N -B -e \
    "select t.theme_path from theme t join core_config_data c on c.value=t.theme_id where c.path='design/theme/theme_id';"
  ```
  If containers are down: `docker ps --format '{{.Names}}' | grep carpient`.
  No containers → say so, edit files only, don't claim a render is verified.

## 1. Edit

- LESS: `web/css/source/`, `Magento_*/web/css/source/`. Layout: `*/layout/*.xml`.
  Templates: `*/templates/*.phtml`. Match the file you touch (indent, naming).
- Prefer ViewModel + template over block-class edits. No inline styles in phtml.
- Layout gotcha (this theme): `catalog_category_view.xml` carries
  `layout="2columns-left"` — only source of it on that handle. Don't drop it.

## 2. LESS work — grunt watch (source maps, no redeploy per save)

```bash
docker exec -it carpient-phpfpm-1 sh -c "cd /var/www/html && npx grunt watch:rugs"
```

- `watch:rugs`, **never bare `grunt watch`** (two targets fight livereload 35729).
- One-time setup: developer mode + `npm install` + `grunt exec:rugs` in container.
  Full setup/gotchas: `docs/runbooks/frontend-less-grunt.md`.

## 3. Deploy static assets (layout / phtml / requirejs / non-watch CSS)

Static assets do NOT regenerate reliably on the fly (requirejs-config.js 404s).
After any layout/template/js change:

```bash
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento setup:static-content:deploy -f --area frontend --theme carpetavenue/rugs en_US"
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:clean full_page block_html layout"
```

(Swap `carpetavenue/rugs` for whatever step 0 verified is active.)

## 4. Gotchas — do not skip

- **FPM opcache freezes bytecode** (`opcache.validate_timestamps=0`,
  `enable_cli=0`). Edited PHP/phtml does NOT take effect in browser until:
  ```bash
  docker restart carpient-phpfpm-1 carpient-phpfpm-xdebug-1
  ```
  `cache:flush` is NOT enough. Never call a browser fix verified from CLI only.
- Self-signed cert — always `curl -sk`. Base URL differs per machine
  (`carpient.devs` vs `carpient.test`); resolve, don't hardcode:
  ```bash
  docker exec carpient-db-1 mariadb -umagento -pmagento magento -N -B \
    -e "select value from core_config_data where path='web/secure/base_url';"
  ```

## 5. Verify render

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new \
  --disable-gpu --ignore-certificate-errors --dump-dom <base_url_from_step_4>
```

## 6. Ship

Hand off to the `ship-pr` skill / `strativ-git-conventions`. No `Co-Authored-By:
Claude` trailer in this repo (per CLAUDE.md).
