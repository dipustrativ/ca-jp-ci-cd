<?php

namespace CarpetAvenue\ReservationsFix\Component\Listing\Columns;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class OrderStatus extends Column{

    protected OrderRepositoryInterface $orderRepository;
    /**
     * Order Id constructor.
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param string[] $components
     * @param string[] $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        OrderRepositoryInterface $orderRepository,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                if (isset($item['object_id'])) {
                    try{
                        $order = $this->orderRepository->get($item['object_id']);
                        $item['order_status'] = $order->getStatus();
                    }
                    catch (NoSuchEntityException $e) {
                        //??
                    }
                    catch (\Exception $e) {
                        $item['order_status'] = $e->getMessage();
                    }
                }
            }
        }
        return $dataSource;
    }
}