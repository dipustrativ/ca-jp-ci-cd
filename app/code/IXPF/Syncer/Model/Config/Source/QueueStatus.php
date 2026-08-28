<?php
declare(strict_types=1);

namespace IXPF\Syncer\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class QueueStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'pending', 'label' => __('Pending')],
            ['value' => 'processing', 'label' => __('Processing')],
            ['value' => 'completed', 'label' => __('Completed')],
            ['value' => 'failed', 'label' => __('Failed')],
        ];
    }
}

