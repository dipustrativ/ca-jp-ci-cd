<?php

declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Widget;

use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Widget\Block\BlockInterface;

/**
 * Renders a hand-picked list of products (by comma-separated SKUs) as a slider.
 * No title — only the product carousel.
 */
class ProductSkuSlider extends AbstractProduct implements BlockInterface
{
    protected $_template = 'CarpetAvenue_Widget::product/sku_slider.phtml';

    /** @var Collection|null */
    private $itemCollection = null;

    /** @var Product[] */
    private $orderedItems = [];

    /**
     * @param Context $context
     * @param CollectionFactory $productCollectionFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly Visibility $productVisibility,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _construct()
    {
        parent::_construct();
        $this->addData(['cache_lifetime' => 3600]);
    }

    public function getCacheKeyInfo()
    {
        return [
            'CARPET_PRODUCT_SKU_SLIDER_WIDGET',
            $this->_storeManager->getStore()->getId(),
            $this->_design->getDesignTheme()->getId(),
            $this->getData('title'),
            $this->getData('view_all_url'),
            $this->getData('skus'),
            $this->getSlidesToShow(),
        ];
    }

    /**
     * Resolved "View all" URL, or '' when not configured.
     * Absolute URLs pass through; anything else is treated as store-relative.
     *
     * @return string
     */
    public function getViewAllUrl(): string
    {
        $url = trim((string) $this->getData('view_all_url'));
        if ($url === '') {
            return '';
        }

        if (preg_match('~^(https?:)?//~i', $url)) {
            return $url;
        }

        return $this->_storeManager->getStore()->getBaseUrl() . ltrim($url, '/');
    }

    /**
     * Optional heading, translated for the current store view.
     *
     * @return string
     */
    public function getHeading(): string
    {
        $title = trim((string) $this->getData('title'));

        return $title === '' ? '' : (string) __($title);
    }

    /**
     * Parsed, de-duplicated SKU list in the order the admin entered them.
     *
     * @return string[]
     */
    public function getSkus(): array
    {
        $raw = (string) $this->getData('skus');
        if ($raw === '') {
            return [];
        }

        $skus = array_map('trim', explode(',', $raw));
        $skus = array_filter($skus, static fn ($sku) => $sku !== '');

        return array_values(array_unique($skus));
    }

    /**
     * Number of products shown at once; the rest become slides.
     *
     * @return int
     */
    public function getSlidesToShow(): int
    {
        $count = (int) $this->getData('slides_to_show');

        return $count > 0 ? $count : 5;
    }

    /**
     * Product collection filtered to the configured SKUs.
     *
     * @return Collection
     */
    public function getProductCollection(): Collection
    {
        if ($this->itemCollection !== null) {
            return $this->itemCollection;
        }

        /** @var Collection $collection */
        $collection = $this->productCollectionFactory->create();
        $skus = $this->getSkus();

        if (empty($skus)) {
            // Force an empty (but valid) collection.
            $collection->addFieldToFilter('sku', ['null' => true]);
            return $this->itemCollection = $collection;
        }

        $collection
            ->addAttributeToSelect('*')
            ->setStoreId($this->_storeManager->getStore()->getId())
            ->addStoreFilter()
            ->setVisibility($this->productVisibility->getVisibleInSiteIds())
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->addFieldToFilter('sku', ['in' => $skus]);

        // Preserve the admin-entered SKU order (collection returns DB order).
        $bySku = [];
        foreach ($collection as $product) {
            $bySku[strtolower((string) $product->getSku())] = $product;
        }

        $ordered = [];
        foreach ($skus as $sku) {
            $key = strtolower($sku);
            if (isset($bySku[$key])) {
                $ordered[] = $bySku[$key];
            }
        }
        $this->orderedItems = $ordered;

        return $this->itemCollection = $collection;
    }

    /**
     * Products in the exact order the SKUs were entered.
     *
     * @return Product[]
     */
    public function getOrderedItems(): array
    {
        $this->getProductCollection();

        return $this->orderedItems;
    }

    public function getChildBlock($alias)
    {
        if ($alias !== 'addto') {
            return parent::getChildBlock($alias);
        }

        return $this->getLayout()->getBlock('categoryproductlistwidget.addto');
    }
}
