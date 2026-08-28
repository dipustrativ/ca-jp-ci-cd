#!/bin/sh
# Generate app/etc/env.php from template at container startup.
# Template reads all config from env vars (getenv()) — no secrets baked in image.

if [ "${DEBUG_ENV}" = "true" ]; then
  echo "=== [entrypoint] ENV DEBUG ==="
  echo "DB_HOST=${DB_HOST}"
  echo "DB_NAME=${DB_NAME}"
  echo "DB_USER=${DB_USER}"
  echo "VALKEY_HOST=${VALKEY_HOST}"
  echo "VALKEY_PORT=${VALKEY_PORT}"
  echo "RABBITMQ_HOST=${RABBITMQ_HOST}"
  echo "RABBITMQ_PORT=${RABBITMQ_PORT}"
  echo "RABBITMQ_USER=${RABBITMQ_USER}"
  echo "OPENSEARCH_HOST=${OPENSEARCH_HOST}"
  echo "OPENSEARCH_PORT=${OPENSEARCH_PORT}"
  echo "DOMAIN=${DOMAIN}"
  echo "IMAGE_TAG=${IMAGE_TAG}"
  echo "=============================="
fi

cp /var/www/html/docker/env.php.template /var/www/html/app/etc/env.php

# Snapshot the container env for cron jobs. crond does NOT pass the container
# environment to the commands it spawns, so `getenv('DB_HOST')` in env.php
# returns false there and every value silently falls back to the template
# defaults (DB_HOST -> "db" -> "getaddrinfo for db failed", empty
# MAGE_CRYPT_KEY, etc). /etc/cron.d/magento-cron sources this file first.
# Written on every start so it always matches the current environment.
export -p > /etc/magento-env.sh
chown root:www-data /etc/magento-env.sh
chmod 0640 /etc/magento-env.sh

exec "$@"
