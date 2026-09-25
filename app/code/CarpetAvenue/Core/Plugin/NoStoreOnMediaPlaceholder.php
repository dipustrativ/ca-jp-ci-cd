<?php

declare(strict_types=1);

namespace CarpetAvenue\Core\Plugin;

use Magento\MediaStorage\Model\File\Storage\Response;

/**
 * Stop a CDN caching the "image is missing" placeholder in place of a real image.
 *
 * When nginx can't find a file under /media/ it hands the request to pub/get.php,
 * which resizes the image on demand. If that fails for any reason — the original
 * is gone, the resize blows the fpm memory_limit, the worker times out — core's
 * Media::launch() falls back to setPlaceholderImage() and returns the placeholder
 * with **HTTP 200 and no cache headers at all**.
 *
 * Cloudflare then applies its own Browser Cache TTL to that 200 and stores the
 * placeholder as if it were the image. Because the cache key includes the
 * hostname, one failed resize poisons one store's domain for the whole TTL
 * (observed: 31 days on carpient-se.carpetavenue.net) while the other domains
 * keep serving the real file — which is exactly how this presents in the wild:
 * "same product, same image path, broken on one store view only".
 *
 * A placeholder is a transient stand-in, so it must never be stored. Real files
 * that get.php serves are untouched and stay cacheable.
 *
 * @see \Magento\MediaStorage\App\Media::setPlaceholderImage()
 */
class NoStoreOnMediaPlaceholder
{
    /**
     * Both the catalog placeholder (media/catalog/product/placeholder/…) and the
     * theme default (…/Magento_Catalog/images/product/placeholder/…) live under a
     * directory of this name, and nothing else served through get.php does.
     */
    private const PLACEHOLDER_DIR = '/placeholder/';

    /**
     * Mark a placeholder response uncacheable before it is sent.
     *
     * @param Response $subject
     * @param string|null $path
     * @return array
     */
    public function beforeSetFilePath(Response $subject, $path): array
    {
        if (is_string($path) && str_contains($path, self::PLACEHOLDER_DIR)) {
            // sendResponse() forwards getHeaders() to the transfer adapter, so a
            // header set here does reach the client.
            $subject->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
            $subject->setHeader('Pragma', 'no-cache', true);
            $subject->setHeader('Expires', '0', true);
        }

        return [$path];
    }
}
