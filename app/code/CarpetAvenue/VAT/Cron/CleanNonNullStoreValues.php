<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Ported from Evalent\PriceMods\Cron\CleanNonNullStoreValues.
 * Removes non-default-store overrides of the "pricing" product attribute so EUR
 * store views fall back to the default value. No-ops unless the switch is on.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Cron;

use CarpetAvenue\VAT\Model\Config;
use Magento\Catalog\Setup\CategorySetup;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class CleanNonNullStoreValues
{
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->nullPricingInEurStores()) {
            return;
        }

        try {
            $attribute = $this->attributeRepository->get(
                CategorySetup::CATALOG_PRODUCT_ENTITY_TYPE_ID,
                'pricing'
            );
        } catch (\Exception $e) {
            // "pricing" attribute not present on this install – nothing to clean.
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $connection->delete(
            $table,
            [
                'store_id NOT IN (0, 17)',
                'attribute_id = ?' => (int)$attribute->getAttributeId(),
            ]
        );
        $this->logger->info('[CarpetAvenue_VAT] Nulled non-EUR store pricing overrides.');
    }
}
