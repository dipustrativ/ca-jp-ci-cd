<?php
namespace Evalent\MatrixRateMods\Block\Adminhtml\Form\Field;
class TrackingCountries extends \Magento\Framework\View\Element\Html\Select{
    protected $countryCollectionFactory;
    public function __construct(
        \Magento\Directory\Model\ResourceModel\Country\CollectionFactory $countryCollectionFactory,
        \Magento\Framework\View\Element\Template\Context $context
    ) {
        parent::__construct($context);
        $this->countryCollectionFactory = $countryCollectionFactory;
    }

    public function setInputName($value)
    {
        return $this->setName($value);
    }

    /**
     * Parse to html.
     *
     * @return mixed
     */
    public function _toHtml()
    {
        if (!$this->getOptions()) {
            foreach ($this->countryCollectionFactory->create()->loadByStore()->toOptionArray() as $option){
                $this->addOption($option['value'],$option['label']);
            }
        }
        return parent::_toHtml();
    }
}