<?php

namespace IXPF\Syncer\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class WebhookTriggerStatus implements \Magento\Framework\Option\ArrayInterface
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_FAILED = 'failed';
    const STATUS_SUCCESS = 'success';

    /**
     * Get options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => self::STATUS_PROCESSING, 'label' => __('Processing')],
            ['value' => self::STATUS_SUCCESS, 'label' => __('Success')],
            ['value' => self::STATUS_FAILED, 'label' => __('Failed')]
        ];
    }

}