<?php

namespace IXPF\Syncer\Cron;

use IXPF\Syncer\Helper\Logger\PurchaseLogger;
use IXPF\Syncer\Helper\ProductPurchase;
use IXPF\Syncer\Services\Purchase\Purchase as PurchaseService;
use Magento\Framework\App\State;

class Purchase
{
    public function __construct(
        private readonly PurchaseService $purchaseService,
        private readonly PurchaseLogger $purchaseLogger,
        private readonly ProductPurchase  $productPurchaseHelper,
        private readonly State $state
    )
    {
    }

    public function execute(): void
    {
        try {
            try {
                $this->state->getAreaCode();
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_CRONTAB);
            }

            if (!$this->productPurchaseHelper->isCronEnabled()) {
                return;
            }
            $this->purchaseService->run("Cron");
        } catch (\Exception $exception) {
            $this->purchaseLogger->writeExceptionDaily($exception, "Failed:: Purchase");
        }
    }
}