<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Enables the WebShopApps Matrix Rate shipping carrier at the default scope so the
 * JP store offers it. The carrier ships disabled by default
 * (carriers/matrixrate/active = 0), which is why it never appeared at checkout.
 *
 * NOTE: enabling the carrier is necessary but not sufficient — Matrix Rate only
 * returns a quote when the `webshopapps_matrixrate` table holds rows matching the
 * destination/weight/price. Import the rate CSV (admin: Stores > Config > Sales >
 * Shipping Methods > Matrix Rate) for it to surface a selectable rate.
 *
 * Writes the DEFAULT scope only, so an admin can still override per website/store.
 * Idempotent: skips when the carrier is already enabled at the default scope.
 *
 * @package CarpetAvenue_Japan
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class EnableMatrixRateShipping implements DataPatchInterface
{
    private const PATH_MATRIXRATE_ACTIVE = 'carriers/matrixrate/active';

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        if (!$this->scopeConfig->isSetFlag(self::PATH_MATRIXRATE_ACTIVE)) {
            $this->configWriter->save(self::PATH_MATRIXRATE_ACTIVE, '1');
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
