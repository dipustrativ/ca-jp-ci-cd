<?php

declare(strict_types=1);

namespace CarpetAvenue\ChangePayment\Controller\Payment;

use CarpetAvenue\ChangePayment\Helper\Data;
use CarpetAvenue\ChangePayment\Helper\Order;
use Exception;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class Confirmation implements HttpGetActionInterface
{

    public function __construct(
        protected PageFactory              $resultPageFactory,
        protected RequestInterface         $request,
        protected EncryptorInterface       $encryptor,
        protected Json                     $json,
        protected OrderRepositoryInterface $orderRepository,
        protected Order                    $orderHelper,
        protected ManagerInterface         $messageManager,
        protected LoggerInterface          $logger,
        protected RedirectFactory          $redirectFactory,
        protected UrlInterface             $url,
        protected Data                     $dataHelper
    )
    {
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();

        if (!$this->dataHelper->isEnabled()) {
            return $this->redirectFactory->create()->setUrl($this->url->getUrl('/', []));
        }

        try {
            $requestParams = $this->json->unserialize($this->encryptor->decrypt($this->request->getParam('params')));
            $this->orderHelper->validateRequestParams($requestParams);
            $order = $this->orderRepository->get($requestParams['order_id']);

            $resultPage->getConfig()->getTitle()->prepend(__("Change Payment Method for order %1", $order->getIncrementId()));
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage(__('We encountered an error while trying to cancel your order. Please contact us for further assistance.'));
            $this->logger->error($e->getMessage());
            $this->logger->error($e->getTraceAsString());
            return $this->redirectFactory->create()->setUrl($this->url->getUrl('/', []));
        }
        return $resultPage;
    }
}

