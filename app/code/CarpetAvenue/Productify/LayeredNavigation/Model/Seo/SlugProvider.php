<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Model\Seo;

use Magento\Catalog\Model\Layer\Category\FilterableAttributeList;
use Magento\Eav\Model\Entity\Attribute as EavAttribute;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Filter\TranslitUrl;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Slug maps for SEO filter URLs, generated from option labels — no extra DB
 * tables. Duplicate labels within an attribute get a "-{optionId}" suffix so
 * slugs stay deterministic in both directions. Cached per store and
 * invalidated by the EAV attribute cache tag on attribute/option saves.
 */
class SlugProvider
{
    private const CACHE_KEY_PREFIX = 'productify_layerednav_slugs_';
    private const CACHE_LIFETIME = 86400;

    /** @var array<int, array> */
    private array $maps = [];

    public function __construct(
        private readonly FilterableAttributeList $filterableAttributeList,
        private readonly TranslitUrl $translitUrl,
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {
    }

    /**
     * @return string[] filterable option-type attribute codes
     */
    public function getFilterableCodes(int $storeId): array
    {
        return array_keys($this->getMaps($storeId)['byCode']);
    }

    /**
     * @return array<string, string> optionId => slug for one attribute
     */
    public function getSlugsByCode(int $storeId, string $attributeCode): array
    {
        return $this->getMaps($storeId)['byCode'][$attributeCode] ?? [];
    }

    public function getOptionIdBySlug(int $storeId, string $attributeCode, string $slug): ?string
    {
        $optionId = $this->getMaps($storeId)['bySlug'][$attributeCode][$slug] ?? null;

        return $optionId !== null ? (string)$optionId : null;
    }

    public function getSlugByOptionId(int $storeId, string $attributeCode, string $optionId): ?string
    {
        $slug = $this->getMaps($storeId)['byCode'][$attributeCode][$optionId] ?? null;

        return $slug !== null ? (string)$slug : null;
    }

    /**
     * @return array{byCode: array<string, array<string, string>>, bySlug: array<string, array<string, string>>}
     */
    private function getMaps(int $storeId): array
    {
        if (isset($this->maps[$storeId])) {
            return $this->maps[$storeId];
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $storeId;
        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            return $this->maps[$storeId] = $this->json->unserialize($cached);
        }

        $maps = $this->buildMaps($storeId);
        $this->cache->save(
            $this->json->serialize($maps),
            $cacheKey,
            [EavAttribute::CACHE_TAG, \Magento\Framework\App\Cache\Type\Config::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $this->maps[$storeId] = $maps;
    }

    private function buildMaps(int $storeId): array
    {
        $byCode = [];
        $bySlug = [];

        foreach ($this->filterableAttributeList->getList() as $attribute) {
            if (!in_array($attribute->getFrontendInput(), ['select', 'multiselect'], true)) {
                continue;
            }
            $code = $attribute->getAttributeCode();
            $attribute->setStoreId($storeId);

            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                if (empty($option['value']) && !is_numeric($option['value'])) {
                    continue;
                }
                $optionId = (string)$option['value'];
                $slug = $this->slugify((string)$option['label']);
                if ($slug === '' || isset($bySlug[$code][$slug])) {
                    $slug = ($slug === '' ? 'o' : $slug . '-') . $optionId;
                }
                $byCode[$code][$optionId] = $slug;
                $bySlug[$code][$slug] = $optionId;
            }

            // Attribute is filterable but has no options yet — still register
            // the code so the router recognizes (and rejects) its segments.
            $byCode[$code] = $byCode[$code] ?? [];
            $bySlug[$code] = $bySlug[$code] ?? [];
        }

        return ['byCode' => $byCode, 'bySlug' => $bySlug];
    }

    private function slugify(string $label): string
    {
        return strtolower($this->translitUrl->filter($label));
    }
}
