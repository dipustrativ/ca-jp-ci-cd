<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleReader;

/**
 * Taxonomy files used by the category mapping autocomplete (etc/data/, Xtento format:
 * Google rows are "<id> - <path>", lines starting with # are comments).
 */
class Taxonomy
{
    private const SOURCES = [
        'google_ja_JP' => 'google/taxonomy-with-ids.ja-JP.txt',
        'google_en_US' => 'google/taxonomy-with-ids.en-US.txt',
    ];

    public function __construct(
        private readonly ModuleReader $moduleReader,
        private readonly ReadFactory $readFactory
    ) {
    }

    /**
     * @return array<string, string> code => label, e.g. google_ja_JP => [Google] ja_JP
     */
    public function getSources(): array
    {
        $sources = [];
        foreach (array_keys(self::SOURCES) as $code) {
            [$vendor, $locale] = explode('_', $code, 2);
            $sources[$code] = sprintf('[%s] %s', ucfirst($vendor), $locale);
        }
        return $sources;
    }

    public function isValidSource(string $code): bool
    {
        return isset(self::SOURCES[$code]);
    }

    /**
     * @return array<int, array{id: string, label: string}>
     * @throws LocalizedException
     */
    public function search(string $code, string $term, int $limit = 100): array
    {
        $term = trim($term);
        if (!$this->isValidSource($code)) {
            throw new LocalizedException(__('Undefined taxonomy "%1".', $code));
        }
        if ($term === '') {
            return [];
        }

        $directory = $this->readFactory->create(
            $this->moduleReader->getModuleDir(Dir::MODULE_ETC_DIR, 'CarpetAvenue_FeedExport') . '/data'
        );
        $isGoogle = str_starts_with($code, 'google');
        $results = [];
        foreach (explode("\n", $directory->readFile(self::SOURCES[$code])) as $row) {
            $row = trim($row);
            if ($row === '' || $row[0] === '#' || mb_stripos($row, $term) === false) {
                continue;
            }
            if ($isGoogle && str_contains($row, ' - ')) {
                [$id, $path] = explode(' - ', $row, 2);
                $results[] = ['id' => $id, 'label' => $path];
            } else {
                $results[] = ['id' => $row, 'label' => $row];
            }
            if (count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }
}
