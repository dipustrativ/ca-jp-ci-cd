<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model\Attachment;

use CarpetAvenue\Orderify\Api\AttachmentGetInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\FileFactory as FileResponseFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class AttachmentGet implements AttachmentGetInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttachmentManager $attachmentManager,
        private readonly FileResponseFactory $fileResponseFactory
    ) {
    }

    /**
     * @inheritDoc
     *
     * The webapi layer can only serialise to JSON/XML, so the PDF is streamed directly and the
     * request terminated — the contract IXPF has always consumed (raw application/pdf body).
     */
    public function execute($orderId)
    {
        $orderId = (int)$orderId;
        if ($orderId <= 0) {
            throw new InputException(__('Order ID is needed to retrieve attachment.'));
        }

        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);

        if (!$this->attachmentManager->hasExtraInstructions($order)) {
            throw new LocalizedException(
                __('Extra shipping instructions should not be included for this order.')
            );
        }

        if (!$this->attachmentManager->fileExists($order)) {
            throw new NoSuchEntityException(__('Order has no attachment file.'));
        }

        $this->fileResponseFactory->create([
            'options' => [
                'filePath'      => $this->attachmentManager->getPath($order),
                'fileName'      => $this->attachmentManager->getFileName($order),
                'contentType'   => 'application/pdf',
                'directoryCode' => DirectoryList::VAR_DIR,
            ],
        ])->sendResponse();

        // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
        exit(0);
    }
}
