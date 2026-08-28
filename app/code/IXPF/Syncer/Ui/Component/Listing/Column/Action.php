<?php

namespace IXPF\Syncer\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\Escaper;

class Action extends Column
{
    protected $urlBuilder;
    protected Escaper $escaper;

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        Escaper $escaper,
        array $components = [],
        array $data = []
    )
    {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->urlBuilder = $urlBuilder;
        $this->escaper = $escaper;
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
            $actions['view'] = [
                'href' => $this->urlBuilder->getUrl('ixpfsyncer/webhook/view', [
                    'entity_id' => $item['entity_id'],
                ]),
                'label' => __('View'),
            ];


            if ($item['trigger_status_raw'] == 'failed') {
                $actions['retry'] = [
                    'href' => $this->urlBuilder->getUrl('ixpfsyncer/webhook/retry', [
                        'entity_id' => $item['entity_id'],
                    ]),
                    'label' => __('Retry'),
                    'confirm' => [
                        'title' => __('Retry Webhook'),
                        'message' => __('Are you sure you want to retry this webhook?')
                    ]
                ];
            }

            if ($item['trigger_status_raw'] == 'pending') {
                $actions['cancel'] = [
                    'href' => $this->urlBuilder->getUrl('ixpfsyncer//webhook/retry', [
                        'entity_id' => $item['entity_id'],
                    ]),
                    'label' => __('Manual Trigger'),
                    'confirm' => [
                        'title' => __('Manual Trigger'),
                        'message' => __('Are you sure you want to manually trigger this webhook?')
                    ]
                ];
            }

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}