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

namespace Mageplaza\Osc\Model\Plugin\CustomerAttributes;

use Magento\Checkout\Model\Session;
use Magento\Customer\Model\AttributeMetadataDataProvider;
use Magento\Customer\Model\ResourceModel\Form\Attribute\Collection as AttributesCollection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager;
use Mageplaza\Osc\Helper\Data;

/**
 * Class AttributeMetadataDataProviderPlugin
 *
 * @package Mageplaza\Osc\Model\Plugin\CustomerAttributes
 */
class AttributeMetadataDataProviderPlugin
{
    /**
     * @var Data
     */
    private $helper;

    /**
     * @var Session
     */
    private $checkoutSession;

    /**
     * @var Manager
     */
    private $moduleManage;

    /**
     * AttributeMetadataDataProviderPlugin constructor.
     *
     * @param Data          $helper
     * @param Session       $session
     * @param Manager       $moduleManage
     */
    public function __construct(
        Data $helper,
        Session $session,
        Manager $moduleManage
    ) {
        $this->helper          = $helper;
        $this->moduleManage    = $moduleManage;
        $this->checkoutSession = $session;
    }

    /**
     * @param AttributeMetadataDataProvider $subject
     * @param AttributesCollection          $result
     * @return AttributesCollection
     */
    public function afterLoadAttributesCollection(AttributeMetadataDataProvider $subject, $result)
    {
        if (!$this->moduleManage->isEnabled('Mageplaza_CustomerAttributes')) {
            return $result;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (NoSuchEntityException $e) {
            return $result;
        } catch (LocalizedException $e) {
            return $result;
        }

        $storeId = $quote->getStoreId();
        if (!$quote->getId() || !$this->helper->isOscPage($storeId)) {
            return $result;
        }
        $customerGroup = $quote->getCustomerGroupId();
        $result->getSelect()->where(
            'mp_store_id is null or mp_store_id = 0 or FIND_IN_SET(?, mp_store_id)',
            $storeId
        )->where(
            'mp_customer_group is null or FIND_IN_SET(?, mp_customer_group)',
            $customerGroup
        );

        return $result;
    }
}
