<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Plugin;

class RemoveCancelButtonOnOrder
{


    public function aroundAddButton(\Magento\Sales\Block\Adminhtml\Order\View $subject, callable $proceed, $buttonId, $data, $level, $sortOrder, $region)
    {
        if ($buttonId == 'order_cancel') {
            $order = $subject->getOrder();
            if ($order->hasShipments() && $order->getTotalDue() != 0) {
                return $subject;
            }
        }
        return $proceed($buttonId, $data, $level, $sortOrder, $region);
    }
}
