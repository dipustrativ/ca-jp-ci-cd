<?php

namespace CarpetAvenue\Proforma\Block;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Module\Dir;
use Magento\Framework\View\Element\Template;

class Proforma extends Template
{
    protected \CarpetAvenue\Proforma\Model\Proforma $proforma;
    public function __construct(
        Template\Context $context,
        protected \Magento\Directory\Model\CountryFactory $countryFactory,
        protected Dir $moduleDir,
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
    /**
     * Carpet Avenue logo as an inline data URI, so mPDF needs no file or HTTP lookup.
     */
    public function getLogoDataUri(): string
    {
        $path = $this->moduleDir->getDir('CarpetAvenue_Proforma', Dir::MODULE_VIEW_DIR)
            . '/base/web/images/carpetavenue-logo.svg';

        return 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($path));
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
