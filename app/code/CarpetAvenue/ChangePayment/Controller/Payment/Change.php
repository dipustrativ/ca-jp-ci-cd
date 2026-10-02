<?php

declare(strict_types=1);

namespace CarpetAvenue\ChangePayment\Controller\Payment;

use CarpetAvenue\ChangePayment\Helper\Data;
use CarpetAvenue\ChangePayment\Helper\Order;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\UrlInterface;

class Change implements HttpGetActionInterface
{

    public function __construct(
        protected Order            $orderHelper,
        protected Data             $dataHelper,
        protected RedirectFactory  $redirectFactory,
        protected UrlInterface     $url
    )
    {
    }

    public function execute()
    {
        if (!$this->dataHelper->isEnabled()) {
            return $this->redirectFactory->create()->setUrl($this->url->getUrl('/', []));
        }
        return $this->orderHelper->holdAndReorder();
    }
}

