# LESS with grunt + source maps (local dev)

Compiles the theme's LESS with grunt so Chrome DevTools shows the **origin
`.less` file and line** (`_carpient_header.less:212`) instead of
`styles-m.css:11841`. Magento's own server-side compilation emits no source
maps — grunt is the only way to get them.

Local dev only. Nothing here affects production or `setup:static-content:deploy`.

## One-time setup

Already committed: `Gruntfile.js`, `package.json`,
`dev/tools/grunt/configs/local-themes.js`. On a fresh checkout / rebuilt
container you only need the install:

```bash
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && npm install --no-audit --no-fund"
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento deploy:mode:set developer"
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && npx grunt exec:rugs && npx grunt less:rugs"
```

`exec:rugs` symlinks the theme's LESS sources into
`pub/static/frontend/carpient/rugs/en_US/` (that's what the source maps point
at); `less:rugs` compiles `styles-m.css` + `styles-l.css` and writes the
matching `.css.map` next to them.

## Daily use

```bash
docker exec -it carpient-phpfpm-1 sh -c "cd /var/www/html && npx grunt watch:rugsSrc"
```

Leave it running. Save any `.less` under
`app/design/frontend/carpient/rugs/**` → recompiles in a few seconds. Hard-reload
the page (⌘⇧R) — the `/static/version<ts>/` URL doesn't change, so a soft reload
can serve the cached CSS.

**Use `watch:rugsSrc`, not `watch:rugs`.** `watch:rugs` only watches the
*published copy* under `pub/static/.../css/source/`, not your actual source
files. Magento's asset publisher (`dev:source-theme:deploy`, run by
`exec:rugs`) skips republishing a file that already exists at the
destination — that's why `exec:rugs` force-cleans first. So once a file is
published, further edits under `app/design` are invisible to `watch:rugs`
until someone manually reruns `exec:rugs`. `watch:rugsSrc` (defined in
`dev/tools/grunt/configs/watch.js`) watches the real source and runs
`exec:rugs` + `less:rugs` on every change, so this isn't manual anymore.

## Rules that are easy to get wrong

- **`watch:rugs`, never bare `watch`.** Plain `grunt watch` starts every target,
  and two of them (`updater`, `reload`) both bind livereload port 35729:
  `Fatal error: Port 35729 is already in use by another process.` Kill strays with
  `docker exec carpient-phpfpm-1 pkill -f grunt`.
- **Everything runs inside `carpient-phpfpm-1`.** The host's `pub/static` is
  empty — static content only exists in the container, and container→host sync
  does not propagate back (`node_modules`, `package-lock.json` stay
  container-side; `node_modules` is gitignored anyway).
- **New `.less` file, or a new `@import`?** Re-run `npx grunt exec:rugs` first —
  `watch` only sees files that are already symlinked into `pub/static`.
- **Theme is `carpient/rugs`**, not `carpetavenue/rugs` — the latter is a
  leftover DB row with no files on disk. The grunt alias for it is `rugs`, defined in
  `dev/tools/grunt/configs/local-themes.js`. That filename is required verbatim —
  `Gruntfile.js` requires `dev/tools/grunt/configs/local-themes`.
- **Anything that wipes `pub/static`** (`setup:static-content:deploy -f`,
  `cache:clean`, deleting `pub/static/frontend`) drops the grunt build and its
  maps. Re-run `exec:rugs && less:rugs`.
- **Developer mode** is required. Back to the previous setup with
  `php bin/magento deploy:mode:set default`.
- **This whole grunt workflow is `en_US`-only** — `dev/tools/grunt/configs/local-themes.js`
  hardcodes `locale: 'en_US'`, so `watch:rugsSrc`/`exec:rugs`/`less:rugs` never
  touch `de_DE`/`es_ES`/`fr_FR`. After a LESS/theme change is confirmed correct
  on the English (`/en/`) storefront, other locales still need a manual
  `setup:static-content:deploy -f --area frontend --theme carpient/rugs <locale>`
  (space-separate multiple locales in one call) to pick it up — otherwise other
  stores silently keep serving whatever CSS/JS was last deployed for them,
  which can be from a completely different point in time than `en_US`.
- **`setup:static-content:deploy -f` doesn't always force-recompile a locale
  that's already been deployed once this session** — the CSS output can stay
  byte-identical (same size, same mtime) even after LESS source changes, no
  error shown. Confirmed by testing `en_US` (via grunt, always fresh) against
  `de_DE` (deployed once early, then silently stale after further edits) vs.
  `es_ES`/`fr_FR` (first deployed later in the same session and picked up
  every change with the same `-f` command). If a redeploy looks like it had no
  effect, delete that locale's output directory first, then deploy:
  `rm -rf pub/static/frontend/carpient/rugs/<locale>` then rerun
  `setup:static-content:deploy -f ... <locale>`.

## Verify it works

```bash
docker exec carpient-phpfpm-1 sh -c "tail -c 45 /var/www/html/pub/static/frontend/carpient/rugs/en_US/css/styles-m.css"
# -> /*# sourceMappingURL=styles-m.css.map */
curl -sk -o /dev/null -w "%{http_code}\n" https://carpient.devs/static/frontend/carpient/rugs/en_US/css/source/_carpient_header.less
# -> 200   (DevTools fetches the sources over this path; use carpient.test if that's your base_url)
```

In DevTools the Styles pane should then name the `.less` file. If it still names
`styles-m.css`, source maps are off in Settings → Preferences → Sources.
