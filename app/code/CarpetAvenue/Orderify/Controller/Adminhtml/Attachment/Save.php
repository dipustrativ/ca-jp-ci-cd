<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Attachment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect;
use Throwable;

/**
 * POST — save the "extra shipping instructions" flag and, when a file is sent, the attachment PDF.
 */
class Save extends AbstractAttachment implements HttpPostActionInterface
{
    private const FILE_FIELD = 'file_attachment';

    public function execute(): Redirect
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');

        try {
            $order = $this->loadOrder();

            $this->attachmentManager->setExtraInstructions(
                $order,
                (bool)$this->getRequest()->getParam('extra_shipping_instructions')
            );

            /** @var HttpRequest $request */
            $request = $this->getRequest();
            $file = $request->getFiles(self::FILE_FIELD);
            if (!empty($file['tmp_name'])) {
                $this->attachmentManager->attachUpload($order, self::FILE_FIELD);
                $this->messageManager->addSuccessMessage(__('The attachment has been saved.'));
            } elseif (!empty($file['name'])) {
                // Name without tmp_name = PHP rejected the upload (e.g. upload_max_filesize).
                $this->messageManager->addErrorMessage(
                    __('The file could not be uploaded (error code %1).', $file['error'] ?? '?')
                );
            } else {
                $this->messageManager->addSuccessMessage(__('The attachment settings have been saved.'));
            }
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->redirectToOrder($orderId);
    }
}
