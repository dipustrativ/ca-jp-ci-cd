<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Osc
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\Osc\Model\ViewModel\Product\Bundle;

use Magento\Bundle\Block\Catalog\Product\View\Type\Bundle\Option as ProductBlock;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class ValidateQuantity implements ArgumentInterface
{
    /**
     * @var Json
     */
    private $serializer;

    /**
     * @var ProductBlock
     */
    private $productBlock;

    /**
     * @var StockRegistryInterface
     */
    private $stockRegistry;

    /**
     * @param Json $serializer
     * @param ProductBlock $productBlock
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        Json $serializer,
        ProductBlock $productBlock,
        StockRegistryInterface $stockRegistry
    ) {
        $this->serializer = $serializer;
        $this->productBlock = $productBlock;
        $this->stockRegistry = $stockRegistry;
    }

    /**
     * @return bool|string
     */
    public function getQuantityValidators()
    {
        $validators = [];
        $validators['required-number'] = true;

        $stockItem = $this->stockRegistry->getStockItem(
            $this->productBlock->getProduct()->getId(),
            $this->productBlock->getProduct()->getStore()->getWebsiteId()
        );

        $params = [];
        $params['minAllowed']  = (float)$stockItem->getMinSaleQty();
        if ($stockItem->getMaxSaleQty()) {
            $params['maxAllowed'] = (float)$stockItem->getMaxSaleQty();
        }
        if ($stockItem->getQtyIncrements() > 0) {
            $params['qtyIncrements'] = (float)$stockItem->getQtyIncrements();
        }
        $validators['validate-item-quantity'] = $params;

        return $this->serializer->serialize($validators);
    }
}
