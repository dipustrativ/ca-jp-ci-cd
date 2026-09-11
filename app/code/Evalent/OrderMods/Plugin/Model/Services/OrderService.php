<?php
namespace Evalent\OrderMods\Plugin\Model\Services;

class OrderService{
    protected \Magento\Sales\Api\OrderRepositoryInterface $orderRepository;
    protected \Evalent\OrderMods\Api\ConfigInterface $config;
    public function __construct(
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Evalent\OrderMods\Api\ConfigInterface $config
    ){
        $this->orderRepository = $orderRepository;
        $this->config = $config;
    }
    public function beforeHold($subject, $id){
        $order = $this->orderRepository->get($id);

        if($this->config->denyHoldForOrderStatus($order->getStatus())){
            throw new \Magento\Framework\Exception\LocalizedException(__(sprintf('Can not hold order with status: %s. Wait until payment is processed.', $order->getStatus())));
        }
        return [$id];
    }
}