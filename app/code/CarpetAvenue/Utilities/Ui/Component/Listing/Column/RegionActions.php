<?php

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column as MagentoColumn;

/**
 * Adds Edit / Delete action links to each row in the region listing.
 */
class RegionActions extends MagentoColumn
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        protected UrlInterface $urlBuilder,
        protected Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['region_id'])) {
                continue;
            }

            $regionId = (int)$item['region_id'];
            $name     = $this->escaper->escapeHtml($item['default_name'] ?? $regionId);

            $item[$this->getData('name')] = [
                'edit' => [
                    'href'  => $this->urlBuilder->getUrl('utilities/region/edit', ['region_id' => $regionId]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href'    => $this->urlBuilder->getUrl('utilities/region/delete', ['region_id' => $regionId]),
                    'label'   => __('Delete'),
                    'confirm' => [
                        'title'   => __('Delete Region'),
                        'message' => __('Are you sure you want to delete region "%1"?', $name),
                    ],
                    'post'    => true,
                ],
            ];
        }

        return $dataSource;
    }
}

