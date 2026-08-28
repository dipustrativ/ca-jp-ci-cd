<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Action extends Column
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
            if (!isset($item['entity_id'])) {
                continue;
            }

            $actions = [];
            $actions['remove'] = [
                'href' => $this->urlBuilder->getUrl('orderify/blacklist/remove', [
                    'id' => $item['entity_id'],
                ]),
                'label' => __('Remove'),
                'confirm' => [
                    'title' => __('Remove Blacklist Entry'),
                    'message' => __('Are you sure you want to remove this entry from the blacklist?'),
                ]
            ];

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}