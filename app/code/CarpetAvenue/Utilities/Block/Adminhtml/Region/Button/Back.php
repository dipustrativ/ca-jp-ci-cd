<?php

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Block\Adminhtml\Region\Button;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class Back implements ButtonProviderInterface
{
    public function __construct(
        private UrlInterface $urlBuilder
    ) {}

    public function getButtonData(): array
    {
        return [
            'label'      => __('Back'),
            'on_click'   => sprintf("location.href = '%s';", $this->urlBuilder->getUrl('utilities/region/index')),
            'class'      => 'back',
            'sort_order' => 10,
        ];
    }
}

