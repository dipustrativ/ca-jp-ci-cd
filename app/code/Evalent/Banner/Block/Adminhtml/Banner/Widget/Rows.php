<?php

declare(strict_types=1);

namespace Evalent\Banner\Block\Adminhtml\Banner\Widget;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

/**
 * Class Rows
 *
 * @package Evalent\Banner\Block\Adminhtml
 */
class Rows extends AbstractFieldArray
{

    /**
     * @var string Template
     */
    protected $_template = 'widget/banner/banner_list.phtml';

    /**
     * @var
     */
    protected $cmsBlockOptions;

    /**
     * @var
     */
    protected $columnsBlock;
    protected $animationEffect;


    /**
     * Rows cache
     *
     * @var array|null
     */
    private $arrayRowsCache;

    /**
     * Prepare to render
     */
    protected function _prepareToRender()
    {
        $config = $this->getConfig();

        if (isset($config['columns']) && is_array($config['columns']) && $config['columns']) {
            foreach ($config['columns'] as $colKey => $col) {
                $this->addColumn($colKey, array(
                    'label' => isset($col['label']) ? $col['label'] : $colKey,
                ));
            }

        } else {

            $this->addColumn('banner', array(
                'label' => __('Banner'),
                'class' => 'ev-banner-item',
                'renderer' => $this->_getBannersRenderer()
            ));

            $this->addColumn('column', array(
                'label' => __('Column'),
                'class' => 'ev-banner-item widget-option input-text admin__control-text',
                'renderer' => $this->getColumnsRenderer()
            ));

            $this->addColumn('animationEffect', array(
                'label' => __('Animation'),
                'class' => 'ev-banner-item widget-option input-text admin__control-text',
                'renderer' => $this->getAnimationEffect()
            ));

        }

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add banner');
    }

    /**
     * @return \Magento\Framework\View\Element\BlockInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _getBannersRenderer()
    {
        if (!$this->cmsBlockOptions) {
            $this->cmsBlockOptions = $this->getLayout()->createBlock(
                Options::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $this->cmsBlockOptions->setClass('widget-option select admin__control-select ev-banner-item');
        }
        return $this->cmsBlockOptions;
    }

    /**
     * @return \Magento\Framework\View\Element\BlockInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getColumnsRenderer()
    {
        if (!$this->columnsBlock) {
            $this->columnsBlock = $this->getLayout()->createBlock(
                BannerColumns::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $this->columnsBlock->setClass('widget-option select admin__control-select ev-banner-item');
        }
        return $this->columnsBlock;
    }


    /**
     * @return \Magento\Framework\View\Element\BlockInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getAnimationEffect()
    {
        if (!$this->animationEffect) {
            $this->animationEffect = $this->getLayout()->createBlock(
                BannerAnimationsEffect::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $this->animationEffect->setClass('widget-option select admin__control-select ev-banner-item');
        }
        return $this->animationEffect;
    }

    /**
     * Obtain existing data from form element
     *
     * Each row will be instance of \Magento\Framework\DataObject
     *
     * @return array
     */
    public function getArrayRows()
    {
        if (null !== $this->arrayRowsCache) {
            return $this->arrayRowsCache;
        }
        $result = [];
        /** @var \Magento\Framework\Data\Form\Element\AbstractElement */
        $element = $this->getElement();

        if ($element->getValue()) {
            $jsonValue = json_decode(base64_decode($element->getValue()), true);

            if (is_array($jsonValue)) {
                foreach ($jsonValue as $rowId => $row) {
                    $rowColumnValues = [];

                    foreach ($row as $key => $value) {
                        $row[$key] = $value;
                        $rowColumnValues[$this->_getCellInputElementId($rowId, $key)] = $row[$key];
                    }

                    $row['_id'] = $rowId;
                    $row['column_values'] = $rowColumnValues;
                    $row['option_extra_attrs'] = "";


                    $result[$rowId] = new DataObject($row);
                    $this->_prepareArrayRow($result[$rowId]);

                }
            }
        }
        $this->arrayRowsCache = $result;
        return $this->arrayRowsCache;
    }

}
