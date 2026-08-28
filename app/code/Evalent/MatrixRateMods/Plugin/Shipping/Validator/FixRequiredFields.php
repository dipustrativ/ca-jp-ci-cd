<?php

declare(strict_types=1);

namespace Evalent\MatrixRateMods\Plugin\Shipping\Validator;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ValidationRules\ShippingAddressValidationRule;

class FixRequiredFields
{

    /**
     * @var \Magento\Customer\Model\Address\Validator\General
     */
    private $generalValidator;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $quoteRepository;

    public function __construct(
        \Magento\Customer\Model\Address\Validator\General $generalValidator,
        CartRepositoryInterface $quoteRepository
    )
    {

        $this->generalValidator = $generalValidator;
        $this->quoteRepository = $quoteRepository;
    }

    public function beforeValidate(ShippingAddressValidationRule $subject, Quote $quote)
    {
        if ($quote->getPayment()->getMethod() != "klarna_kco") {
            return [$quote];
        }
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress = $quote->getBillingAddress();
        if ($shippingAddress && $billingAddress) {
            $shippingErrors = $this->generalValidator->validate($shippingAddress);
            $billingErrors = $this->generalValidator->validate($billingAddress);
            if (!empty($shippingErrors) && empty($billingErrors)) {
                $shippingAddress->setFirstname($billingAddress->getFirstname());
                $shippingAddress->setLastname($billingAddress->getLastname());
                $shippingAddress->setStreetFull($billingAddress->getStreetFull());
                $shippingAddress->setCity($billingAddress->getCity());
                $quote->setShippingAddress($shippingAddress);
                $this->quoteRepository->save($quote);
            }
        }
        return [$quote];
    }
}