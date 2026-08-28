<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column as MagentoColumn;

class JpBlacklistAction  extends MagentoColumn
{

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        protected UrlInterface $urlBuilder,
        protected Escaper $escaper,
        array $components = [],
        array $data = []
    )
    {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {

        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as & $item) {
            if (!isset($item['sku'])) {
                continue;
            }

            $actions = [];
            $actions['delete'] = [
                'href' => $this->urlBuilder->getUrl('productify/japan/delete', [
                    'sku' => $item['sku'],
                ]),
                'label' => __('Delete'),
            ];

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}