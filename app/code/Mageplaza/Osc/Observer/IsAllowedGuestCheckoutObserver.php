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

namespace Mageplaza\Osc\Observer;

use Magento\Downloadable\Observer\IsAllowedGuestCheckoutObserver as DownloadableAllowedGuestCheckoutObserver;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Mageplaza\Osc\Helper\Data;

/**
 * Class IsAllowedGuestCheckoutObserver
 * @package Mageplaza\Osc\Observer
 */
class IsAllowedGuestCheckoutObserver extends DownloadableAllowedGuestCheckoutObserver implements ObserverInterface
{
    /**
     * @inheritdoc
     */
    public function execute(Observer $observer)
    {
        $helper = ObjectManager::getInstance()->get(Data::class);
        if ($helper->isEnabled()) {
            return $this;
        }

        return parent::execute($observer);
    }
}
