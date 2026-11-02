<?php

namespace IXPF\Syncer\Model\Source;

use Magento\Framework\Option\ArrayInterface;
use IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;

class ProductGalleryWebhookStatus implements ArrayInterface
{
    /**
     * Get options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => IxpfPhotoStudioQueue::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => IxpfPhotoStudioQueue::STATUS_PROCESSING, 'label' => __('Processing')],
            ['value' => IxpfPhotoStudioQueue::STATUS_COMPLETED, 'label' => __('Completed')],
            ['value' => IxpfPhotoStudioQueue::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => IxpfPhotoStudioQueue::STATUS_MAGENTO_FAILED, 'label' => __('Failed by Magento')],
            ['value' => IxpfPhotoStudioQueue::STATUS_READY_FOR_WEBHOOK, 'label' => __('Ready for Webhook')],
            ['value' => IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED, 'label' => __('Webhook Failed')],
            ['value' => IxpfPhotoStudioQueue::STATUS_SUPERSEDED, 'label' => __('Superseded')]
        ];
    }

}