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

class Weight extends DefaultColumn
{

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        Context $context,
        StockRegistryInterface $stockRegistry,
        StockConfigurationInterface $stockConfiguration,
        Registry $registry,
        OptionFactory $optionFactory,
        array $data = []
    ) {
        parent::__construct($context, $stockRegistry, $stockConfiguration,
            $registry, $optionFactory, $data);
        $this->productRepository = $productRepository;
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


}