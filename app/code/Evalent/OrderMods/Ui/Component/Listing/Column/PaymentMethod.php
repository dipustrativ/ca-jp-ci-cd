<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Ui\Component\Listing\Columns\Column;

class PaymentMethod extends Column
{

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->orderRepository = $orderRepository;
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                if ($item['entity_id']) {
                    $order = $this->orderRepository->get($item['entity_id']);
                    if (isset($order->getPayment()->getAdditionalInformation()['brand_code'])) {
                        $item[$this->getData('name')] = $order->getPayment()->getAdditionalInformation()['brand_code'];
                    }
                }
            }
        }
        return $dataSource;
    }
}
