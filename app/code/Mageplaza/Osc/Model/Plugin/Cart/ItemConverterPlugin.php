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

namespace Mageplaza\Osc\Model\Plugin\Cart;

use Magento\Quote\Api\Data\TotalsItemExtensionInterfaceFactory;
use Magento\Quote\Model\Cart\Totals\ItemConverter;
use Magento\Quote\Api\Data\TotalsItemInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Mageplaza\Osc\Helper\Item;

/**
 * Class ItemConverterPlugin
 *
 * @package Mageplaza\Osc\Model\Plugin\Cart
 */
class ItemConverterPlugin
{
    /**
     * @var Item
     */
    private $helper;

    /**
     * @var Quote
     */
    private $quote;

    /**
     * @var TotalsItemExtensionInterfaceFactory
     */
    private $totalsItemExtension;

    /**
     * ItemConverterPlugin constructor.
     *
     * @param Item                                $helper
     * @param Quote                               $quote
     * @param TotalsItemExtensionInterfaceFactory $totalsItemExtension
     */
    public function __construct(
        Item $helper,
        Quote $quote,
        TotalsItemExtensionInterfaceFactory $totalsItemExtension
    ) {
        $this->helper              = $helper;
        $this->quote               = $quote;
        $this->totalsItemExtension = $totalsItemExtension;
    }

    /**
     * @param ItemConverter       $subject
     * @param TotalsItemInterface $itemsData
     * @param QuoteItem           $item
     * @return TotalsItemInterface
     */
    public function afterModelToDataObject(
        ItemConverter $subject,
        TotalsItemInterface $itemsData,
        QuoteItem $item
    ) {
        if (!$this->helper->isEnabled($item->getStoreId())) {
            return $itemsData;
        }
        $totalsItem = $this->totalsItemExtension->create();
        $data       = ['product_url' => $item->getProduct()->getProductUrl()];

        $totalsItem->setMposc($this->helper->jsonEncodeData($data));
        $itemsData->setExtensionAttributes($totalsItem);

        return $itemsData;
    }
}
