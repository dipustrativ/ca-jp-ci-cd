<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Plugin\Checkout;

use CarpetAvenue\Productify\Helper\ProductionType;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Expose the "unique" product flag on each quote item in the checkout config,
 * so the OSC summary mixin (reads `is_unique` off checkoutConfig.quoteItemData)
 * can hide the qty +/- steppers for one-off/unique products.
 */
class DefaultConfigProvider
{
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var ProductionType
     */
    private $productionTypeHelper;

    public function __construct(
        CheckoutSession $checkoutSession,
        ProductionType $productionTypeHelper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->productionTypeHelper = $productionTypeHelper;
    }

    /**
     * @param \Magento\Checkout\Model\DefaultConfigProvider $subject
     * @param array $config
     *
     * @return array
     */
    public function afterGetConfig(\Magento\Checkout\Model\DefaultConfigProvider $subject, $config)
    {
        if (empty($config['quoteItemData'])) {
            return $config;
        }

        $uniqueByItemId = [];
        foreach ($this->checkoutSession->getQuote()->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            if ($product) {
                $uniqueByItemId[(int)$item->getId()] = $this->productionTypeHelper->isProductUnique($product);
            }
        }

        foreach ($config['quoteItemData'] as &$itemData) {
            $itemId = (int)($itemData['item_id'] ?? 0);
            $itemData['is_unique'] = $uniqueByItemId[$itemId] ?? false;
        }

        return $config;
    }
}
