#!/usr/bin/env bash
#
# Apply local vendor patches idempotently.
#
# We do NOT use cweagans/patches: the composer mirror in this environment does
# not serve it (repo.packagist.org returns "no packages here"), so the plugin
# cannot be installed locally or during the production image build. Instead this
# script is wired into composer's post-install-cmd / post-update-cmd, so every
# `composer install`/`update` re-applies the patches against a freshly fetched
# vendor tree. It is safe to run repeatedly — a patch that is already applied is
# skipped via a --dry-run --forward probe.
#
# Add new patches by appending an `apply <package-dir> <patch-file>` line.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

apply() {
    target="$ROOT/$1"
    patch_file="$ROOT/$2"

    if [ ! -d "$target" ]; then
        echo "  skip (package not installed): $1"
        return 0
    fi
    if [ ! -f "$patch_file" ]; then
        echo "  skip (patch missing): $2"
        return 0
    fi

    if patch -p1 -d "$target" --forward --dry-run <"$patch_file" >/dev/null 2>&1; then
        patch -p1 -d "$target" --forward <"$patch_file" >/dev/null
        echo "  applied: $2"
    else
        echo "  skip (already applied): $2"
    fi
}

echo "Applying vendor patches..."
apply "vendor/adyen/php-api-library" "patches/adyen-php-api-library-php85-curl-close.patch"
echo "Done."
