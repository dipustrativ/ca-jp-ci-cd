<?php
declare(strict_types=1);

namespace IXPF\Syncer\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * Fire the creditmemo webhook on credit memo save.
 */
class CreditmemoSaveWebhook extends AbstractOrderEntityWebhook
{
    protected function getType(): string
    {
        return 'creditmemo';
    }

    protected function resolveObjectId(Observer $observer): ?int
    {
        /** @var CreditmemoInterface|null $creditmemo */
        $creditmemo = $observer->getEvent()->getData('creditmemo');

        return $creditmemo && $creditmemo->getId() ? (int)$creditmemo->getId() : null;
    }
}
