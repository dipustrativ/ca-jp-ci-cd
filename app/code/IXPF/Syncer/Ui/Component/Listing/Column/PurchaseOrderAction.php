<?php

namespace IXPF\Syncer\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class PurchaseOrderAction extends Column
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
            if (!isset($item['ixpf_purchase_id'])) {
                continue;
            }

            $actions = [];
            $actions['view'] = [
                'href' => $this->urlBuilder->getUrl('ixpfsyncer/purchaseorder/products', [
                    'ixpf_purchase_id' => $item['ixpf_purchase_id'],
                ]),
                'label' => __('View Products'),
            ];

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}