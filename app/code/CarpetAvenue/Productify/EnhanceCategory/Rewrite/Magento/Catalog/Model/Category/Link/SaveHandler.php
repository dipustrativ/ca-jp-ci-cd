<?php


namespace CarpetAvenue\Productify\EnhanceCategory\Rewrite\Magento\Catalog\Model\Category\Link;

use CarpetAvenue\Productify\EnhanceCategory\Helper\Data;
use Magento\Catalog\Api\Data\CategoryLinkInterface;

class SaveHandler extends \Magento\Catalog\Model\Category\Link\SaveHandler
{

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CategoryLink
     */
    private $productCategoryLink;

    /**
     * @var \CarpetAvenue\Productify\EnhanceCategory\Helper\Data
     */
    private $dataHelper;

    /**
     * @var \Magento\Framework\EntityManager\HydratorPool
     */
    private $hydratorPool;

    /**
     * @var array
     */
    public $currentCategoryLinks;

    public function __construct(
        \Magento\Catalog\Model\ResourceModel\Product\CategoryLink $productCategoryLink,
        \Magento\Framework\EntityManager\HydratorPool $hydratorPool,
        Data $dataHelper
    ) {
        parent::__construct($productCategoryLink, $hydratorPool);
        $this->productCategoryLink = $productCategoryLink;
        $this->dataHelper = $dataHelper;
        $this->hydratorPool = $hydratorPool;
    }

    /**
     * Execute
     *
     * @param object $entity
     * @param array $arguments
     * @return object
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute($entity, $arguments = [])
    {
        $entity->setIsChangedCategories(false);

        $extensionAttributes = $entity->getExtensionAttributes();
        if ($extensionAttributes === null && !$entity->hasCategoryIds()) {
            return $entity;
        }

        $modelCategoryLinks = $this->getCategoryLinksPositions($entity);

        $dtoCategoryLinks = $extensionAttributes->getCategoryLinks();
        if ($dtoCategoryLinks !== null) {
            $hydrator = $this->hydratorPool->getHydrator(CategoryLinkInterface::class);
            $dtoCategoryLinks = array_map(function ($categoryLink) use ($hydrator) {
                return $hydrator->extract($categoryLink) ;
            }, $dtoCategoryLinks);

            if (!count($this->currentCategoryLinks)){
                $dtoCategoryLinks = $modelCategoryLinks;
            }else{
                $dtoCategoryLinks = array_filter($dtoCategoryLinks, function ($link) {
                    return isset($this->currentCategoryLinks[$link['category_id']]);
                });
            }

            $processLinks = $this->mergeCategoryLinks($dtoCategoryLinks, $modelCategoryLinks);
        } else {
            $processLinks = $modelCategoryLinks;
        }

        $affectedCategoryIds = $this->productCategoryLink->saveCategoryLinks($entity, $processLinks);

        if (!empty($affectedCategoryIds)) {
            $entity->setAffectedCategoryIds($affectedCategoryIds);
            $entity->setIsChangedCategories(true);
        }

        return $entity;
    }

    /**
     * Get category links positions
     *
     * @param object $entity
     * @return array
     */
    private function getCategoryLinksPositions($entity)
    {
        $result = [];
        $currentCategoryLinks = $this->productCategoryLink->getCategoryLinks($entity, $entity->getCategoryIds());
        $defaultPosition = $this->dataHelper->getDefaultCatalogProductPosition();
        foreach ($entity->getCategoryIds() as $categoryId) {
            $key = array_search($categoryId, array_column($currentCategoryLinks, 'category_id'));
            if ($key === false) {
                $result[] = ['category_id' => (int)$categoryId, 'position' => $defaultPosition];
            } else {
                $result[] = $currentCategoryLinks[$key];
            }
        }

        $this->currentCategoryLinks = [];
        foreach ($currentCategoryLinks as $link) {
            $this->currentCategoryLinks[$link['category_id']] = $link['position'];
        }

        return $result;
    }


    /**
     * Merge category links
     *
     * @param array $newCategoryPositions
     * @param array $oldCategoryPositions
     * @return array
     */
    private function mergeCategoryLinks($newCategoryPositions, $oldCategoryPositions)
    {
        if (empty($newCategoryPositions)) {
            return [];
        }

        $categoryPositions = array_combine(array_column($oldCategoryPositions, 'category_id'), $oldCategoryPositions);
        foreach ($newCategoryPositions as $newCategoryPosition) {
            $categoryId = $newCategoryPosition['category_id'];
            if (!isset($categoryPositions[$categoryId])) {
                $categoryPositions[$categoryId] = ['category_id' => $categoryId];
            }
            $categoryPositions[$categoryId]['position'] = $newCategoryPosition['position'];
        }
        $result = array_values($categoryPositions);

        return $result;
    }
}
