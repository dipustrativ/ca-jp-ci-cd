<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\Blacklist;

use Magento\Framework\App\ResourceConnection;

/**
 * Loads the JP storefront product blocklist (ca_jp_blacklist) once per request.
 *
 * A blocklisted SKU is treated as a disabled product on the storefront (see
 * Plugin\Blacklist\ProductStatus), so it drops out of every catalog/search
 * surface and 404s on its direct URL.
 */
class SkuProvider
{
    /** @var array<string, true>|null */
    private ?array $skus = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function isBlacklisted(string $sku): bool
    {
        // Case-insensitive: the ca_jp_blacklist SKU may be entered in a
        // different case than the catalog SKU, and MySQL's default collation
        // matches case-insensitively (so the collection filter already does).
        return isset($this->load()[mb_strtolower($sku)]);
    }

    /** @return string[] */
    public function getSkus(): array
    {
        return array_keys($this->load());
    }

    /** @return array<string, true> */
    private function load(): array
    {
        if ($this->skus !== null) {
            return $this->skus;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ca_jp_blacklist');
        $select = $connection->select()->from($table, ['sku']);

        $this->skus = array_fill_keys(
            array_map('mb_strtolower', $connection->fetchCol($select)),
            true
        );

        return $this->skus;
    }
}
