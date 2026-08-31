<?php

declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Widget;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;
use Magento\Catalog\Api\CategoryManagementInterface;

class CategoryList extends Template implements BlockInterface
{
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var \Magento\Catalog\Api\CategoryRepositoryInterface
     */
    private $categoryRepository;

    /** @var null|\Magento\Catalog\Model\Category */
    private $mainCategory = null;

    /**
     * @var CategoryManagementInterface
     */
    private $categoryManagement;

    private $currentDepth = 0;

    /**
     * CategoryList constructor.
     *
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory
     * @param \Magento\Catalog\Api\CategoryRepositoryInterface                $categoryRepository
     * @param CategoryManagementInterface                                      $categoryManagement
     * @param \Magento\Framework\View\Element\Template\Context                $context
     * @param array                                                            $data
     */
    public function __construct(
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        CategoryRepositoryInterface $categoryRepository,
        CategoryManagementInterface $categoryManagement,
        Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->categoryRepository = $categoryRepository;
        $this->categoryManagement = $categoryManagement;
    }

    public function _toHtml()
    {
        if ($this->getMainCategory() === null) {
            return "";
        }

        if (!count($this->getSubCategories())) {
            return "";
        }

        return parent::_toHtml();
    }

    public function getMainCategory()
    {
        if ($this->mainCategory !== null) {
            return $this->mainCategory;
        }

        $id = $this->getData('category_id');
        try {
            /** @var \Magento\Catalog\Model\Category $category */
            return $this->mainCategory = $this->categoryRepository->get($id);
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    /**
     * @return \Magento\Catalog\Model\Category[]
     */
    public function getSubCategories()
    {
        $amount = $this->getData('category_page_size');
        $depth = $this->getData('category_depth') ?? 1;

        if (($category = $this->getMainCategory()) === null) {
            return [];
        }

        $return = [];
        $c = 0;
        $c2 = 0;
        $c3 = 0;
        $c4 = 0;

        foreach ($category->getChildrenCategories() as $catId) {

            if (($cat = $this->getCategory($catId->getId()))) {

                if($cat->getIncludeInMenu() && $cat->getIsActive()) {
                    $return[] = $cat;
                    $c++;
                }

                if($depth > 1) {

                    // Level 2
                    foreach ($cat->getChildrenCategories() as $childId) {
                        if (($cat2 = $this->getCategory($childId->getId()))) {

                            if($cat2->getIncludeInMenu() && $cat2->getIsActive()) {
                                $return[] = $cat2;
                                $c2++;
                            }

                            if($depth > 2) {

                                // Level 3
                                foreach ($cat2->getChildrenCategories() as $childId2) {
                                    if (($cat3 = $this->getCategory($childId2->getId()))) {

                                        if($cat3->getIncludeInMenu() && $cat3->getIsActive()) {
                                            $return[] = $cat3;
                                            $c3++;
                                        }

                                        if($depth == 4) {

                                            // Level 4
                                            foreach ($cat3->getChildrenCategories() as $childId3) {
                                                if (($cat4 = $this->getCategory($childId3->getId()))) {

                                                    if($cat4->getIncludeInMenu() && $cat4->getIsActive()) {
                                                        $return[] = $cat4;
                                                        $c4++;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if ($c >= $amount) {
                break;
            }
        }


        return $return;
    }

    /**
     * @param $id
     *
     * @return \Magento\Catalog\Api\Data\CategoryInterface|null
     */
    private function getCategory($id)
    {
        try {
            return $this->categoryRepository->get($id);
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    /**
     * @return \Magento\Catalog\Model\Category[]
     */
    private function getSubCategoryRecursive($category, $depth, $maxDepth)
    {
        $return = [];

        foreach ($category->getChildrenCategories() as $childId) {
            if (($cat = $this->getCategory($childId->getId()))) {

                if($cat->getIncludeInMenu() && $cat->getIsActive()) {
                    $return[] = $cat;
                }

                $this->currentDepth = $depth;

                if($depth < $maxDepth) {
                    $return = $return + $this->getSubCategoryRecursive($cat, $depth + 1, $maxDepth);
                }
            }
        }

        return $return;
    }

    /**
     * Resolve the image to show for a category tile.
     *
     * Categories have no `thumbnail` attribute (that is a product attribute); the
     * standard category image lives in `image`. When a category has no image set,
     * fall back to a module placeholder so the tile is never blank.
     *
     * @param \Magento\Catalog\Api\Data\CategoryInterface|\Magento\Framework\DataObject $category
     * @return string
     */
    public function getCategoryImageUrl($category): string
    {
        // `thumbnail` is the custom category image field the admin uploads to
        // (added by Productify's AddCategoryImageAttributes patch); fall back to
        // the core `image` attribute, then to a placeholder so tiles are never blank.
        $image = $category->getData('thumbnail') ?: $category->getData('image');

        if (!empty($image)) {
            return $this->getImageUrl((string) $image, 200, 200);
        }

        return $this->getPlaceholderImageUrl();
    }

    /**
     * @return string
     */
    public function getPlaceholderImageUrl(): string
    {
        return $this->getViewFileUrl('CarpetAvenue_Widget::images/category-placeholder.svg');
    }

    public function getImageUrl(string $imagePath, ?int $width = null, ?int $height = null): string
    {
        $store = $this->_storeManager->getStore();
        $path = ltrim($imagePath, '/');

        // Thumbnail may be stored as a bare filename, as "catalog/category/x.jpg",
        // or as a full "media/catalog/category/x.jpg" path. Normalise so the media
        // path is never duplicated.
        if (strpos($path, 'media/') === 0) {
            return $store->getBaseUrl() . $path;
        }
        if (strpos($path, 'catalog/category/') === 0) {
            return $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . $path;
        }

        return $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/category/' . $path;
    }

}
