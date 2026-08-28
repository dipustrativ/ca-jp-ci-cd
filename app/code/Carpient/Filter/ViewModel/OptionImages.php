<?php

declare(strict_types=1);

namespace Carpient\Filter\ViewModel;

use Carpient\Filter\Model\OptionImage;
use Magento\Eav\Model\Entity\Attribute as EavAttribute;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\TableNotFoundException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Helper\Media as SwatchMedia;

/**
 * Exposes a per-attribute-option image URL to frontend filter templates.
 *
 * Priority per option:
 *   1. Image uploaded via the admin "Setting" modal (carpient_filter_option_image).
 *   2. Fallback to the option's existing Magento visual swatch image
 *      (eav_attribute_option_swatch, type = image) so swatch attributes such as
 *      Shape show their icon without a re-upload.
 *
 * One batch query per source, then cached: both selects are whole-table reads
 * and the swatch fallback stats the filesystem once per row.
 */
class OptionImages implements ArgumentInterface
{
    private const CACHE_KEY_PREFIX = 'carpient_filter_option_images_';
    private const CACHE_LIFETIME = 86400;

    /**
     * @var array<int, string>|null option_id => absolute image URL
     */
    private ?array $map = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly SwatchMedia $swatchMedia,
        private readonly CacheInterface $cache,
        private readonly Json $serializer
    ) {
    }

    /**
     * Absolute image URL for an option, or null when none is available.
     *
     * @param int $optionId
     * @return string|null
     */
    public function getUrl(int $optionId): ?string
    {
        if ($optionId <= 0) {
            return null;
        }

        $url = $this->getMap()[$optionId] ?? null;

        return ($url === null || $url === '') ? null : $url;
    }

    /**
     * Keyed per store — the uploaded URLs carry the store's media base.
     *
     * @return array<int, string>
     */
    private function getMap(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $cacheKey = self::CACHE_KEY_PREFIX . $storeId;
        $cached = $this->cache->load($cacheKey);
        $decoded = ($cached === false || $cached === null) ? null : $this->serializer->unserialize($cached);

        if (is_array($decoded)) {
            return $this->map = $decoded;
        }

        $this->map = $this->buildMap();
        // EAV tag covers swatch changes, OptionImage tag covers admin uploads.
        $this->cache->save(
            $this->serializer->serialize($this->map),
            $cacheKey,
            [EavAttribute::CACHE_TAG, OptionImage::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $this->map;
    }

    /**
     * @return array<int, string>
     */
    private function buildMap(): array
    {
        $map = [];
        $connection = $this->resource->getConnection();
        $mediaBase = rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');

        // 2. Swatch image fallback (type 2 = image swatch), admin scope.
        $swatchTable = $this->resource->getTableName('eav_attribute_option_swatch');
        $swatchSelect = $connection->select()
            ->from($swatchTable, ['option_id', 'value'])
            ->where('type = ?', 2)
            ->where('store_id = ?', 0)
            ->where('value IS NOT NULL')
            ->where('value != ?', '');
        foreach ($connection->fetchAll($swatchSelect) as $row) {
            $file = (string) $row['value'];
            $url = $this->swatchMedia->getSwatchAttributeImage('swatch_thumb', $file);
            if ($url !== '') {
                $map[(int) $row['option_id']] = $url;
            }
        }

        // 1. Uploaded images override the swatch fallback.
        $table = $this->resource->getTableName('carpient_filter_option_image');
        $select = $connection->select()
            ->from($table, ['option_id', 'image'])
            ->where('image IS NOT NULL')
            ->where('image != ?', '');

        try {
            $rows = $connection->fetchAll($select);
        } catch (TableNotFoundException $e) {
            // Schema not applied yet (setup:upgrade not run). This runs inside
            // layered navigation, so a missing table must not take down every
            // category page -- fall back to the swatch images already collected.
            return $map;
        }

        foreach ($rows as $row) {
            $map[(int) $row['option_id']] = $mediaBase . '/' . ltrim((string) $row['image'], '/');
        }

        return $map;
    }
}
