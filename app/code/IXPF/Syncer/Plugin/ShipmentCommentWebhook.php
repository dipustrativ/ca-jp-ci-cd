<?php
declare(strict_types=1);

namespace IXPF\Syncer\Plugin;

/**
 * Shipment comment save fires the shipment webhook.
 */
class ShipmentCommentWebhook extends AbstractCommentWebhook
{
    /**
     * @inheritDoc
     */
    protected function getType(): string
    {
        return 'shipment';
    }
}
