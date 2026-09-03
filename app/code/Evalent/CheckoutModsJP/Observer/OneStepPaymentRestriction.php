<?php

declare(strict_types=1);

namespace Evalent\CheckoutModsJP\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Restricts the payment methods offered in one-step checkout based on which cart
 * button the customer clicked:
 *  - "Bank Transfer"  -> only evinvoicepayment is available
 *  - "Other Methods"  -> evinvoicepayment is hidden
 * The flag is set by the OSC Router override; if it was never set, nothing changes.
 */
class OneStepPaymentRestriction implements ObserverInterface
{
    /**
     * @var Session
     */
    protected $checkoutSession;

    public function __construct(
        Session $checkoutSession
    ) {
        $this->checkoutSession = $checkoutSession;
    }

    public function execute(Observer $observer)
    {
        /** @var \Magento\Payment\Model\Method\AbstractMethod $methodInstance */
        $methodInstance = $observer->getMethodInstance();
        $result = $observer->getResult();
        if ($this->checkoutSession->getIsBankTransfer() === 1) {
            if ($methodInstance->getCode() != 'evinvoicepayment') {
                $result->setIsAvailable(false);
            }
        } elseif ($this->checkoutSession->getIsBankTransfer() === 0) {
            if ($methodInstance->getCode() == 'evinvoicepayment') {
                $result->setIsAvailable(false);
            }
        }
    }
}
