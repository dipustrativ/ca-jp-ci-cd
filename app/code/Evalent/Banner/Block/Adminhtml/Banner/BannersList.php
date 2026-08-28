<?php

declare(strict_types=1);

namespace Evalent\Banner\Block\Adminhtml\Banner;

use Evalent\Banner\Block\Adminhtml\Banner\Widget\Rows;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Factory;
use Magento\Framework\View\LayoutInterface;

class BannersList extends Template
{

    protected $_elementFactory;

    protected $layout;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Framework\View\LayoutInterface $layout
     * @param \Magento\Framework\Data\Form\Element\Factory $elementFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        LayoutInterface $layout,
        Factory $elementFactory,
        array $data = []
    ) {
        $this->_elementFactory = $elementFactory;
        $this->_layout = $layout;
        parent::__construct($context, $data);
    }

    /**
     * Prepare element HTML
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element Form Element
     * @return \Magento\Framework\Data\Form\Element\AbstractElement
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function prepareElementHtml(AbstractElement $element)
    {

        $uniqId = $this->mathRandom->getUniqueHash($element->getId());

        $elements = $this->_layout->createBlock(Rows::class)
            ->setElement($element)
            ->setConfig($this->getConfig())
            ->setFieldsetId($this->getFieldsetId())
            ->setUniqId($uniqId);

        $element->setData('after_element_html', $elements->toHtml());
        return $element;
    }

}