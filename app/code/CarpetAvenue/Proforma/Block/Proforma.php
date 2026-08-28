<?php

namespace CarpetAvenue\Proforma\Block;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\View\Element\Template;

class Proforma extends Template
{
    protected \CarpetAvenue\Proforma\Model\Proforma $proforma;
    public function __construct(
        Template\Context $context,
        protected \Magento\Directory\Model\CountryFactory $countryFactory,
        array $data = []
    ){
        parent::__construct($context,$data);
    }
    public function setProforma(\CarpetAvenue\Proforma\Model\Proforma $proforma)
    {
        $this->proforma = $proforma;
    }
    public function getProforma()
    {
        return $this->proforma;
    }
    public function getCountryName($code){
        $country = $this->countryFactory->create()->loadByCode($code);
        return $country->getName();
    }
    public function getFormatedStreet(){
        return str_replace(PHP_EOL,'<br>', $this->proforma->getCustomerStreet()??'');
    }

    /**
     * Render block HTML
     *
     * @return string
     * @throws ValidatorException
     */
    protected function _toHtml()
    {
        if (!$this->getTemplate()) {
            $this->setTemplate('CarpetAvenue_Proforma::proforma.phtml');
        }
        return $this->fetchView($this->getTemplateFile());
    }
}
