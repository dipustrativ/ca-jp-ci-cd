<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model\PaymentIcon;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;

/**
 * Reads/lists payment icon files stored under pub/media/payment_icons/.
 *
 * Single source of truth for the media subdir, allowed extensions and the
 * relative-path <-> URL mapping shared by the admin chooser and the frontend
 * strip.
 */
class IconRepository
{
    public const MEDIA_SUBDIR = 'payment_icons';

    public const ALLOWED_EXTENSIONS = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** @var Filesystem */
    private $filesystem;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var WriteInterface|null */
    private $mediaDir;

    public function __construct(
        Filesystem $filesystem,
        StoreManagerInterface $storeManager
    ) {
        $this->filesystem = $filesystem;
        $this->storeManager = $storeManager;
    }

    /**
     * @return WriteInterface
     */
    public function getMediaDirectory(): WriteInterface
    {
        if ($this->mediaDir === null) {
            $this->mediaDir = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        }
        return $this->mediaDir;
    }

    /**
     * Absolute path to the payment_icons media folder (created if missing).
     *
     * @return string
     */
    public function getAbsoluteDir(): string
    {
        $dir = $this->getMediaDirectory();
        if (!$dir->isExist(self::MEDIA_SUBDIR)) {
            $dir->create(self::MEDIA_SUBDIR);
        }
        return $dir->getAbsolutePath(self::MEDIA_SUBDIR);
    }

    /**
     * Media base URL, e.g. https://host/media/.
     *
     * @return string
     */
    public function getMediaBaseUrl(): string
    {
        return $this->storeManager->getStore()
            ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
    }

    /**
     * Full URL for a stored relative path (e.g. payment_icons/visa.svg).
     *
     * @param string $relativePath
     * @return string
     */
    public function getUrl(string $relativePath): string
    {
        return $this->getMediaBaseUrl() . ltrim($relativePath, '/');
    }

    /**
     * True if the relative path resolves to an existing file under the subdir.
     *
     * @param string $relativePath
     * @return bool
     */
    public function fileExists(string $relativePath): bool
    {
        $relativePath = ltrim($relativePath, '/');
        if (strpos($relativePath, self::MEDIA_SUBDIR . '/') !== 0) {
            return false;
        }
        return $this->getMediaDirectory()->isExist($relativePath);
    }

    /**
     * Resolve a payment-method code to a stored icon's relative path.
     *
     * Tries each allowed extension in priority order (svg first, then png, …),
     * returning the first existing file, e.g. "visa" -> "payment_icons/visa.svg".
     * The code is sanitized to a bare filename (no slashes, no traversal) so a
     * sheet value can never escape the icons folder.
     *
     * @param string $code
     * @return string|null Relative path, or null when no matching file exists.
     */
    public function resolveByCode(string $code): ?string
    {
        $code = strtolower(trim($code));
        // Keep only safe filename characters; strips slashes, dots, traversal.
        $code = preg_replace('/[^a-z0-9_-]/', '', $code) ?? '';
        if ($code === '') {
            return null;
        }
        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            $relative = self::MEDIA_SUBDIR . '/' . $code . '.' . $ext;
            if ($this->fileExists($relative)) {
                return $relative;
            }
        }
        return null;
    }

    /**
     * List icons in the folder.
     *
     * @return array<int, array{name:string, path:string, url:string}>
     */
    public function getList(): array
    {
        $absDir = $this->getAbsoluteDir();
        $items = [];
        foreach ((array)scandir($absDir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $absDir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($full)) {
                continue;
            }
            $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            $relative = self::MEDIA_SUBDIR . '/' . $name;
            $items[] = [
                'name' => $name,
                'path' => $relative,
                'url' => $this->getUrl($relative),
            ];
        }
        usort($items, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $items;
    }
}
