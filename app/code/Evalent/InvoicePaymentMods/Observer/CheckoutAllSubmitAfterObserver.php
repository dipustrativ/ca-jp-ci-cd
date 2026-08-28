<?php
/**
 * Created by PhpStorm.
 * User: bassam
 * Date: 2018-10-30
 * Time: 10:53
 */

namespace Evalent\InvoicePaymentMods\Observer;

use Evalent\InvoicePaymentMods\Helper\Data;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Class CheckoutAllSubmitAfterObserver
 * @package Evalent\InvoicePaymentMods\Observer
 */
class CheckoutAllSubmitAfterObserver implements ObserverInterface
{
    /**
     *
     * @var Data
     */
    protected $helper;

    /**
     * @param Data $helper
     */
    public function __construct(
        Data $helper
    ) {
        $this->helper = $helper;
    }

    /**
     *
     * @param Observer $observer
     * @return $this
     */
    public function execute(Observer $observer)
    {
        if (!$this->helper->getConfigField('active')) {
            return $this;
        }
        if ($this->helper->getConfigField('auto_invoice')) {//Auto Invoice take on/off 0/1
            $order = $observer->getEvent()->getOrder();

            if ($order->getPayment()->getMethodInstance()->getCode() == 'evinvoicepayment') { //If EV order
                if (!$order->getId()) {
                    return $this;
                }

                $this->helper->createInvoice($order);
                return $this;
            }//Endif EV order
        }//End IF Auto Invoice -take on/off 0/1
    }
}