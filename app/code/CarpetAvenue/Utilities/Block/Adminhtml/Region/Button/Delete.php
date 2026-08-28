<?php

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Block\Adminhtml\Region\Button;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Delete button – only visible when editing an existing region.
 */
class Delete implements ButtonProviderInterface
{
    public function __construct(
        private UrlInterface $urlBuilder,
        private RequestInterface $request
    ) {}

    public function getButtonData(): array
    {
        $regionId = (int)$this->request->getParam('region_id');

        if (!$regionId) {
            return [];
        }

        $deleteUrl = $this->urlBuilder->getUrl('utilities/region/delete', ['region_id' => $regionId]);

        return [
            'label'      => __('Delete'),
            'class'      => 'delete',
            'on_click'   => "deleteConfirm('" . __('Are you sure you want to delete this region?') . "', '{$deleteUrl}', {data: {}})",
            'sort_order' => 20,
        ];
    }
}

