<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Helper;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Image extends \Magento\Catalog\Helper\Image
{

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Catalog\Model\Product\ImageFactory $productImageFactory,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\View\ConfigInterface $viewConfig,
        ?\Magento\Catalog\Model\View\Asset\PlaceholderFactory $placeholderFactory = null
    ) {
        parent::__construct($context, $productImageFactory, $assetRepo,
            $viewConfig, $placeholderFactory);
        $this->productRepository = $productRepository;
    }

    /**
     * Initialize Helper to work with Image
     *
     * @param \Magento\Catalog\Model\Product|int $product
     * @param string $imageId
     * @param array $attributes
     * @return \Magento\Catalog\Helper\Image
     */
    public function init($product, $imageId, $attributes = [])
    {
        if (is_numeric($product)) {
            try {
                $product = $this->productRepository->getById($product);
            } catch (NoSuchEntityException $e) {

            }
        }
        return parent::init($product, $imageId, $attributes);
    }
}