<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Model\Seo;

use CarpetAvenue\Productify\LayeredNavigation\Model\Config;

/**
 * Splits an SEO path like "rugs/wool-rugs/color-red_blue/material-wool" into
 * the category path ("rugs/wool-rugs") and filter params
 * (['color' => '5,6', 'material' => '12']).
 */
class UrlParser
{
    public function __construct(
        private readonly SlugProvider $slugProvider,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{categoryPath: string, params: array<string, string>}|null
     *         null when the path contains no valid filter segments
     */
    public function parse(string $pathInfo, int $storeId, string $urlSuffix = ''): ?array
    {
        $path = trim($pathInfo, '/');
        if ($urlSuffix !== '' && str_ends_with($path, $urlSuffix)) {
            $path = substr($path, 0, -strlen($urlSuffix));
        }

        $segments = explode('/', $path);
        $params = [];

        // Consume valid filter segments from the end of the path.
        while (count($segments) > 1) {
            $parsed = $this->parseSegment(end($segments), $storeId);
            if ($parsed === null) {
                break;
            }
            [$code, $optionIds] = $parsed;
            if (isset($params[$code])) {
                // Same attribute twice in the path — not a URL we generate.
                return null;
            }
            $params[$code] = implode(',', $optionIds);
            array_pop($segments);
        }

        if ($params === []) {
            return null;
        }

        return [
            'categoryPath' => implode('/', $segments) . $urlSuffix,
            'params' => $params,
        ];
    }

    /**
     * @return array{0: string, 1: string[]}|null [attributeCode, optionIds]
     */
    private function parseSegment(string $segment, int $storeId): ?array
    {
        foreach ($this->slugProvider->getFilterableCodes($storeId) as $code) {
            if (!str_starts_with($segment, $code . '-')) {
                continue;
            }
            $slugPart = substr($segment, strlen($code) + 1);
            if ($slugPart === '') {
                return null;
            }

            $optionIds = [];
            foreach (explode($this->config->getMultiValueSeparator(), $slugPart) as $slug) {
                $optionId = $this->slugProvider->getOptionIdBySlug($storeId, $code, $slug);
                if ($optionId === null) {
                    // Prefix matched but a slug does not resolve — treat the
                    // whole segment as a regular path (e.g. category
                    // "size-guide" vs attribute "size").
                    return null;
                }
                $optionIds[] = $optionId;
            }

            return [$code, array_values(array_unique($optionIds))];
        }

        return null;
    }
}
