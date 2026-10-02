<?php
namespace CarpetAvenue\ChangePayment\Cron;

class ProcessOrphans
{
    protected \CarpetAvenue\ChangePayment\Helper\Data $dataHelper;
    protected \CarpetAvenue\ChangePayment\Helper\Order $orderHelper;

    public function __construct(
        \CarpetAvenue\ChangePayment\Helper\Data $dataHelper,
        \CarpetAvenue\ChangePayment\Helper\Order $orderHelper
    ) {
        $this->dataHelper = $dataHelper;
        $this->orderHelper = $orderHelper;
    }

    public function execute()
    {
        if (!$this->dataHelper->isEnabled()) {
            return;
        }
        $this->orderHelper->processOrphanedOrders();
    }
}