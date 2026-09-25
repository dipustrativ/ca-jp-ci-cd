<?php

namespace CarpetAvenue\Productify\Service;

use CarpetAvenue\Productify\Helper\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use CarpetAvenue\Productify\Helper\Logger;
use Magento\Catalog\Model\ResourceModel\Product\Action;
use CarpetAvenue\Productify\Model\ResourceModel\ProductifyProductChangelogFactory;

class ProductUrlRewrite
{
    public function __construct(
        protected ResourceConnection         $resourceConnection,
        protected ProductRepositoryInterface $productRepository,
        protected ProductUrlPathGenerator    $productUrlPathGenerator,
        protected ProductUrlRewriteGenerator $productUrlRewriteGenerator,
        protected CategoryRepositoryInterface $categoryRepository,
        protected ProductifyProductChangelogFactory $productifyProductChangelogFactory,
        protected UrlPersistInterface        $urlPersist,
        protected Action                     $action,
        protected Logger                     $logger,
        protected Config                     $config
    )
    {

    }

    /**
     * Regenerate URL keys for products whose name changed since the last build
     *
     * @param array $products Rows as built by CollectionBuilder::buildForStore()
     * @param callable|null $onProgress Called as ($done, $total): once with 0, then per product
     */
    public function init(array $products, ?callable $onProgress = null)
    {
        if (!$this->config->isUpdateProductUrlRewriteEnabled()) {
            return;
        }

        $storedProducts = $this->getStoredProducts($products);
        $total = count($products);
        $done = 0;
        if ($onProgress) {
            $onProgress($done, $total);
        }
        foreach ($products as $product) {
            if ($onProgress) {
                $onProgress(++$done, $total);
            }
            try {
                if (!isset($product['id']) && !isset($product['store_id'])) {
                    continue;
                }

                $isProductUpdate = $this->checkProductNameUpdated(
                    $product['id'],
                    $product['store_id'],
                    $product['name'],
                    $storedProducts[$product['id'] . ':' . $product['store_id']] ?? []
                );
                if ($isProductUpdate) {
                    $this->setUrlKeyByStore($product['id'], $product['store_id']);
                }
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }

    /**
     * Name and sku from the previous build, per "id:store_id"
     *
     * One query per 1000 products instead of one per product. Ordered by entity_id so
     * a duplicated (id, store_id) resolves to the same row the old per-product
     * fetchRow() returned.
     *
     * @param array $products
     * @return array<string, array{name: string, sku: string}>
     */
    private function getStoredProducts(array $products): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('carpetavenue_productify_collection');
        $storeIds = array_values(array_unique(array_column($products, 'store_id')));
        if (!$storeIds) {
            return [];
        }

        $storedProducts = [];
        foreach (array_chunk(array_column($products, 'id'), 1000) as $ids) {
            $select = $connection->select()
                ->from($table, ['id', 'store_id', 'name', 'sku'])
                ->where('store_id IN (?)', $storeIds)
                ->where('id IN (?)', $ids)
                ->order('entity_id ASC');
            foreach ($connection->fetchAll($select) as $row) {
                $storedProducts[$row['id'] . ':' . $row['store_id']] ??= [
                    'name' => $row['name'],
                    'sku' => $row['sku'],
                ];
            }
        }
        return $storedProducts;
    }

    /**
     * @param int $productId
     * @param int $storeId
     * @param string $currentProductName
     * @param array $productifyProduct Stored name and sku; empty when the product was never built
     * @return bool
     */
    protected function checkProductNameUpdated(
        int $productId,
        int $storeId,
        string $currentProductName,
        array $productifyProduct
    ) {
        if (empty($productifyProduct)) {
            return false;
        }

        if (trim($productifyProduct['name']) != trim($currentProductName)) {
            //-- add log change product name
            $this->productifyProductChangelogFactory->create()->addNewLog([
                'id' => $productId,
                'store_id' => $storeId,
                'sku' => $productifyProduct['sku'],
                'name' => $currentProductName,
                'previous_name' => $productifyProduct['name']
            ]);

            return true;
        }

        return false;
    }


    /**
     * @param int $productId
     * @param int $storeId
     *
     * @return bool
     */
    private function setUrlKeyByStore(int $productId, int $storeId): bool
    {

        try {
            $storeSpecificProduct = $this->productRepository->getById($productId, true, $storeId, true);
            $storeSpecificProduct->setStoreId($storeId);
            $urlTemplate = $this->config->getUrlRewriteTemplate();

            //UpdateURL
            $parsedUrlKey = $this->parseTemplate($storeSpecificProduct, $urlTemplate);
            if ($parsedUrlKey != "" && $parsedUrlKey != $storeSpecificProduct->getUrlKey()) {
                $this->logger->info(__("Updating url_key on store id %1 for product %2 ", $storeId, $productId));
                $this->logger->info($parsedUrlKey);

                $this->action->updateAttributes([$productId],
                    ['url_key' => $parsedUrlKey], $storeId);
                $storeSpecificProduct->setUrlKey($parsedUrlKey);

                $storeSpecificProduct->unsUrlPath();
                $path = $this->productUrlPathGenerator->getUrlPath($storeSpecificProduct);
                $storeSpecificProduct->setUrlPath($path);
                try {
                    $this->urlPersist->replace($this->productUrlRewriteGenerator->generate($storeSpecificProduct));
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage());
                }
            } else {
                // If we can't create url key on default store we don't want to proceed with other stores
                if ($storeId == 0 && $parsedUrlKey == "") {
                    return false;
                } else if ($storeId != 0) {
                    return false;
                }

            }
        } catch (\Exception $e) {
            $this->logger->error("setUrlKeyByStore " . $e->getMessage());
        }
        return true;
    }

    /**
     * @param ProductInterface $product
     * @param string $template
     *
     * @return string
     * @throws NoSuchEntityException
     */
    private function parseTemplate(ProductInterface $product, string $template): string
    {
        preg_match_all("/{([^}]*)}/", $template, $matches);
        $attributeBrackets = $matches[0];
        $attributeCodes = $matches[1];
        $isIf = false;
        $deleteIf = false;
        foreach ($attributeCodes as $key => $attributeCodePart) {
            if ($isIf) {
                if ($attributeCodePart == "endif") {
                    $isIf = false;
                    continue;
                }
                if ($deleteIf) {
                    continue;
                }
            }
            if (substr($attributeCodePart, 0, 3) == 'if ') {
                $isIf = true;
                $deleteIf = true;
                $attributeValue = $this->getAttributeValue($product, trim(substr($attributeCodePart, 3)));
                if ($attributeValue === "") {
                    //Delete the whole if statement if value is empty
                    preg_match("/{" . $attributeCodePart . "((?!endif).)*{endif}/", $template, $ifStatement);
                    $template = str_replace($ifStatement[0], "", $template);
                } else {
                    $isIf = true;
                    $deleteIf = false;
                    $template = preg_replace("/{if([^}]*)}/", "", $template, 1);
                    $template = preg_replace("/{endif}/", "", $template, 1);
                }
            }
            $attributeValue = $this->getAttributeValue($product, $attributeCodePart);
            // If attribute is missing a value the rewrite should not occur
            if ($attributeValue == "") {
                return "";
            }
            $from = "/" . str_replace("/", "\/", $attributeBrackets[$key]) . "/";
            $template = preg_replace($from, $attributeValue, $template, 1);
        }

        return trim(preg_replace("/[^a-z0-9]+/", '-', strtolower($template)));
    }

    /**
     * @param ProductInterface $product
     * @param string $attributeCodes format:
     * attributeCode1/attributeCode2
     * or just
     * attributeCode
     *
     * @return string
     * @throws NoSuchEntityException
     */
    private function getAttributeValue(ProductInterface $product, string $attributeCodes): string
    {
        foreach (explode('/', $attributeCodes) as $attributeCode) {
            if ($attributeCode == 'seo_category_id') {
                try {
                    /** @var Category $category */
                    if ($product->getData($attributeCode) != "" ) {
                        $categoryId = $product->getData($attributeCode);
                    } else {
                        $defaultStoreProduct = $this->productRepository->getById($product->getId(), false, 0, true);
                        $categoryId = $defaultStoreProduct->getData($attributeCode);
                    }
                    if ($categoryId == "") {
                        $this->logger->error(__("Couldn't find category with id %1", $categoryId));
                        return "";
                    }

                    $category = $this->categoryRepository->get($categoryId, $product->getStoreId());
                    return trim($category->getUrlKey());
                } catch (NoSuchEntityException $e) {
                    $this->logger->error(__("Attribute code %1 cause en exception for product %2: %3", $attributeCodes, $product->getId(), $e->getMessage()));
                    return "";
                }
            }

            if ($product->getData($attributeCode) != "" ) {
                $attributeValue = $product->getData($attributeCode);
            } else {
                $defaultStoreProduct = $this->productRepository->getById($product->getId(), false, 0, true);
                $attributeValue = $defaultStoreProduct->getData($attributeCode);
            }
            if ($attributeValue && $attributeValue != "") {
                if (preg_match('/^[\p{Latin}|0-9-,.\/ ]+$/', $attributeValue)) {
                    return $attributeValue;
                } else {
                    $defaultStoreProduct = $this->productRepository->getById($product->getId(), false, 0, true);
                    $attributeValue = $defaultStoreProduct->getData($attributeCode);
                    if (preg_match('/^[\p{Latin}|0-9-,.\/ ]+$/', $attributeValue)) {
                        return $attributeValue;
                    }
                }
            }
        }

        $this->logger->error(__("Attribute code %1 caused an empty string on product %2", $attributeCodes, $product->getId()));
        return "";
    }
}