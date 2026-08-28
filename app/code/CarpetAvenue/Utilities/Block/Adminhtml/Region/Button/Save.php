<?php

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Block\Adminhtml\Region\Button;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class Save implements ButtonProviderInterface
{
    public function getButtonData(): array
    {
        return [
            'label'          => __('Save Region'),
            'class'          => 'save primary',
            'data_attribute' => [
                'mage-init' => ['button' => ['event' => 'save']],
                'form-role' => 'save',
            ],
            'sort_order' => 90,
        ];
    }
}

