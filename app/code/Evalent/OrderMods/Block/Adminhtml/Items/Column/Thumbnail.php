<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Block\Adminhtml\Items\Column;

use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\OptionFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Sales\Block\Adminhtml\Items\Column\DefaultColumn;

class Thumbnail extends DefaultColumn
{

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;

    protected $_assetRepo;

    private $_catalogHelper;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        Context $context,
        StockRegistryInterface $stockRegistry,
        StockConfigurationInterface $stockConfiguration,
        Registry $registry,
        OptionFactory $optionFactory,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Catalog\Helper\Product $catalogHelper,
        array $data = []
    ) {
        parent::__construct($context, $stockRegistry, $stockConfiguration,
            $registry, $optionFactory, $data);
        $this->productRepository = $productRepository;
        $this->_assetRepo = $assetRepo;
        $this->_catalogHelper = $catalogHelper;
    }

    /**
     * @return \Magento\Catalog\Api\Data\ProductInterface|null
     */
    public function getProduct()
    {
        try {
            $product = $this->productRepository->get($this->getSku());
            return $product;
        } catch (LocalizedException $e) {
            return null;
        }
    }

    public function getImageUrl()
    {
        $product = $this->getProduct();
        if (!$product) {
            return $this->_assetRepo->getUrl('Magento_Catalog::images/product/placeholder/thumbnail.jpg');
        }

        return $this->_catalogHelper->getThumbnailUrl($product);
    }

}
