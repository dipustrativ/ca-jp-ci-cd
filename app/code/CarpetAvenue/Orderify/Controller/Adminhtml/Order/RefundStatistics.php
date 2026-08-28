<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\LayoutFactory;

class RefundStatistics extends Action
{

    public function __construct(
        Action\Context $context,
        protected LayoutFactory $layoutFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultFactory->create(
            ResultFActory::TYPE_RAW
        );
        $layout = $this->layoutFactory->create();
        $html = $layout->getLayout()
            ->createBlock(\CarpetAvenue\Orderify\Block\Adminhtml\Order\Ajax\RefundStatistic::class)
            ->setTemplate('CarpetAvenue_Orderify::order/view/ajax/refund_statistics.phtml')
            ->toHtml();
        return $result->setContents($html);
    }
}