<?php

namespace Evalent\ProductCertificate\Block;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\View\Element\Template;

class Certificate extends Template
{
    /** @var Product[] $_products */
    protected $_products;

    /** @var StoreId $_storeId */
    protected $_storeId;

    /**
     * @param Product[] $products
     */
    public function setProducts(array $products)
    {
        $this->_products = $products;
    }

    /**
     * @return Product[]
     */
    public function getProducts()
    {
        return $this->_products;
    }

    /**
     * @param StoreId[] $storeId
     */
    public function setStoreId(int $storeId)
    {
        $this->_storeId = $storeId;
    }

    public function getStoreId()
    {
        return $this->_storeId;
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
            $this->setTemplate('Evalent_ProductCertificate::certificate.phtml');
        }
        return $this->fetchView($this->getTemplateFile());
    }
}
