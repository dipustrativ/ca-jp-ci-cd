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

namespace Mageplaza\Osc\Block\Adminhtml\Field;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Mageplaza\OrderAttributes\Helper\Data as oaHelper;
use Mageplaza\OrderAttributes\Model\Attribute;

/**
 * Class CheckoutSteps
 * @package Mageplaza\Osc\Block\Adminhtml\Field
 */
class CheckoutSteps extends AbstractOrderField
{
    const BLOCK_ID = 'mposc-checkout-step';
    protected $_template = 'Mageplaza_Osc::field/orderAttributes/position.phtml';

    /**
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getFields()
    {
        if (!$this->isVisible() || !$this->getCodeCheckoutStep()) {
            return [[], []];
        }

        /** @var oaHelper $oaHelper */
        $oaHelper = $this->helper->getObject(oaHelper::class);

        $availFields = [];
        $sortedFields = [];
        $sortOrder = 1;

        foreach ($oaHelper->getOrderAttributesCollection(null, null, false) as $field) {
            if ($field->getPosition() === $this->getCodeCheckoutStep()) {
                $availFields[] = $field;
            }
        }
        $oaFields = $this->helper->getOAFieldPosition();

        usort($oaFields, function ($a, $b) {
            return ($a['bottom'] <= $b['bottom']) ? -1 : 1;
        });

        foreach ($oaFields as $field) {
            /** @var Attribute $avField */
            foreach ($availFields as $key => $avField) {
                if ($field['code'] === $avField->getAttributeCode() && $avField->getPosition() === $this->getCodeCheckoutStep()) {
                    unset($availFields[$key]);
                    $avField
                        ->setColspan($field['colspan'])
                        ->setSortOrder($sortOrder++)
                        ->setColStyle($this->helper->getColStyle($field['colspan']))
                        ->setIsRequired($field['required'])
                        ->setIsRequiredMp($field['required']);
                    $sortedFields[] = $avField;
                    break;
                }
            }
        }
        $this->sortedFields = $sortedFields;
        return [$this->sortedFields, $availFields];
    }

    /**
     * @return string
     */
    public function getCodeCheckoutStep()
    {
        return $this->getRequest()->getParam('codeCheckoutSteps', false);
    }

    /**
     * @return bool
     */
    public function hasFields()
    {
        return count($this->availableFields) > 0 || count($this->sortedFields) > 0;
    }
    /**
     * @return string
     */
    public function getBlockTitle()
    {
        return (string)__('Checkout Steps');
    }
}
