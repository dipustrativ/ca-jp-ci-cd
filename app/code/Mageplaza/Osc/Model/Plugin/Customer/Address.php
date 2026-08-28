<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Osc
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\Osc\Model\Plugin\Customer;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Checkout\Model\Session;
use Magento\Eav\Model\Config;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class Address
 * @package Mageplaza\Osc\Model\Plugin\Customer
 */
class Address
{

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var Config
     */
    private $config;

    /**
     * AccountManagement constructor.
     *
     * @param Session $checkoutSession
     * @param Config $config
     */
    public function __construct(Session $checkoutSession, Config $config)
    {
        $this->checkoutSession = $checkoutSession;
        $this->config = $config;
    }

    /**
     * @param \Magento\Customer\Model\Address $subject
     * @param \Magento\Customer\Model\Address $result
     *
     * @return \Magento\Customer\Model\Address
     */
    public function afterUpdateData(\Magento\Customer\Model\Address $subject, $result)
    {
        $result->setShouldIgnoreValidation(true);

        return $result;
    }

    /**
     * @param \Magento\Customer\Model\Address $subject
     * @param AddressInterface $address
     * @return AddressInterface[]
     * @throws LocalizedException
     */
    public function beforeUpdateData(\Magento\Customer\Model\Address $subject, AddressInterface $address)
    {
        $oscData = $this->checkoutSession->getOscData();
        if (is_array($oscData) && array_key_exists('customerAttributes', $oscData)
            && count($oscData['customerAttributes'])) {
            foreach ($oscData['customerAttributes'] as $key => $value) {
                if ($this->config->getAttribute('customer_address', $key)) {
                    $address->setCustomAttribute($key, $value);
                }
            }
        }

        $customAttributes = $address->getCustomAttributes();
        foreach ($customAttributes as $key => $attribute) {
            if (($key === 'mposc_field_1' || $key === 'mposc_field_2' || $key === 'mposc_field_3') && !$attribute) {
                $address->setCustomAttribute($key, '');
            }
        }

        return [$address];
    }
}
