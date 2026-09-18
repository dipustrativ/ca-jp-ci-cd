<?php

namespace CarpetAvenue\ReservationsFix\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;

class ShipmentSourceRegister implements ObserverInterface
{
    protected \CarpetAvenue\ReservationsFix\Model\Session $session;
    public function __construct(\CarpetAvenue\ReservationsFix\Model\Session $session){
        $this->session = $session;
    }

    public function execute(EventObserver $observer){
        // @var \Magento\Sales\Model\Order\Shipment $shipment
        if ($shipment = $observer->getData('shipment')){
            $this->session->setSourceCode($shipment->getExtensionAttributes()->getSourceCode());
        }
    }
}