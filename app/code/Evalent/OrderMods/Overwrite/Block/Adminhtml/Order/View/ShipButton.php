<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Overwrite\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Registry;
use Magento\InventoryShippingAdminUi\Model\IsOrderSourceManageable;
use Magento\InventoryShippingAdminUi\Model\IsWebsiteInMultiSourceMode;

class ShipButton extends \Magento\InventoryShippingAdminUi\Block\Adminhtml\Order\View\ShipButton
{

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var IsWebsiteInMultiSourceMode
     */
    private $isWebsiteInMultiSourceMode;

    /**
     * @var IsOrderSourceManageable
     */
    private $isOrderSourceManageable;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param IsWebsiteInMultiSourceMode $isWebsiteInMultiSourceMode
     * @param array $data
     * @param IsOrderSourceManageable $isOrderSourceManageable
     */
    public function __construct(
        Context $context,
        Registry $registry,
        IsWebsiteInMultiSourceMode $isWebsiteInMultiSourceMode,
        array $data = [],
        ?IsOrderSourceManageable $isOrderSourceManageable = null
    ) {
        parent::__construct($context, $registry, $isWebsiteInMultiSourceMode, $data, $isOrderSourceManageable);
        $this->registry = $registry;
        $this->isWebsiteInMultiSourceMode = $isWebsiteInMultiSourceMode;
        $this->isOrderSourceManageable = $isOrderSourceManageable ??
            ObjectManager::getInstance()->get(IsOrderSourceManageable::class);
    }

    /**
     * @inheritdoc
     */
    protected function _prepareLayout()
    {
        parent::_prepareLayout();

        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->registry->registry('current_order');
        $websiteId = (int)$order->getStore()->getWebsiteId();
        if ($this->isWebsiteInMultiSourceMode->execute($websiteId) && $this->isOrderSourceManageable->execute($order)) {
            $invoiced = $order->getBaseTotalDue() == 0;
            if ($invoiced) {
                $this->buttonList->update(
                    'order_ship',
                    'onclick',
                    'setLocation(\'' . $this->getSourceSelectionUrl() . '\')'
                );
            } else {
                $this->buttonList->update(
                    'order_ship',
                    'onclick',
                    'confirmSetLocation(\'' . __(
                        'WARNING! THE INVOICE HAS NOT BEEN CAPTURED. ARE YOU SURE YOU WANT TO SHIP THIS ORDER WITHOUT PAYMENT?'
                    ) . '\', \'' . $this->getSourceSelectionUrl() . '\')'
                );
            }
        }
        return $this;
    }

}
