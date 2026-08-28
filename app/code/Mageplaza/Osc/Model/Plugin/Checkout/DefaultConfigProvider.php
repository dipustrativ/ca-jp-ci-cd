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

namespace Mageplaza\Osc\Model\Plugin\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Mageplaza\Osc\Helper\Item;

/**
 * Class DefaultConfigProvider
 * @package Mageplaza\Osc\Model\Plugin\Checkout
 */
class DefaultConfigProvider
{
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var Item
     */
    private $itemHelper;

    /**
     * DefaultConfigProvider constructor.
     *
     * @param CheckoutSession $checkoutSession
     * @param Item $itemHelper
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        Item $itemHelper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->itemHelper = $itemHelper;
    }

    /**
     * @param \Magento\Checkout\Model\DefaultConfigProvider $subject
     * @param array $config
     *
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function afterGetConfig(\Magento\Checkout\Model\DefaultConfigProvider $subject, $config)
    {
        if (!$this->itemHelper->isOscPage()) {
            return $config;
        }

        $quote = $this->checkoutSession->getQuote();
        $countItem = 0;
        $maxItemsDisplay = (int)$this->itemHelper->getConfigValue('checkout/options/max_items_display_count');
        foreach ($config['quoteItemData'] as &$item) {
            $countItem += 1;
            if ($countItem < $maxItemsDisplay || $maxItemsDisplay === 0) {
                $item['mposc'] = $this->itemHelper->getItemOptionsConfig($quote, $item['item_id'], true);
            } else {
                $item['mposc'] = $this->itemHelper->getItemOptionsConfig($quote, $item['item_id'], false);
            }
        }

        return $config;
    }
}
