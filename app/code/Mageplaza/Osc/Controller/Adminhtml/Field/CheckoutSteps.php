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

namespace Mageplaza\Osc\Controller\Adminhtml\Field;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\LayoutFactory;
use Mageplaza\Osc\Block\Adminhtml\Field\CheckoutSteps as TemplateCheckoutSteps;

/**
 * Class CheckoutSteps
 * @package Mageplaza\Osc\Controller\Adminhtml\Field
 */
class CheckoutSteps extends Action
{
    /**
     * @var LayoutFactory
     */
    protected $layoutFactory;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @param Context $context
     * @param LayoutFactory $layoutFactory
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Context $context,
        LayoutFactory $layoutFactory,
        JsonFactory $resultJsonFactory,
    ) {
        $this->layoutFactory     = $layoutFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        parent::__construct($context);
    }

    /**
     * @return Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        $layout     = $this->layoutFactory->create();
        $block      = $layout->createBlock(TemplateCheckoutSteps::class);
        $data = [
            'block_html' => $block->toHtml(),
        ];

        return $resultJson->setData($data);
    }
}
