<?php

namespace CarpetAvenue\Orderify\Plugins\Adminhtml\View;

use Magento\Sales\Block\Adminhtml\Order\View\Info;
use Magento\Sales\Model\Order\Address;

class ShippingStateMessagePlugin
{
    public function aroundGetFormattedAddress(
        Info     $subject,
        \Closure $proceed,
        Address  $address
    )
    {
        $result = $proceed($address);
        if ($address->getAddressType() == "shipping" && strtoupper($address->getCountryId()) == "IT") {
            if ($address->getRegion() == null || intval($address->getRegionId()) == 0) {
                $result = '<p class="message message-warning">' . __('State/Province is missing for Italy') . '</p> <br>' . $result;
            }
        }
        return $result;
    }
}