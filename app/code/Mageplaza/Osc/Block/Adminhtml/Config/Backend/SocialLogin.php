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

namespace Mageplaza\Osc\Block\Adminhtml\Config\Backend;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field as FormField;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Module\FullModuleList;
use Mageplaza\Osc\Helper\Data;

/**
 * Class DeleteOrder
 * @package Mageplaza\EditOrder\Block\Adminhtml\Config
 */
class SocialLogin extends FormField
{
    /**
     * @var
     */
    protected $element;

    /**
     * @var FullModuleList
     */
    protected $moduleList;
    /**
     * @var Data
     */
    protected $helper;

    /**
     * @param Context $context
     * @param FullModuleList $moduleList
     * @param Data $helper
     * @param array $data
     */
    public function __construct(
        Context $context,
        FullModuleList $moduleList,
        Data $helper,
        array $data = []
    ) {
        $this->moduleList = $moduleList;
        $this->helper     = $helper;
        parent::__construct($context, $data);
    }

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->setTemplate('Mageplaza_Osc::system/config/socialLogin.phtml');
        parent::_construct();
    }

    /**
     * Return element html
     *
     * @param AbstractElement $element
     *
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $this->element = $element;

        return $this->_toHtml();
    }

    /**
     *
     * @return boolean
     */
    public function checkModuleSocialLogin()
    {
        return $this->moduleList->has('Mageplaza_SocialLogin');
    }
    /**
     *
     * @return boolean
     */
    public function checkEnableModuleSocialLogin()
    {
        return $this->helper->isModuleOutputEnabled('Mageplaza_SocialLogin') && $this->helper->getConfigValue('sociallogin/general/enabled');
    }
}
