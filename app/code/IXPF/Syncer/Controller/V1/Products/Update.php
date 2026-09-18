<?php

namespace IXPF\Syncer\Controller\V1\Products;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Logger\ProductUpdateLogger;
use IXPF\Syncer\Helper\PhotoStudio;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Helper\Helpers as ProductHelper;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductRepository;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Store\Model\StoreFactory;
use Magento\Framework\Registry;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Google\Cloud\Storage\StorageClient;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper\AttributeFilter;

class Update extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{

    public function __construct(
        Context                  $context,
        TokenValidator           $tokenValidator,
        JsonFactory              $resultFactory,
        protected ProductHelper  $productHelper,
        protected Registry       $registry,
        protected Helper         $initializationHelper,
        protected ProductFactory $productFactory,
        protected StoreFactory   $storeFactory,
        protected ProductRepository $productRepository,
        protected ProductUpdateLogger $productUpdateLogger,
        protected ProductAttributeMediaGalleryManagementInterface $productAttributeMediaGalleryManagement,
        protected AttributeFilter $attributeFilter,
        protected ResourceConnection $resourceConnection
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    private function getCurrentUseDefaults(Product $product, int $storeId): array
    {
        // Load same product at global scope to compare
        $globalProduct = $this->productRepository->getById(
            $product->getId(),
            false,
            0
        );

        $useDefaults = [];

        foreach ($product->getAttributes() as $attributeCode => $attribute) {
            // Skip global-scope attributes — they have no "use default" concept
            if ((int)$attribute->getIsGlobal() === ScopedAttributeInterface::SCOPE_GLOBAL) {
                continue;
            }

            $storeValue  = $product->getData($attributeCode);
            $globalValue = $globalProduct->getData($attributeCode);

            // If store value matches global — it's using default
            $useDefaults[$attributeCode] = ($storeValue == $globalValue) ? '1' : '0';
        }

        return $useDefaults;
    }

    /**
     * @return ResponseInterface|Json|ResultInterface
     */
    public function execute()
    {
        try {
            //-- token validation check
            $this->tokenValidator();

            $store = $this->getRequest()->getParam('store');
            if (!$store) {
                $store = 0;
            }

            $set = $this->getRequest()->getParam('set');
            if (!$set) {
                throw new \Exception('Missing attribute set parameter');
            }
            $id = $this->getRequest()->getParam('id');
            if (!$id) {
                throw new \Exception('Missing product id parameter');
            }

            $rawContent = $this->getRequest()->getContent();
            $payload = json_decode($rawContent, true);
            //-- log payload
            $this->productUpdateLogger->writeDaily("Received update request for product ID: {$id}, Store ID: {$store}, Attribute Set ID: {$set}");
            $this->productUpdateLogger->writeDaily(json_encode($payload));

            if (empty($payload)) {
                throw new \Exception('Empty product information');
            }

            if (!$this->productHelper->getEnableProductUpdateConfig()) {
                throw new \Exception('Product update configuration disable');
            }

            $productPayloads = [];
            $allowUpdateAttributes = $this->productHelper->getProductUpdateAllowAttributes();
            $productAttributeInfoChanged = false;
            if (isset($payload['product'])) {
                foreach ($payload['product'] as $key => $value) {
                    if (in_array($key, $allowUpdateAttributes)) {
                        $productPayloads[$key] = $value;
                        $productAttributeInfoChanged = true;
                    }
                }
            }

            // special_price = 0 → treat as "remove special price" by setting null.
            if (array_key_exists('special_price', $productPayloads) && $productPayloads['special_price'] == 0) {
                $productPayloads['special_price'] = null;
                $this->productUpdateLogger->writeDaily(
                    "[INFO] Product ID {$id}: 'special_price' was 0 — set to null to clear the special price."
                );
            }

            // price = 0 → almost certainly a bad feed value; unset so the existing price is preserved.
            if (array_key_exists('price', $productPayloads) && $productPayloads['price'] == 0) {
                unset($productPayloads['price']);
                $this->productUpdateLogger->writeDaily(
                    "[SKIPPED] Product ID {$id}: 'price' was 0 in the request payload — "
                    . "value ignored to prevent accidental zero-price update."
                );
            }

            $useDefaults = [];
            if ($store != 0 && isset($payload['use_default']) && is_array($payload['use_default'])) {
                foreach ($payload['use_default'] as $attrCode => $useDefault) {
                    if (in_array($attrCode, $allowUpdateAttributes) && intval($useDefault) === 1) {
                        $useDefaults[$attrCode] = $useDefault;
                    }
                }
            }

            $productData = $this->productRepository->getById($id, true, $store);
            $productStoreUseDefaults = $this->getCurrentUseDefaults($productData, $store);
            $useDefaults = array_merge($productStoreUseDefaults, $useDefaults); // Merge with incoming use_default flags, prioritizing incoming values

            //-- remove use defaults attribute from useDefault if attribute value is available in payload, because if attribute value is available in payload, it means useDefault is false
            foreach ($productPayloads as $attributeCode => $value) {
                if ($value == null || $value == "") {
                    $useDefaults[$attributeCode] = '1';
                } else {
                    if (isset($useDefaults[$attributeCode])) {
                        unset($useDefaults[$attributeCode]);
                    }
                }
            }

            if (isset($payload['product']['category_ids']) && is_array($payload['product']['category_ids'])) {
                $productPayloads['category_ids'] = $payload['product']['category_ids'];
            } else {
                $productPayloads['category_ids'] = $productData->getCategoryIds();
            }

            if (isset($payload['product']['website_ids']) && is_array($payload['product']['website_ids'])) {
                $productPayloads['website_ids'] = $payload['product']['website_ids'];
            } else {
                $productPayloads['website_ids'] = $productData->getWebsiteIds();
            }

            if (!isset($payload['stock_data'])) {
                $productPayloads['stock_data'] = [
                    'manage_stock' => 1,
                    'use_config_manage_stock' => 1,
                ];
            }

            //-- payload data into log
            $this->productUpdateLogger->writeDaily('Prepared product payload for update:');
            $this->productUpdateLogger->writeDaily(json_encode($productPayloads));

            foreach ($productPayloads as $attributeCode => $value) {
                if ($value == "") {
                    $useDefaults[$attributeCode] = '1';
                }
                if ($value == null) {
                    $useDefaults[$attributeCode] = '1';
                }
            }
            //-- merge with existing product data to avoid overwriting unchanged attributes with null (since the initialization helper will reset any attributes not included in the payload)
            $productPayloads = array_merge($productData->getData(), $productPayloads);
            if (count($useDefaults) > 0 && $store != 0) {
                $this->productUpdateLogger->writeDaily('Use default for attributes: ' . json_encode($useDefaults));
                $productPayloads = $this->attributeFilter->prepareProductAttributes($productData, $productPayloads, $useDefaults);
            }
            $product = $this->initializationHelper->initializeFromData($productData, $productPayloads);
            $product->save();

            // Category position: Magento sets position=0 on save; update to 1000 for zero-position rows
            if (isset($payload['product']['category_ids']) && is_array($payload['product']['category_ids']) && !empty($payload['product']['category_ids'])) {
                $this->fixCategoryPositions((int)$product->getId(), $payload['product']['category_ids']);
            }

            if (isset($payload['media_gallery']) && in_array('media_gallery', $allowUpdateAttributes)) {
                //$product = $this->productRepository->getById($product->getId(), false, $store);
                $this->manageGalleryImages((int)$product->getId(), $payload['media_gallery']);
            }

            if ($productAttributeInfoChanged) {
                $this->_eventManager->dispatch(
                    'controller_action_catalog_product_save_entity_after',
                    ['controller' => $this, 'product' => $product]
                );
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => __('Product updated successfully.'),
            ]);

        } catch (\Exception $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 500);
        }
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Set position=1000 for category-product rows written with position=0 during product save.
     * Only touches rows where position is still 0 — existing non-zero positions are left untouched.
     */
    private function fixCategoryPositions(int $productId, array $categoryIds): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $connection->getTableName('catalog_category_product'),
            ['position' => 1000],
            [
                'product_id = ?'     => $productId,
                'category_id IN (?)' => array_map('intval', $categoryIds),
                'position = ?'       => 0,
            ]
        );
    }

    /**
     * Loads and registers the product for the given request parameters.
     *
     * no risk of the store context being re-read differently inside this method.
     * The product is loaded exactly once and registered once — any previous
     * registry entries are cleared first to avoid stale references.
     *
     * @param RequestInterface $request
     * @param int              $storeId  Already-resolved store ID from execute()
     * @return Product
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function build(RequestInterface $request, int $storeId = 0): Product
    {
        $productId      = (int)$request->getParam('id');
        $attributeSetId = (int)$request->getParam('set');

        if (!$productId) {
            throw new \Exception('Missing product id parameter');
        }

        /** @var Product $product */
        $product = $this->productFactory->create();
        $product->setData('_edit_mode', true);
        $product->setData('store_id', $storeId);
        $product->load($productId);

        if (!$product->getId()) {
            throw new NoSuchEntityException(
                __("The product that was requested doesn't exist. Verify the product and try again.")
            );
        }
        $product->setAttributeSetId($attributeSetId);

        $store = $this->storeFactory->create();
        $store->load($storeId);

        // Clear any previously registered product/store entries before registering
        // the freshly loaded instances to prevent stale-object ambiguity.
        $this->registry->unregister('product');
        $this->registry->unregister('current_product');
        $this->registry->unregister('current_store');
        $this->registry->register('product', $product);
        $this->registry->register('current_product', $product);
        $this->registry->register('current_store', $store);

        // No wysiwygConfig->setStoreId() here, unlike
        // Magento\Catalog\Controller\Adminhtml\Product\Builder::build(). That store id
        // is only read by Wysiwyg\Config::isEnabled() to render the admin editor, so it
        // does nothing for an API-driven save — and Magento\Cms\Model\Wysiwyg\Config
        // cannot even be constructed outside the adminhtml area: its
        // CompositeConfigProvider arguments are declared only in
        // magento/module-cms/etc/adminhtml/di.xml, so building it on this frontend route
        // fatals with "$variablePluginConfigProvider must be of type array, null given".

        return $product;
    }

    /**
     * @throws NoSuchEntityException
     * @throws StateException
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws LocalizedException
     * @throws \Exception
     */
    private function manageGalleryImages($productId, $images): void
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $photoStudio = $objectManager->create(PhotoStudio::class);
        $photoStudio->manageGalleryImages($productId, $images);
    }
}