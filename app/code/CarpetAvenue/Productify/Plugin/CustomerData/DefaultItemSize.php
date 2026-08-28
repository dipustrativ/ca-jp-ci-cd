<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Plugin\CustomerData;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote\Item;

/**
 * Adds size (width/length) + is_round to minicart item data so the
 * minicart item template can render the dimension line, matching base.
 */
class DefaultItemSize
{
    private const CONFIGURABLE_SIZE = 'configurable_size';

    /**
     * @var ProductAttributeRepositoryInterface
     */
    private $attributeRepository;

    public function __construct(ProductAttributeRepositoryInterface $attributeRepository)
    {
        $this->attributeRepository = $attributeRepository;
    }

    /**
     * @param \Magento\Checkout\CustomerData\DefaultItem $subject
     * @param \Closure $proceed
     * @param Item $item
     * @return array
     */
    public function aroundGetItemData(
        \Magento\Checkout\CustomerData\DefaultItem $subject,
        \Closure $proceed,
        Item $item
    ) {
        $data = $proceed($item);
        $product = $item->getProduct();

        try {
            $size = $this->getSize($product, $data);
        } catch (NoSuchEntityException $e) {
            $size = [0, 0];
        }

        return array_merge($data, [
            'is_round' => $product->getData('shape') == 20,
            'width' => $size[0],
            'length' => $size[1],
        ]);
    }

    /**
     * @throws NoSuchEntityException
     */
    private function getSize(ProductInterface $product, array $data = [])
    {
        // Configurable: use the selected child's dimensions, fall back to parent.
        if (isset($data['product_type']) && $data['product_type'] === 'configurable') {
            if ($child = $this->getChild($data, $product)) {
                return $this->getSize($child);
            }
        }

        return $this->getDimension($product);
    }

    /**
     * @throws NoSuchEntityException
     */
    private function getChild(array $data, ProductInterface $product)
    {
        if (isset($data['options'])) {
            $attributes = $this->getAttributes($data['options']);
            $children = $product->getTypeInstance()->getUsedProducts($product);
            foreach ($children as $child) {
                $found = true;
                foreach ($data['options'] as $option) {
                    $childOptionValue = $child->getData($attributes[$option['option_id']]->getAttributeCode());
                    if ($childOptionValue !== $option['option_value']) {
                        $found = false;
                        break;
                    }
                }
                if ($found) {
                    return $child;
                }
            }
        }

        return false;
    }

    /**
     * @throws NoSuchEntityException
     */
    private function getDimension(ProductInterface $product, ?int $dimension = null)
    {
        if (!isset($dimension)) {
            return [
                $this->getDimension($product, 0),
                $this->getDimension($product, 1),
            ];
        } elseif (!in_array((int)$dimension, [0, 1])) {
            return 0;
        }

        $d = $product->getCustomAttribute($dimension == 0 ? 'width' : 'length');

        if (!isset($d)) {
            $options = $this->getOptions();
            $size = $product->getCustomAttribute(self::CONFIGURABLE_SIZE);
            if (isset($size) && isset($options[$size->getValue()])) {
                $values = explode('x', $options[$size->getValue()]);
                if (isset($values[$dimension])) {
                    return $values[$dimension];
                }
            }
        } else {
            return $d->getValue();
        }

        return 0;
    }

    /**
     * @throws NoSuchEntityException
     */
    private function getAttributes($options)
    {
        $result = [];
        foreach ($options as $option) {
            $attribute = $this->attributeRepository->get($option['option_id']);
            $result[$attribute->getAttributeId()] = $attribute;
        }

        return $result;
    }

    /**
     * @throws NoSuchEntityException
     */
    private function getOptions()
    {
        $options = $this->attributeRepository->get(self::CONFIGURABLE_SIZE)->getOptions();
        $result = [];
        foreach ($options as $option) {
            $result[$option->getValue()] = $option->getLabel();
        }

        return $result;
    }
}
