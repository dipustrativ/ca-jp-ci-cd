# CarpetAvenue JP — Production server utilities

Ad-hoc ops recipes for the Dokploy production host. Volume/container names
below have Dokploy's app-id prefix (`carpetavenue-jp-app-fj43zo_...`) — yours may
differ; confirm with `docker volume ls` / `docker ps` first (see §2).

> ⚠️ Every literal here (`fj43zo` app-id, `34.14.82.141`, OrbStack paths) is from
> the `carpient` deployment. Substitute, don't paste.

---

## 1. Upload files into `magento_media` from local machine

`magento_media` is a named Docker volume (see `docker-compose.dokploy.yml`),
physically at:

```
/var/lib/docker/volumes/carpetavenue-jp-app-fj43zo_magento_media/_data
```

You SSH in as `magento` (not root), and that path is root-owned — so you can't
`scp` directly into it. Stage the upload in your home dir, then `sudo` it in.
Zip first for a single fast transfer instead of many small files.

**Step 1 — zip locally:**
```bash
cd /Users/shuvo/OrbStack/docker/containers/carpetavenue-jp-app-1/var/www/html/pub/media/catalog/product
zip -r /tmp/product-images.zip .
```

**Step 2 — scp zip to server staging dir (as `magento`, no sudo needed):**
```bash
scp /tmp/product-images.zip magento@34.14.82.141:/home/magento/media-upload.zip
```

**Step 3 — ssh in, extract, sudo-move into the volume, fix ownership:**
```bash
ssh -t magento@34.14.82.141 "
mkdir -p /home/magento/media-upload &&
unzip -o /home/magento/media-upload.zip -d /home/magento/media-upload &&
sudo cp -r /home/magento/media-upload/. /var/lib/docker/volumes/carpetavenue-jp-app-fj43zo_magento_media/_data/catalog/product/ &&
sudo chown -R www-data:www-data /var/lib/docker/volumes/carpetavenue-jp-app-fj43zo_magento_media/_data/catalog/product/ &&
rm -rf /home/magento/media-upload /home/magento/media-upload.zip
"
```
`-t` forces a pseudo-tty so the sudo password prompt works through the ssh
chain — you'll be asked for the `magento` user's sudo password.

`cp -r src/. dst/` (dot after `src`) copies *contents*, not a nested folder —
if your zip has a wrapper folder inside, `cd` into it before zipping, or
adjust the extracted path.

**Step 4 — cleanup local zip:**
```bash
rm /tmp/product-images.zip
```

**One-shot combined command (run from local machine):**
```bash
cd /Users/shuvo/OrbStack/docker/containers/carpetavenue-jp-app-1/var/www/html/pub/media/catalog/product && \
zip -r /tmp/product-images.zip . && \
scp /tmp/product-images.zip magento@34.14.82.141:/home/magento/media-upload.zip && \
ssh -t magento@34.14.82.141 "mkdir -p /home/magento/media-upload && unzip -o /home/magento/media-upload.zip -d /home/magento/media-upload && sudo cp -r /home/magento/media-upload/. /var/lib/docker/volumes/carpetavenue-jp-app-fj43zo_magento_media/_data/catalog/product/ && sudo chown -R www-data:www-data /var/lib/docker/volumes/carpetavenue-jp-app-fj43zo_magento_media/_data/catalog/product/ && rm -rf /home/magento/media-upload /home/magento/media-upload.zip" && \
rm /tmp/product-images.zip
```

⚠️ Direct filesystem write like this bypasses Magento's media gallery DB
records. Fine for a 1:1 file replace of existing images. If you need new
images to show up in the admin product image picker, use the admin upload UI
or the IXPF Syncer API instead, so the DB stays in sync.

---

## 2. Connect to the running `app` container / run `bin/magento`

There's no `bin/magento` on the host — Magento only exists inside the `app`
container. `container_name` isn't pinned in `docker-compose.dokploy.yml`, so
Dokploy auto-names it with the app-id prefix. Find it, then exec in:

```bash
ssh magento@34.14.82.141

# find the app container (php-fpm role)
docker ps --filter "name=app" --format "{{.Names}}\t{{.Image}}"
# e.g. carpetavenue-jp-app-fj43zo-app-1

# exec a magento CLI command
docker exec <app-container-name> sh -c "cd /var/www/html && php bin/magento cache:flush"

# interactive shell inside the container
docker exec -it <app-container-name> sh
```

Common follow-ups after a manual media upload (§1):
```bash
docker exec <app-container-name> sh -c "cd /var/www/html && php bin/magento cache:clean full_page block_html"
docker exec <app-container-name> sh -c "cd /var/www/html && php bin/magento catalog:images:resize"
```

`cron` and `static` services run the same `carpetavenue-jp` image if you ever need
to inspect those instead — same `docker ps --filter "name=cron"` pattern.
