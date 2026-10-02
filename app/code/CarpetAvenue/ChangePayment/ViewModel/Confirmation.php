<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace CarpetAvenue\ChangePayment\ViewModel;

use CarpetAvenue\ChangePayment\Helper\Data;
use CarpetAvenue\ChangePayment\Helper\Order;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class Confirmation implements ArgumentInterface
{

    private OrderInterface $order;

    public function __construct(
        protected RequestInterface         $request,
        protected EncryptorInterface       $encryptor,
        protected Json                     $json,
        protected OrderRepositoryInterface $orderRepository,
        protected Order                    $orderHelper,
        protected Data                     $dataHelper
    )
    {
    }

    public function getOrder(): OrderInterface
    {
        if (!isset($this->order)) {
            $requestParams = $this->json->unserialize($this->encryptor->decrypt($this->request->getParam('params')));
            $this->orderHelper->validateRequestParams($requestParams);
            $this->order = $this->orderRepository->get($requestParams['order_id']);
        }
        return $this->order;
    }

    public function getChangePaymentUrl(): string
    {
        return $this->dataHelper->getChangePaymentUrl($this->getOrder());
    }

    public function canHoldAndReorder(): bool
    {
        // Check if the order can be held and reordered
        return $this->orderHelper->canHoldAndReorder($this->getOrder());
    }
    public function getCartUrl(): string
    {
        return $this->dataHelper->getCartUrl();
    }
}
