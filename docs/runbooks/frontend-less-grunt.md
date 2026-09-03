# LESS with grunt + source maps (local dev)

**Use when** you're writing LESS for the storefront theme and want edits to
recompile on save, with Chrome DevTools naming the origin `.less` file and line
(`_extend.less:612`) instead of `styles-m.css:18085`. Magento's own server-side
compilation emits no source maps — grunt is the only way to get them.

Local dev only. Nothing here affects production or `setup:static-content:deploy`.

Theme is **`carpetavenue/rugs`**, locale **`ja_JP`**, container
**`carpetavenue-jp-phpfpm-1`**, base URL **`https://carpetavenue-jp.devs/`**.

## Prerequisite: the dev bind mounts must be active

Grunt runs *inside* the container and watches container files. `/var/www/html`
is a **named Docker volume** (`appdata`), not your host checkout, and there is no
Mutagen in this stack. Without bind mounts, host edits never reach the container
and `watch` sits idle forever — this is also why a `git pull` alone can leave the
storefront serving the old design.

`../compose.dev.yaml` declares the mounts. Confirm they're live:

```bash
docker inspect carpetavenue-jp-phpfpm-1 \
  --format '{{range .Mounts}}{{if eq .Type "bind"}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}{{end}}'
```

You want `src/app/code`, `src/app/design`, `src/app/etc`, `src/generated`,
`src/var`, `src/dev/tools/grunt/configs` and the three `.sample` mappings. If only
`nginx.conf` shows up, the containers predate the current `compose.dev.yaml`
(editing that file does **not** affect running containers — `docker restart`
re-reads nothing).

To activate them, **rescue the shadowed files first** — a bind mount hides
whatever the volume had at that path:

```bash
C=carpetavenue-jp-phpfpm-1; S=<repo>/src
docker cp "$C":/var/www/html/app/etc/.     "$S"/app/etc/      # env.php + 5 core files
docker cp "$C":/var/www/html/composer.json "$S"/composer.json
docker cp "$C":/var/www/html/composer.lock "$S"/composer.lock
docker cp "$C":/var/www/html/var           "$S"/
mkdir -p "$S"/generated
cd .. && bin/docker-compose up -d --force-recreate app phpfpm phpfpm-xdebug
bin/magento setup:di:compile          # generated/ is an empty host dir now
```

Why each one matters — all four have bitten this project:

- **`app/etc`** — the host tracks only `config.php`. Mounting it without the copy
  drops `env.php` and Magento loses its DB credentials. The site dies.
- **`composer.json` / `composer.lock`** — the container's copy carried
  `magentoj/language-ja_jp`, which had never been committed. Mounting the host's
  over it would silently drop the Japanese language pack on the next composer run.
- **`generated`, `var`** — absent on the host, so they mount as empty dirs.
  `bin/start` also refuses to boot while they're missing. `generated` is rebuilt
  by `di:compile`; `var` is copied out to preserve `var/composer_home`.
- **`nginx.conf`** — stock `compose.dev.yaml` maps `nginx.conf.sample` over it.
  This project customises `nginx.conf` (the built production image uses the same
  file), so the mapping is corrected to `./src/nginx.conf` in our copy. Don't let
  a docker-magento update revert it.

Once the mounts are live, **`bin/copytocontainer` is no longer needed** for
`app/code`, `app/design` or the grunt configs. Host edits are the container's
files.

## One-time setup

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && npm install --no-audit --no-fund"
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento deploy:mode:set developer"
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && npx grunt exec:rugs && npx grunt less:rugs"
```

`node_modules` lives container-side only (gitignored, and the host has no
`package.json` of its own — `compose.dev.yaml` maps `package.json.sample` in).

`exec:rugs` force-cleans, then publishes the theme's LESS into
`pub/static/frontend/carpetavenue/rugs/ja_JP/` — that's what the source maps
point at. `less:rugs` compiles `styles-m.css` + `styles-l.css` and writes the
matching `.css.map` beside them.

## Daily use

```bash
docker exec -it carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && npx grunt watch:rugsSrc"
```

Leave it running. Save any `.less` under
`app/design/frontend/carpetavenue/rugs/**` → full `exec:rugs` + `less:rugs` cycle
in ~3-4s. Verified: host edits do reach the container's inotify through the
macOS bind mount.

**Full-page cache has to be off** for a save to reach the browser:

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:disable full_page && php bin/magento cache:flush"
```

The page links `css/styles-m.css` under a `/static/version<ts>/` prefix even in
developer mode. The prefix comes from `pub/static/deployed_version.txt`, which
`clean:rugs` deletes and Magento rewrites on the next *uncached* render. With FPC
on, that render never happens: the cached HTML keeps the old prefix, the browser
keeps that URL in its disk cache, and grunt reports "2 stylesheets created" while
nothing changes on screen. `php bin/magento cache:flush` after each save is the
alternative. Re-enable with `cache:enable full_page` when done.

Hard-reload (⌘⇧R) if you still see the old CSS. Nothing else in `pub/static` is
disturbed by a save — see the `clean:rugs` note below.

## How the theme is wired into grunt

Four files, all of them things that have broken before:

- **`grunt-config.json`** → `{"themes": "dev/tools/grunt/configs/local-themes"}`.
  This is the only thing that points grunt at our theme list.
  `dev/tools/grunt/tools/files-router.js` swallows a parse error and silently
  falls back to stock `themes.js` (Magento blank + luma only), so an **empty or
  malformed `grunt-config.json` makes every `:rugs` target vanish** with no error
  message. The container gets this file from `grunt-config.json.sample` via
  `compose.dev.yaml`; the host's own `src/grunt-config.json` is unused and may be
  0 bytes.
- **`dev/tools/grunt/configs/local-themes.js`** — defines the `rugs` alias
  (`carpetavenue/rugs`, `ja_JP`). Filename is required verbatim.
- **`dev/tools/grunt/configs/watch.js`** — carries our `<name>Src` watch target
  (see below). The rest of the grunt configs are stock Adobe; `exec`, `less`,
  `clean` and `watch` auto-generate a target per theme alias, which is where
  `exec:rugs` / `less:rugs` / `clean:rugs` / `watch:rugs` come from for free.
- **`dev/tools/grunt/configs/clean.js`** — the per-theme `clean:<name>` target is
  narrowed to the published `.less` files plus their `var/view_preprocessed`
  mirror. Stock wiped `pub/static/frontend/carpetavenue/rugs/ja_JP/**` entirely,
  so a single LESS save deleted every published `.js`, image and font too, plus
  `pub/static/deployed_version.txt`, and only the LESS came back — `exec:<theme>`
  is `clean:<theme> && dev:source-theme:deploy --type=less`, nothing more.
  `requirejs-config.js` went with them, and that file is only rewritten during an
  *uncached* page render, so with full-page cache on every request 404'd it
  (served as `text/plain`, which the browser refuses) and the entire requirejs
  graph failed. Two parts of the target are **not** optional. `var/view_preprocessed`
  holds the preprocessed copies of `styles-m.less` and every `_extend.less`, and a
  republish reuses them if they survive. `pub/static/deployed_version.txt` is what
  moves the `/static/version<ts>/` prefix; keep it and the browser never refetches
  the recompiled CSS. Stock also listed `path.tmpLess`/`path.tmpSource` — Magento
  2.1 paths that match nothing in 2.4.

## Rules that are easy to get wrong

- **`watch:rugsSrc`, not `watch:rugs`.** `watch:rugs` (stock, auto-generated)
  watches only the *published copy* under `pub/static/.../css/source/`. Magento's
  asset publisher skips a file that already exists at the destination, so once
  `exec:rugs` has published a file, later edits under `app/design` are invisible
  to it. `rugsSrc` watches the real sources and runs `exec:rugs` + `less:rugs` on
  every save. It derives its path from the theme entry, so renaming the theme in
  `local-themes.js` is enough — nothing hardcodes a vendor.
- **Never bare `grunt watch`.** That starts every target, and two of them
  (`updater`, `reload`) both bind livereload port 35729:
  `Fatal error: Port 35729 is already in use`. Kill strays with
  `docker exec carpetavenue-jp-phpfpm-1 pkill -f grunt`.
- **Anything that wipes `pub/static`** (`setup:static-content:deploy -f`,
  deleting `pub/static/frontend`) drops the grunt build and its maps. Re-run
  `exec:rugs && less:rugs`.
- **Developer mode is required.** Back to the previous setup with
  `php bin/magento deploy:mode:set default`. Note that in `default` mode compiled
  CSS in `pub/static` is cached and is *not* recompiled when a source `.less`
  changes — that is the usual cause of "my CSS edit does nothing".
- **This workflow is `ja_JP`-only** — `local-themes.js` hardcodes the locale. It
  is currently the store's only locale; if another is ever added, it needs a
  manual `setup:static-content:deploy -f --area frontend --theme carpetavenue/rugs <locale>`
  after a LESS change, or that store silently keeps serving older CSS.
- **`setup:static-content:deploy -f` doesn't reliably force-recompile a locale
  already deployed once in the same session** — output can stay byte-identical
  after LESS edits, with no error. If a redeploy looks like it had no effect,
  delete the locale's output directory first:
  `rm -rf pub/static/frontend/carpetavenue/rugs/<locale>` then redeploy.

## Without grunt

If you only need a CSS change to show up and don't care about source maps, skip
all of the above — in `default` mode, delete the compiled output and let the next
request regenerate it (~2s):

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && \
  rm -rf pub/static/frontend/carpetavenue var/view_preprocessed/* pub/static/deployed_version.txt && \
  php bin/magento cache:clean block_html full_page"
```

Deleting `deployed_version.txt` bumps the `/static/version<ts>/` prefix, so the
browser can't serve a cached copy. Add `bin/copytocontainer app/design/...` first
if the bind mounts aren't active.

## Verify it works

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "tail -c 45 /var/www/html/pub/static/frontend/carpetavenue/rugs/ja_JP/css/styles-m.css"
# -> /*# sourceMappingURL=styles-m.css.map */

curl -sk -o /dev/null -w "%{http_code}\n" \
  https://carpetavenue-jp.devs/static/frontend/carpetavenue/rugs/ja_JP/css/source/_extend.less
# -> 200   (DevTools fetches the sources over this path)
```

In DevTools the Styles pane should then name the `.less` file. If it still names
`styles-m.css`, source maps are off in Settings → Preferences → Sources.

Nothing outside the theme's `.less` files should move on a save:

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "ls /var/www/html/pub/static/frontend/carpetavenue/rugs/ja_JP/requirejs-config.js"
curl -sk -o /dev/null -w "%{http_code}\n" \
  https://carpetavenue-jp.devs/static/frontend/carpetavenue/rugs/ja_JP/requirejs-config.js
# -> 200, with full_page cache enabled
```

## History

2026-09-03: `clean:rugs` narrowed (see above). Until then every LESS save wiped
the whole published theme dir — 421 paths, all published JS included — so the
workflow required `cache:disable full_page` and left the storefront's requirejs
graph dead between saves. Verified after the change with full-page cache on: two
consecutive edits compiled into `styles-m.css`, `requirejs-config.js` untouched,
`requirejs-config.js` and `.css.map` both 200. The first cut of that change also
dropped `deployed_version.txt` from the target and this document claimed
developer mode serves CSS without a version prefix — both wrong. It does, so the
browser kept the cached stylesheet and recompiles appeared to do nothing.
`deployed_version.txt` is back in the target, and the FPC-off instruction is back
too: deleting that file only moves the prefix on an *uncached* render, so with FPC
on the recompile still never reaches the browser. The original instruction was
right for a different reason (JS deletion) than the one that still applies.

Before 2026-08-30 this document described theme `carpient/rugs` at locale
`en_US` in container `carpient-phpfpm-1`, and claimed `carpetavenue/rugs` was "a
leftover DB row with no files" — the reverse of the truth after the rename.
Commit `0b1649f` had also reverted `watch.js`, `exec.js` and `clean.js` to stock
and emptied `grunt-config.json`, removing every `:rugs` target the old
instructions depended on. Grunt could not run at all as written.
