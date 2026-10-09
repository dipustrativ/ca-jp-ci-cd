<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Attachment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

/**
 * POST — detach the PDF from the order and delete the file.
 */
class Remove extends AbstractAttachment implements HttpPostActionInterface
{
    public function execute(): Redirect
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');

        try {
            $this->attachmentManager->detach($this->loadOrder());
            $this->messageManager->addSuccessMessage(__('The attachment has been removed.'));
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->redirectToOrder($orderId);
    }
}
