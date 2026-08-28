<?php
namespace Evalent\DynamicSimilarProducts\Block\Html;

class Title extends \Magento\Theme\Block\Html\Title{

    protected \Evalent\DynamicSimilarProducts\Helper\Data $dataHelper;
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Evalent\DynamicSimilarProducts\Helper\Data $dataHelper,
        $data = []
    ){
        parent::__construct($context,$scopeConfig,$data);
        $this->dataHelper = $dataHelper;
    }

    public function getPageHeading(){
        if ($similarTo = $this->dataHelper->getSimilarToProduct()){
            /*$attrStr = '';
            if(($attr = $similarTo->getResource()->getAttribute('color')) && ($values = $similarTo->getData('color'))){
                foreach (explode(',',$values) as $value){
                    $attrStr .= ','.$attr->getSource()->getOptionText($value);
                }
            }
            if(($attr = $similarTo->getResource()->getAttribute('size_group')) && ($value = $similarTo->getData('size_group'))){
                $attrStr .= ','.$attr->getSource()->getOptionText($value);
            }*/
            return __("Similar Products to %1", $similarTo->getSku());//.' '.trim($attrStr,',');
        }
        return '';
    }
}