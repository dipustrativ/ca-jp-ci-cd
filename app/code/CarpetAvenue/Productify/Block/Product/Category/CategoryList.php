<?php

namespace CarpetAvenue\Productify\Block\Product\Category;

use Magento\Framework\View\Element\Template;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use \Magento\Catalog\Model\CategoryRepository;

class CategoryList extends \Magento\Framework\View\Element\Template
{

    public function __construct(
        Template\Context $context,
        protected LayerResolver $_layerResolver,
        protected CollectionFactory $_categoryCollectionFactory,
        protected CategoryRepository $_categoryRepository,
        array $data = []
    )
    {
        parent::__construct($context, $data);
    }

    /**
     * Get current category
     */
    public function getCurrentCategory()
    {
        return $this->_layerResolver->get()->getCurrentCategory();
    }

    public function getCurrentCategoryName()
    {
        return $this->_layerResolver->get()->getCurrentCategory()->getName();
    }

    /**
     * Get subcategories
     */
    public function getSubcategories()
    {
        $storeId = $this->_storeManager->getStore()->getId();
        $category = $this->getCurrentCategory();

        $collection = $this->_categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'thumbnail', 'thumbnail_image_path', 'url_key', 'custom_url'])
            ->addAttributeToFilter('parent_id', $category->getId())
            ->addAttributeToFilter('is_active', 1)
            ->setStoreId($storeId)
            ->setOrder('position', 'ASC');

        return $collection;
    }

    /**
     * Get the "Carled" image url for specified image
     *
     * @param string   $imagePath
     * @param int|null $width
     * @param int|null $height
     *
     * @return string
     */
    public function getImageUrl(string $imagePath , ?int $width = null, ?int $height = null): string
    {
        if ($imagePath == '' || $imagePath == null) {
            return '';
        }

        $store = $this->_storeManager->getStore();
        $path = ltrim($imagePath, '/');

        // Thumbnail may already contain "media/catalog/category/" or
        // "catalog/category/" — normalise so the media path is not duplicated.
        if (strpos($path, 'media/') === 0) {
            return $store->getBaseUrl() . $path;
        }
        if (strpos($path, 'catalog/category/') === 0) {
            return $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . $path;
        }

        return $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/category/' . $path;

    }
}