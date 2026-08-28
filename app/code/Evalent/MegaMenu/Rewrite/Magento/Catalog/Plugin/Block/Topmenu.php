<?php


namespace Evalent\MegaMenu\Rewrite\Magento\Catalog\Plugin\Block;

use Evalent\MegaMenu\Helper\Data;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Tree\Node;

class Topmenu extends \Magento\Catalog\Plugin\Block\Topmenu
{

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\StateDependentCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var \Evalent\MegaMenu\Helper\Data
     */
    private $dataHelper;

    /**
     * @var \Magento\Catalog\Api\CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Catalog\Model\Layer\Resolver
     */
    private $layerResolver;

    public function __construct(
        Data $dataHelper,
        CategoryRepositoryInterface $categoryRepository,
        \Magento\Catalog\Helper\Category $catalogCategory,
        \Magento\Catalog\Model\ResourceModel\Category\StateDependentCollectionFactory $categoryCollectionFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\Layer\Resolver $layerResolver
    ) {
        parent::__construct($catalogCategory, $categoryCollectionFactory, $storeManager);
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->dataHelper = $dataHelper;
        $this->categoryRepository = $categoryRepository;
        $this->storeManager = $storeManager;
        $this->catalogCategory = $catalogCategory;
        $this->layerResolver = $layerResolver;
    }

    /**
     * Build category tree for menu block.
     *
     * @param \Magento\Theme\Block\Html\Topmenu $subject
     * @param string                            $outermostClass
     * @param string                            $childrenWrapClass
     * @param int                               $limit
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @SuppressWarnings("PMD.UnusedFormalParameter")
     */
    public function beforeGetHtml(
        \Magento\Theme\Block\Html\Topmenu $subject,
        $outermostClass = '',
        $childrenWrapClass = '',
        $limit = 0
    ) {
        $rootId = $this->storeManager->getStore()->getRootCategoryId();
        $storeId = $this->storeManager->getStore()->getId();
        if (!$this->dataHelper->isEnabled()) {
            parent::beforeGetHtml($subject, $outermostClass, $childrenWrapClass, $limit);
            return;
        }
        $actualRootId = $this->dataHelper->getRootCategoryId();
        if (!$actualRootId || $actualRootId == $rootId) {
            parent::beforeGetHtml($subject, $outermostClass, $childrenWrapClass, $limit);
            return;
        }
        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $collection */
        $collection = $this->getCategoryTree($storeId, $rootId);
        $currentCategory = $this->getCurrentCategory();
        $mapping = [$actualRootId => $subject->getMenu()];  // use nodes stack to avoid recursion
        foreach ($collection as $category) {
            $categoryParentId = $category->getParentId();
            if (!isset($mapping[$categoryParentId])) {
                $parentIds = $category->getParentIds();
                foreach ($parentIds as $parentId) {
                    if (isset($mapping[$parentId])) {
                        $categoryParentId = $parentId;
                    }
                }
            }

            /** @var Node $parentCategoryNode */
            $parentCategoryNode = $mapping[$categoryParentId];

            $categoryNode = new Node(
                $this->getCategoryAsArray(
                    $category,
                    $currentCategory,
                    $category->getParentId() == $categoryParentId
                ),
                'id',
                $parentCategoryNode->getTree(),
                $parentCategoryNode
            );
            $parentCategoryNode->addChild($categoryNode);

            $mapping[$category->getId()] = $categoryNode; //add node in stack
        }
    }

    /**
     * Get current Category from catalog layer
     *
     * @return \Magento\Catalog\Model\Category
     */
    private function getCurrentCategory()
    {
        $catalogLayer = $this->layerResolver->get();

        if (!$catalogLayer) {
            return null;
        }

        return $catalogLayer->getCurrentCategory();
    }

    /**
     * Convert category to array
     *
     * @param \Magento\Catalog\Model\Category $category
     * @param \Magento\Catalog\Model\Category $currentCategory
     * @param bool $isParentActive
     * @return array
     */
    private function getCategoryAsArray($category, $currentCategory, $isParentActive)
    {
        $category = $this->categoryRepository->get($category->getId());
        return [
            'name' => $category->getName(),
            'id' => 'category-node-' . $category->getId(),
            'url' => $this->catalogCategory->getCategoryUrl($category),
            'has_active' => in_array((string)$category->getId(), explode('/', $currentCategory->getPath()), true),
            'is_active' => $category->getId() == $currentCategory->getId(),
            'is_category' => true,
            'is_parent_active' => $isParentActive,
            'image_url' => $this->getImageUrl($category->getImage() ?? ''),
            'thumbnail_image_url' => $this->getImageUrl($category->getThumbnail() ?? ''),
            'mobile_image_url' => $this->getImageUrl($category->getMobileImage() ?? ''),
            'additional_megamenu_data' => $category->getAdditionalMegamenuData(),
            'megamenu_badge' => $category->getMegamenuBadge(),
            'megamenu_badge_color' => $category->getMegamenuBadgeColor(),
            'background_color' => $category->getBackgroundColor(),
            'foreground_color' => $category->getForegroundColor(),
            'custom_block' => $category->getCustomBlock(),
            'custom_url' => $category->getCustomUrl(),
            'thumbnail_image_path' => $category->getThumbnail() ?? '',
            'showall_custom_label' => $category->getShowallCustomLabel()
        ];
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
    public function getImageUrl(string $imagePath, ?int $width = null, ?int $height = null): string
    {
        if ($imagePath == '') {
            return '';
        }

        // Mage-OS stores the attribute value as a full "/media/catalog/category/<file>"
        // path (the base project stored just the file name) — reduce to the file name
        // so the prefix isn't duplicated.
        $imagePath = basename($imagePath);

        return $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA).'catalog/category/'.$imagePath;

    }

    /**
     * Get Category Tree
     *
     * @param int $storeId
     * @param int $rootId
     * @return \Magento\Catalog\Model\ResourceModel\Category\Collection
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getCategoryTree($storeId, $rootId)
    {
        if (!$this->dataHelper->isEnabled()) {
            return parent::getCategoryTree($storeId, $rootId);
        }
        $actualRootId = $this->dataHelper->getRootCategoryId();
        if (!$actualRootId || $actualRootId == $rootId) {
            return parent::getCategoryTree($storeId, $rootId);
        }
        /** @var \Magento\Catalog\Model\Category $category */
        $category = $this->categoryRepository->get($actualRootId);
        $parentIds = $category->getParentIds();
        // The chosen root category must be child of the store root category
        if (!in_array($rootId, $parentIds)) {
            return parent::getCategoryTree($storeId, $rootId);
        }

        $level = $category->getLevel();
        $path = "/" . $category->getId();
        for ($i = 1; $i < $level - 1; $i++) {
            $path = "/" . $parentIds[sizeof($parentIds) - $i] .$path;
        }
        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $collection */
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect('name');
        $collection->addFieldToFilter('path', ['like' => '1/' . $rootId .  $path . '/%']); //load only from store root
        $collection->addAttributeToFilter('include_in_menu', 1);
        $collection->addIsActiveFilter();
        $collection->addNavigationMaxDepthFilter();
        $collection->addUrlRewriteToResult();
        $collection->addOrder('level', Collection::SORT_ORDER_ASC);
        $collection->addOrder('position', Collection::SORT_ORDER_ASC);
        $collection->addOrder('parent_id', Collection::SORT_ORDER_ASC);
        $collection->addOrder('entity_id', Collection::SORT_ORDER_ASC);

        return $collection;
    }

    /**
     * Add list of associated identities to the top menu block for caching purposes.
     *
     * @param \Magento\Theme\Block\Html\Topmenu $subject
     * @return void
     */
    public function beforeGetIdentities(\Magento\Theme\Block\Html\Topmenu $subject)
    {
        $subject->addIdentity(Category::CACHE_TAG);
        $rootId = $this->storeManager->getStore()->getRootCategoryId();
        $storeId = $this->storeManager->getStore()->getId();
        if (!$this->dataHelper->isEnabled()) {
            parent::beforeGetIdentities($subject);
            return;
        }
        $actualRootId = $this->dataHelper->getRootCategoryId();
        if (!$actualRootId || $actualRootId == $rootId) {
            parent::beforeGetIdentities($subject);
            return;
        }
        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $collection */
        $collection = $this->getCategoryTree($storeId, $rootId);
        $mapping = [$actualRootId => $subject->getMenu()];  // use nodes stack to avoid recursion
        foreach ($collection as $category) {
            if (!isset($mapping[$category->getParentId()])) {
                continue;
            }
            $subject->addIdentity(Category::CACHE_TAG . '_' . $category->getId());
        }
    }
}
