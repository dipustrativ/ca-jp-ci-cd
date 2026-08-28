<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Helper;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class ProductHelper extends AbstractHelper
{
    const SPECIAL_ATTRIBUTES = [
            'seo_category_id',
            'profile_name',
    ];

    /**
     * @var \Magento\Catalog\Api\CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;

    public function __construct(
        CategoryRepositoryInterface $categoryRepository,
        ProductRepositoryInterface $productRepository,
        Context $context
    ) {
        parent::__construct($context);
        $this->categoryRepository = $categoryRepository;
        $this->productRepository = $productRepository;
    }

    /**
     * @param ProductInterface $product
     *
     * @return string|null
     */
    public function getProductProductionType($product): ?string
    {
        // Get production_type value from store ID 0 (admin)
        $_storeIdTemp = $product->getStoreId();
        $attribute = $product->getResource()->getAttribute('production_type');
        $option_value_base = $attribute->setStoreId(0)->getSource()->getOptionText($product->getData('production_type'));

        // Restore attribute store ID
        $attribute->setStoreId($_storeIdTemp);

        return $option_value_base;
    }

    /**
     * @param ProductInterface    $product
     * @param \Evalent\ProductProfiles\Model\ProductProfile $profile
     * @param string                                        $attributeCodes format:
     *                                                                      attributeCode1/attributeCode2
     *                                                                      or just
     *                                                                      attributeCode
     *
     * @return string|null
     */
    private function getAttributeValue($product, $profile, $attributeCodes)
    {
        foreach (explode('/', $attributeCodes) as $attributeCode) {
            if (in_array($attributeCode, self::SPECIAL_ATTRIBUTES)) {
                $attributeValue = $this->getSpecialAttributeValue($product,
                    $profile, $attributeCode);
            } else {
                $attributeValue = $product->getData($attributeCode);
            }
            if ($attributeValue && $attributeValue != "") {
                return $attributeValue;
            }
        }
        return "";
    }

    /**
     * @param ProductInterface    $product
     * @param \Evalent\ProductProfiles\Model\ProductProfile $profile
     * @param string                                        $attributeCode
     *
     * @return string|null
     */
    private function getSpecialAttributeValue($product, $profile, $attributeCode): string
    {
        switch ($attributeCode) {
            case 'seo_category_id':
                try {
                    $category = $this->categoryRepository->get($product->getData($attributeCode), $product->getStoreId());
                    return $category->getName();
                } catch (NoSuchEntityException $e) {

                }
                break;
            case 'profile_name':
                return $profile->getName();
        }
        return "";
    }
}
