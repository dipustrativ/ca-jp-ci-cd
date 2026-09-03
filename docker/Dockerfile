# syntax=docker/dockerfile:1
# ─────────────────────────────────────────────────────────────────────────────
# CarpetAvenue (Magento 2.4.9) production image
#
# Certified stack (Adobe Commerce 2.4.9): PHP 8.5, Composer 2.10, MySQL 8.4,
# OpenSearch 3, Valkey 9, RabbitMQ 4.2, nginx 1.30.
# ─────────────────────────────────────────────────────────────────────────────
ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-fpm-bookworm AS builder

# System build dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libxslt1-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libonig-dev \
    libsodium-dev \
    libgmp-dev \
    git \
    zip \
    unzip \
  && rm -rf /var/lib/apt/lists/*

# PHP extensions. igbinary FIRST so phpredis links against it
# (env.php cache uses 'serializer' => 'igbinary').
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
       gd bcmath intl soap xsl zip sockets pdo_mysql mbstring \
       sodium gmp pcntl posix ftp \
    && pecl install igbinary \
    && docker-php-ext-enable igbinary \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- STEP 1: Dependencies (cached unless composer files change) ---
COPY composer.json composer.lock ./

# auth.json via BuildKit secret — never lands in an image layer
RUN --mount=type=secret,id=composer_auth,target=/var/www/html/auth.json \
    composer install --no-dev --no-interaction --no-autoloader --prefer-dist

# Trim vendor weight not used at runtime: per-package tests, docs, VCS metadata.
# Safe — --no-dev already dropped dev packages; these dirs are never autoloaded.
RUN find vendor \( \
        -type d \( -iname 'Test' -o -iname 'Tests' -o -iname 'docs' -o -iname '.git' -o -iname 'phpunit' \) \
      \) -prune -exec rm -rf {} + 2>/dev/null || true

# --- STEP 2: App code + compilation ---
COPY . .
# Overwrite env.php with build variant (file cache, no network deps).
# Real env.php is rendered at container start by entrypoint.sh from
# docker/env.php.template (reads env vars via getenv()).
RUN cp docker/env.php.build app/etc/env.php
RUN composer dump-autoload --optimize

# setup:di:compile exits 255 flushing cache (no DB/Valkey in build) but succeeds.
# tee returns 0; assert on the success string instead of exit code.
RUN php -d memory_limit=4G bin/magento setup:di:compile 2>&1 | tee /tmp/di-compile.log \
    && grep -q "Generated code and dependency injection configuration successfully" /tmp/di-compile.log

# Locales baked into pub/static. EMPTY = let SCD auto-discover every locale in
# the DB (general/locale/code per store view + admin interface_locale) — the SCD
# env.php mounted below has real creds, so this needs no maintenance when a store
# view is added. Production mode does not build static on demand, so a missing
# locale is a broken storefront for that store view — not a fallback to en_US.
#
# Each locale adds its own copy of the theme's compiled assets, so this is the
# main lever on image size. Pin an explicit subset to shrink the image:
#   docker buildx build --build-arg STATIC_LOCALES="en_US de_DE sv_SE" ...
ARG STATIC_LOCALES=""

# Themes baked into pub/static. carpetavenue/rugs is the live frontend theme;
# Magento/luma is dead weight × every locale.
#
# --theme is an INCLUDE list (PackagePool::canDeployTheme), so Magento/backend
# MUST be listed too — production mode never builds static on demand, and
# without it the admin panel loads with no CSS/JS. Magento/blank still deploys
# as carpetavenue/rugs' parent; SCD keeps ancestors of deployed packages.
#
# SCD needs real DB creds — swap in SCD env.php, deploy static, restore build env.php.
# Single RUN: BuildKit snapshots final state only — real creds never persist in a layer.
# ${STATIC_LOCALES} is intentionally unquoted so it word-splits into separate args.
#
# The trailing `test`: if minify is off here but on at runtime, SCD writes
# require.js while the site asks for require.min.js — every generated asset 404s
# and the build log looks clean. Both flags are locked in app/etc/config.php so
# that can't drift; this fails the build if it ever does. docker/README.md §8.
RUN --mount=type=secret,id=env_php_scd \
    cp /run/secrets/env_php_scd app/etc/env.php && \
    php -d memory_limit=4G bin/magento setup:static-content:deploy -f \
        --theme carpetavenue/rugs --theme Magento/backend \
        ${STATIC_LOCALES} --jobs="$(nproc)" && \
    cp docker/env.php.build app/etc/env.php && \
    test -f pub/static/adminhtml/Magento/backend/en_US/requirejs/require.min.js && \
    test -f pub/static/adminhtml/Magento/backend/en_US/css/styles.min.css

# Strip everything the running site never reads. Belt-and-suspenders vs
# .dockerignore (BuildKit can reuse a cached COPY layer and leak these in):
#   dev/            test + build tooling            (~68M)
#   var/*           SCD/compile intermediates, regenerated at runtime (~53M)
#                   var/view_preprocessed is only safe to drop because
#                   env.php.template pins minify_html=0. With it ON this tree is
#                   BUILD OUTPUT: production resolves every .phtml into it and
#                   never regenerates it, so stripping it here empties every
#                   block on the live site. See docker/env.php.template.
#   *.sql, docs     one-off install + changelog cruft
# NOT docker/ — entrypoint.sh reads docker/env.php.template at container start.
# KEPT ON PURPOSE: n98-magerun2.phar (on-server debugging) and *.sample.
# var/import*: leftovers from local import/export runs that rode in via COPY
# (~22M). Magento recreates them on demand; nothing reads them at boot.
#
# The leading grep is the guard: flipping minify_html back to '1' without also
# keeping var/view_preprocessed fails the build here, loudly, instead of shipping
# an image whose every block renders empty.
RUN set -eux; \
    grep -q "'minify_html' => '0'," docker/env.php.template; \
    rm -rf dev phpserver base.sql CHANGELOG*.md \
           var/cache var/page_cache var/view_preprocessed var/tmp var/log var/report \
           var/import var/import_history var/importexport var/importexport_* var/*.csv

# Static-content strips — the single biggest lever on image size (~688M).
# Full evidence, caveats and a post-upgrade re-check procedure: docker/IMAGE-SIZE.md
#
# 1. Repoint the ONE cross-theme url() before deleting the parent theme. A
#    Mageplaza LESS @import emitted an absolute-from-pub/static path; the same
#    PNG already exists in rugs' own tree. Locale-agnostic on purpose.
# 2. Magento/blank (~530M): deployed only as carpetavenue/rugs' SCD ancestor. No
#    store view resolves to it — this install is 1 website (`ca_jp`) / 1 store
#    view (`jp`) with design/theme/theme_id at DEFAULT scope = 5 =
#    carpetavenue/rugs, and no website- or store-scope override. blank/en_US is
#    also a strict SUBSET of rugs/en_US. Fallback is flattened at deploy time;
#    runtime URLs name the active theme only.
#    ⚠️ theme_id is per-database — re-read it from the SCD DB after any restore,
#    never carry the number between environments.
# 3. *.map (~59M): source maps, DevTools-only. 37 of 39 sourceMappingURL
#    comments are already dangling in the shipped image.
# 4. hugerte (~99M): the ADMIN WYSIWYG, copied into the frontend theme by SCD
#    x every locale. 0 frontend layouts/templates require it; the mention in
#    requirejs-config.js and js/bundle/bundle1.js is a lazy RequireJS path map,
#    not the library. The adminhtml copy is untouched — the editor needs it.
RUN set -eux; \
    sed -i 's|\.\./\.\./\.\./\.\./\.\./frontend/Magento/blank/[a-z][a-z]_[A-Z][A-Z]/|../|g' \
        pub/static/frontend/carpetavenue/rugs/*/css/*.css; \
    ! grep -rq 'frontend/Magento/blank/' pub/static/frontend/carpetavenue; \
    rm -rf pub/static/frontend/Magento/blank; \
    find pub/static -name '*.map' -delete; \
    rm -rf pub/static/frontend/*/*/*/hugerte

# ─── Cross-locale deduplication: the single biggest win (~415M) ───────────────
# SCD writes a full copy of every asset per locale, and most assets are
# locale-independent (jquery, images, fonts, vendor JS). pub/static holds 510M
# of files but only ~78M of distinct content. Replacing the duplicates with
# hardlinks collapses it to 78M.
#
# MUST be the last step in this stage — anything that rewrites a static file
# afterwards would mutate every locale that shares the inode.
#
# --ignore-time is required: SCD stamps a different mtime per locale, and
# without it hardlink only finds 189M instead of 415M. mtime is not used for
# static cache-busting (that is pub/static/deployed_version.txt), and identical
# files sharing one Last-Modified is correct anyway.
#
# Mode and owner are deliberately NOT ignored (no --ignore-mode/--ignore-owner):
# that would buy ~53M more but could link an executable to a non-executable
# twin and silently drop the +x bit somewhere in vendor/bin.
#
# Verified end-to-end: BuildKit's COPY --from preserves hardlinks, including
# with --chown, and the `static` service's `cp -a` preserves them into the
# shared volume (GNU cp --preserve=all includes `links`), so the volume shrinks
# by the same amount. Evidence + re-check steps: docker/IMAGE-SIZE.md
#
# The check looks for any hardlinked file rather than naming one: it used to
# assert on jquery.js, which vanished the moment minification was turned on.
RUN set -eux; \
    apt-get update && apt-get install -y --no-install-recommends hardlink; \
    rm -rf /var/lib/apt/lists/*; \
    hardlink --ignore-time /var/www/html; \
    find pub/static -type f -links +1 -print -quit | grep -q .

# ─────────────────────────────────────────────────────────────────────────────
# FINAL STAGE (production runtime)
# Stay on bookworm (glibc) — Alpine musl is incompatible with the .so files above.
# ─────────────────────────────────────────────────────────────────────────────
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm-bookworm
WORKDIR /var/www/html

# Runtime shared libs + cron
RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype6 libjpeg62-turbo libpng16-16 libwebp7 libxslt1.1 \
    libzip4 libicu72 libxml2 libonig5 libsodium23 libgmp10 \
    cron \
  && rm -rf /var/lib/apt/lists/*

# PHP extensions + their ini from builder
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Compiled application. --chown sets ownership in the SAME layer as the copy;
# a separate `chown -R` would duplicate the whole tree into a new layer (~270MB).
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html

# PHP prod settings + FPM pool
COPY docker/php/php.ini     /usr/local/etc/php/conf.d/zz-magento.ini
COPY docker/php/php-cli.ini /usr/local/etc/php/conf.d/zz-magento-cli.ini
COPY docker/php/www.conf    /usr/local/etc/php-fpm.d/www.conf

# Cron table — cron service overrides CMD with `cron -f`
COPY docker/cron/magento-cron /etc/cron.d/magento-cron
RUN chmod 0644 /etc/cron.d/magento-cron

# Entrypoint: drops real env.php from template at container start
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Pre-create writable dirs (mount points for named volumes) owned by www-data.
# NO -R: the copied tree is already www-data from COPY --chown above; we only
# need ownership on these (possibly new, empty) leaf dirs. Cheap — no dup layer.
RUN set -eux; \
    for d in var/log var/report var/cache var/page_cache var/session pub/media pub/static; do \
      mkdir -p "/var/www/html/$d"; \
    done; \
    chown www-data:www-data \
      /var/www/html/var/log /var/www/html/var/report /var/www/html/var/cache \
      /var/www/html/var/page_cache /var/www/html/var/session \
      /var/www/html/pub/media /var/www/html/pub/static

ENTRYPOINT ["/entrypoint.sh"]
EXPOSE 9000
CMD ["php-fpm"]
