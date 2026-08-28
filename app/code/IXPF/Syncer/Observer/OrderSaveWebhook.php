<?php
declare(strict_types=1);

namespace IXPF\Syncer\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Fire the order webhook on order save.
 */
class OrderSaveWebhook extends AbstractOrderEntityWebhook
{
    protected function getType(): string
    {
        return 'order';
    }

    protected function resolveObjectId(Observer $observer): ?int
    {
        /** @var OrderInterface|null $order */
        $order = $observer->getEvent()->getData('order');

        return $order && $order->getId() ? (int)$order->getId() : null;
    }
}
