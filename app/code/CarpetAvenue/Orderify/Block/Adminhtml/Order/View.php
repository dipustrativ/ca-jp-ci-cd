<?php

namespace CarpetAvenue\Orderify\Block\Adminhtml\Order;

use Magento\Sales\Block\Adminhtml\Order\View as OrderView;

class View  extends OrderView
{
    /**
     * Constructor
     *
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function _construct()
    {
        parent::_construct();

        $order = $this->getOrder();
        if (!$order) {
            return;
        }

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $billingRegionEmpty = false;
        if (strtoupper($billingAddress->getCountryId()) == "IT" && $billingAddress->getRegion() == null) {
            $billingRegionEmpty = true;
        }
        $shippingRegionEmpty = false;
        if (strtoupper($shippingAddress->getCountryId()) == "IT" && $shippingAddress->getRegion() == null) {
            $shippingRegionEmpty = true;
        }

        if ($billingRegionEmpty || $shippingRegionEmpty) {
            $message = __("This order does not have a state assigned. Please assign the state first before proceeding with the invoice. ");
            $missingFrom = [];
            if ($billingRegionEmpty) {
                $missingFrom[] = '<a href="' . $this->getUrl('sales/order/address', ['address_id' => $billingAddress->getId()]) . '">Billing Address</a>';
            }
            if ($shippingRegionEmpty) {
                $missingFrom[] = '<a href="' . $this->getUrl('sales/order/address', ['address_id' => $shippingAddress->getId()]) . '">Shipping Address</a>';
            }

            if (!empty($missingFrom)) {
                $message .= sprintf(
                    __("Missing from: %s"),
                    implode(" and ", $missingFrom)
                );
            }

            if ($this->_isAllowedAction('Magento_Sales::invoice') && $order->canInvoice()) {
                $_label = $order->getForcedShipmentWithInvoice() ? __('Invoice and Ship') : __('Invoice');
                $onClick = "confirmSetLocation('{$message}', '#')";
                $this->addButton(
                    'order_invoice',
                    [
                        'label' => $_label,
                        //'onclick' => 'setLocation(\'' . $this->getInvoiceUrl() . '\')',
                        'onclick' => $onClick,
                        'class' => 'invoice'
                    ]
                );
            }

        }
    }
}