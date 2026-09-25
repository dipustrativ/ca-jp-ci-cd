<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Test\Unit\Service;

use CarpetAvenue\Productify\Helper\Config;
use CarpetAvenue\Productify\Model\GroupProductCacheRepository;
use CarpetAvenue\Productify\Model\ProductVariantReferenceRepository;
use CarpetAvenue\Productify\Service\Core\CollectionBuilder;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Locale\Currency as LocaleCurrency;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CollectionBuilderImageTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function imageIds(): array
    {
        return [
            'variant image' => ['product_page_image_small'],
            'colour swatch' => ['productify_variant_swatch'],
        ];
    }

    #[DataProvider('imageIds')]
    public function testImageUrlUsesOnlyTheDeclaredViewImageId(string $imageId): void
    {
        $product = $this->createStub(Product::class);
        $imageHelper = $this->createMock(Image::class);
        $imageHelper->expects(self::once())
            ->method('init')
            ->with($product, $imageId)
            ->willReturnSelf();
        $imageHelper->expects(self::never())->method('resize');
        $imageHelper->expects(self::never())->method('constrainOnly');
        $imageHelper->expects(self::never())->method('keepAspectRatio');
        $imageHelper->expects(self::never())->method('keepTransparency');
        $imageHelper->expects(self::once())->method('getUrl')->willReturn('https://example.test/image.jpg');

        self::assertSame(
            'https://example.test/image.jpg',
            $this->builder($imageHelper)->getProductImageUrl($product, $imageId)
        );
    }

    private function builder(Image $imageHelper): CollectionBuilder
    {
        return new CollectionBuilder(
            $this->createStub(CollectionFactory::class),
            $this->createStub(GroupProductCacheRepository::class),
            $this->createStub(ProductVariantReferenceRepository::class),
            $this->createStub(StoreManagerInterface::class),
            $imageHelper,
            $this->createStub(CacheInterface::class),
            $this->createStub(Emulation::class),
            $this->createStub(PriceCurrencyInterface::class),
            $this->createStub(LocaleCurrency::class),
            $this->createStub(Config::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(ManagerInterface::class),
            $this->createStub(StockResolverInterface::class)
        );
    }
}
