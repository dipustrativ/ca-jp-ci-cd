<?php

declare(strict_types=1);

namespace Evalent\MatrixRateMods\OverWrite\Controller\Adminhtml\Order\Shipment;

use Magento\Backend\App\Action;

class GetShippingItemsGrid extends \Magento\Shipping\Controller\Adminhtml\Order\Shipment\GetShippingItemsGrid
{


    /**
     * @var \Evalent\MatrixRateMods\Model\ShipmentProvider
     */
    private $shipmentProvider;

    public function __construct(
        Action\Context $context,
        \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader $shipmentLoader,
        \Evalent\MatrixRateMods\Model\ShipmentProvider $shipmentProvider
    ) {
        parent::__construct($context, $shipmentLoader);
        $this->shipmentProvider = $shipmentProvider;
    }

    public function execute()
    {
        $this->shipmentLoader->setOrderId($this->getRequest()->getParam('order_id'));
        $this->shipmentLoader->setShipmentId($this->getRequest()->getParam('shipment_id'));
        $this->shipmentLoader->setShipment($this->shipmentProvider->getShipmentData());
        $this->shipmentLoader->setTracking($this->getRequest()->getParam('tracking'));
        $this->shipmentLoader->load();
        return $this->getResponse()->setBody(
            $this->_view->getLayout()->createBlock(
                \Magento\Shipping\Block\Adminhtml\Order\Packaging\Grid::class
            )->setTemplate('Evalent_MatrixRateMods::order/packaging/grid.phtml')
                ->setIndex(
                $this->getRequest()->getParam('index')
            )->toHtml()
        );
    }
}