<?php

declare(strict_types=1);

namespace Evalent\MatrixRateMods\Model;

use Magento\Framework\App\RequestInterface;
use Magento\Shipping\Model\ShipmentProviderInterface;

class ShipmentProvider implements ShipmentProviderInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @param RequestInterface $request
     */
    public function __construct(
        RequestInterface $request
    ) {
        $this->request = $request;
    }

    /**
     * @inheritdoc
     */
    public function getShipmentData(): array
    {
        $items = $this->request->getParam('items', []);

        $shipmentItems = [];
        foreach ($items as $item) {
            $orderItemId = $item['orderItemId'];
            $shipmentItems['items'][$orderItemId] = $item['qtyToShip'];
        }

        return count($shipmentItems) > 0 ? $shipmentItems : [];
    }
}