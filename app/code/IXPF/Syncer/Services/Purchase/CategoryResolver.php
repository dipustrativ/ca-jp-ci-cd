<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Purchase;

use Exception;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\PurchaseLogger;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\Store;

/**
 * Resolves "A/B/C" category path strings to category ids.
 * Missing levels are created on the fly, then the leaf id is returned.
 */
class CategoryResolver
{
    /**
     * Cache of resolved path prefixes → category id (per request).
     *
     * @var array<string, int>
     */
    private array $pathCache = [];

    public function __construct(
        protected CategoryCollectionFactory   $categoryCollectionFactory,
        protected CategoryInterfaceFactory    $categoryFactory,
        protected CategoryRepositoryInterface $categoryRepository,
        protected PurchaseLogger               $purchaseLogger,
        protected Config                       $config
    ) {
    }

    /**
     * Resolve many "A/B/C" paths to unique leaf category ids.
     * Failures are logged and skipped — never abort the product.
     *
     * @param string[] $paths
     * @return int[]
     */
    public function resolvePaths(array $paths): array
    {
        $ids = [];
        foreach ($paths as $path) {
            $path = trim((string)$path);
            if ($path === '') {
                continue;
            }
            try {
                $id = $this->resolvePath($path);
                if ($id) {
                    $ids[] = $id;
                }
            } catch (Exception $exception) {
                $this->purchaseLogger->writeDaily(
                    "Category path resolve failed [{$path}]: " . $exception->getMessage(),
                    'error'
                );
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Walk a single "A/B/C" path from root, find-or-create each level, return leaf id.
     *
     * @param string $path
     * @return int|null
     * @throws Exception
     */
    private function resolvePath(string $path): ?int
    {
        $segments = array_values(array_filter(
            array_map('trim', explode('/', $path)),
            static fn(string $segment): bool => $segment !== ''
        ));

        if (!$segments) {
            return null;
        }

        $parentId = $this->config->getPurchaseRootCategoryId();
        $currentId = null;
        $prefix = [];

        foreach ($segments as $name) {
            $prefix[] = $name;
            $cacheKey = implode('/', $prefix);

            if (isset($this->pathCache[$cacheKey])) {
                $currentId = $this->pathCache[$cacheKey];
                $parentId = $currentId;
                continue;
            }

            $childId = $this->findChildByName($parentId, $name)
                ?? $this->createChild($parentId, $name);

            $this->pathCache[$cacheKey] = $childId;
            $currentId = $childId;
            $parentId = $childId;
        }

        return $currentId;
    }

    /**
     * Find a direct child category by name under a parent. Null if absent.
     *
     * @param int $parentId
     * @param string $name
     * @return int|null
     */
    private function findChildByName(int $parentId, string $name): ?int
    {
        $collection = $this->categoryCollectionFactory->create();
        // Match against the admin/default name. The ERP payload sends default
        // (English) category names; store views (e.g. JP, store 1) override
        // names with translations. Without pinning scope the collection joins
        // the current store's name — in the frontend area that is the JP view,
        // so English paths never match, and the resolver wrongly tries to
        // create a duplicate (url_key collision → category skipped).
        $collection->setStoreId(Store::DEFAULT_STORE_ID);
        $collection->addAttributeToSelect('name')
            ->addFieldToFilter('parent_id', $parentId)
            ->addAttributeToFilter('name', $name)
            ->setPageSize(1);

        $category = $collection->getFirstItem();

        return $category->getId() ? (int)$category->getId() : null;
    }

    /**
     * Create a child category under a parent and return its id.
     *
     * @param int $parentId
     * @param string $name
     * @return int
     * @throws Exception
     */
    private function createChild(int $parentId, string $name): int
    {
        // NOTE: do NOT setPath() manually — CategoryRepository::save() derives
        // path/level/parent_id from the parent for new categories. Presetting
        // path suppresses the resource-model id-append (path stays as parent's,
        // leaf id + level never written → broken tree).
        // Field set mirrors Magento core import CategoryProcessor::createCategory().
        $category = $this->categoryFactory->create();
        $category->setName($name);
        $category->setParentId($parentId);
        $category->setIsActive(true);
        $category->setIsAnchor(true);
        $category->setIncludeInMenu(true);
        $category->setAttributeSetId($category->getDefaultAttributeSetId());

        $category = $this->categoryRepository->save($category);

        return (int)$category->getId();
    }
}
