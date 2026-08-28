<?php
declare(strict_types=1);

namespace IXPF\Syncer\Observer;

use Magento\Framework\Event\Observer;

/**
 * Fire the customer webhook on customer save.
 */
class CustomerSaveWebhook extends AbstractOrderEntityWebhook
{
    protected function getType(): string
    {
        return 'customer';
    }

    protected function resolveObjectId(Observer $observer): ?int
    {
        $customer = $observer->getEvent()->getData('customer');

        return $customer && $customer->getId() ? (int)$customer->getId() : null;
    }
}
