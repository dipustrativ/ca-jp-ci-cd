<?php

declare(strict_types=1);

namespace Evalent\MatrixRateMods\Plugin\Shipping\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory;
use Magento\Store\Model\ScopeInterface;

class Data
{

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory
     */
    private $trackCollectionFactory;

    protected \Evalent\MatrixRateMods\Helper\Data $helper;
    protected \Magento\Sales\Model\OrderRepository $orderRepository;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        CollectionFactory $trackCollectionFactory,
        \Evalent\MatrixRateMods\Helper\Data $helper,
        \Magento\Sales\Model\OrderRepository $orderRepository
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->trackCollectionFactory = $trackCollectionFactory;
        $this->helper = $helper;
        $this->orderRepository = $orderRepository;
    }

    public function aroundGetTrackingPopupUrlBySalesModel(\Magento\Shipping\Helper\Data $subject, callable $proceed, $model)
    {
        if ($model instanceof Track) {
            $carrierCode = $model->getCarrierCode();
            if (null !== $carrierCode){
                if (mb_strpos($carrierCode, "dhl") !== false) { //Some cases the carrier_code might be "dhl_de"
                    $carrierCode = 'dhl';
                }
                else if (mb_strpos($carrierCode, "gls") !== false) { //Some cases the carrier_code might be "dhl_de"
                    $carrierCode = 'gls';
                }
                $order = $this->orderRepository->get($model->getOrderId());
                $url = $this->helper->getDestTrackingUrl($carrierCode,$order->getShippingAddress()->getCountryId());
                if (null === $url){
                    $trackingUrlConfigPath = 'carriers/matrixrate/' . $carrierCode . '_tracking_url';
                    $url = $this->scopeConfig->getValue($trackingUrlConfigPath, ScopeInterface::SCOPE_STORE, $model->getStoreId());
                }
                if ($url != null && trim($url) !="") {
                    return str_replace("{tracking_number}", $model->getNumber(), $url);
                }
            }
        } elseif ($model instanceof Order) {
            $shipments = $model->getShipmentsCollection();
            foreach ($shipments as $shipment) {
                $track = $this->getTracksCollection($shipment);
                $carrierCode = $track->getCarrierCode();
                if (null === $carrierCode){
                    continue;
                }
                if (mb_strpos($carrierCode, "dhl") !== false) { //Some cases the carrier_code might be "dhl_de"
                    $carrierCode = 'dhl';
                }
                else if (mb_strpos($carrierCode, "gls") !== false) { //Some cases the carrier_code might be "dhl_de"
                    $carrierCode = 'gls';
                }
                $url = $this->helper->getDestTrackingUrl($carrierCode,$model->getShippingAddress()->getCountryId());
                if (null === $url){
                    $trackingUrlConfigPath = 'carriers/matrixrate/' . $carrierCode . '_tracking_url';
                    $url = $this->scopeConfig->getValue($trackingUrlConfigPath, ScopeInterface::SCOPE_STORE, $model->getStoreId());
                }
                if ($url != null && trim($url) !="") {
                    return str_replace("{tracking_number}", $track->getNumber(), $url);
                }
            }
        }
        return $proceed($model);
    }

    /**
     * @param Shipment $shipment
     * @return Track
     */
    protected function getTracksCollection(Shipment $shipment)
    {
        /** @var Track $track */
        $track = $this->trackCollectionFactory->create()->setShipmentFilter($shipment->getId())->getFirstItem();

        if ($shipment->getId()) {
            $track->setShipment($shipment);
        }
        return $track;
    }
}
