<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Block\Adminhtml\Order\View;

use CarpetAvenue\Orderify\Model\Attachment\AttachmentManager;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * "Attachment" section on the admin order view.
 */
class Attachment extends Template
{
    private Order|false|null $order = null;

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttachmentManager $attachmentManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOrderId(): int
    {
        return (int)$this->getRequest()->getParam('order_id');
    }

    public function getOrder(): ?Order
    {
        if ($this->order === null) {
            try {
                /** @var Order $order */
                $order = $this->orderRepository->get($this->getOrderId());
                $this->order = $order;
            } catch (NoSuchEntityException) {
                $this->order = false;
            }
        }

        return $this->order ?: null;
    }

    public function hasExtraInstructions(): bool
    {
        $order = $this->getOrder();

        return $order !== null && $this->attachmentManager->hasExtraInstructions($order);
    }

    public function getFileName(): ?string
    {
        $order = $this->getOrder();

        return $order !== null ? $this->attachmentManager->getFileName($order) : null;
    }

    public function isFileMissing(): bool
    {
        $order = $this->getOrder();

        return $order !== null
            && $this->attachmentManager->getPath($order) !== null
            && !$this->attachmentManager->fileExists($order);
    }

    public function getMaxSizeMb(): int
    {
        return (int)(AttachmentManager::MAX_SIZE / 1048576);
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('orderify/attachment/save');
    }

    public function getDownloadUrl(): string
    {
        return $this->getUrl('orderify/attachment/download', ['order_id' => $this->getOrderId()]);
    }

    public function getRemoveUrl(): string
    {
        return $this->getUrl('orderify/attachment/remove');
    }

    protected function _toHtml(): string
    {
        if ($this->getOrder() === null
            || !$this->_authorization->isAllowed('CarpetAvenue_Orderify::order_attachment')
        ) {
            return '';
        }

        return parent::_toHtml();
    }
}
