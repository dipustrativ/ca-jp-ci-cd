<?php
declare(strict_types=1);

namespace IXPF\Syncer\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\ShipmentInterface;

/**
 * Fire the shipment webhook on shipment save.
 */
class ShipmentSaveWebhook extends AbstractOrderEntityWebhook
{
    protected function getType(): string
    {
        return 'shipment';
    }

    protected function resolveObjectId(Observer $observer): ?int
    {
        /** @var ShipmentInterface|null $shipment */
        $shipment = $observer->getEvent()->getData('shipment');

        return $shipment && $shipment->getId() ? (int)$shipment->getId() : null;
    }
}
