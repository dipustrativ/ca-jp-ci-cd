<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Attachment;

use CarpetAvenue\Orderify\Model\Attachment\AttachmentManager;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\FileFactory as FileResponseFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Api\OrderRepositoryInterface;
use Throwable;

/**
 * GET — download the order's attachment PDF.
 */
class Download extends AbstractAttachment implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        AttachmentManager $attachmentManager,
        private readonly FileResponseFactory $fileResponseFactory
    ) {
        parent::__construct($context, $orderRepository, $attachmentManager);
    }

    public function execute(): ResponseInterface|Redirect
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');

        try {
            $order = $this->loadOrder();
            if (!$this->attachmentManager->fileExists($order)) {
                $this->messageManager->addErrorMessage(__('Order has no attachment file.'));
                return $this->redirectToOrder($orderId);
            }

            return $this->fileResponseFactory->create([
                'options' => [
                    'filePath'      => $this->attachmentManager->getPath($order),
                    'fileName'      => $this->attachmentManager->getFileName($order),
                    'contentType'   => 'application/pdf',
                    'directoryCode' => DirectoryList::VAR_DIR,
                ],
            ]);
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->redirectToOrder($orderId);
        }
    }
}
