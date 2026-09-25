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
use CarpetAvenue\Productify\Helper\Data;
use CarpetAvenue\Productify\Helper\Logger;
use CarpetAvenue\Productify\Model\GroupProductCacheRepository;
use CarpetAvenue\Productify\Model\ProductVariantReferenceRepository;
use CarpetAvenue\Productify\Service\Core\CollectionBuilder;
use CarpetAvenue\Productify\Service\VariantBuilder;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Locale\Currency as LocaleCurrency;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VariantBuilderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Logger declares its log directory from Magento's bootstrap constant.
        // Focused PHPUnit runs do not load app/bootstrap.php.
        if (!defined('BP')) {
            define('BP', sys_get_temp_dir());
        }
    }

    public function testFullBuildGroupsStoreProductsWithoutQuerying(): void
    {
        $store = $this->createStub(Store::class);
        $repository = $this->createMock(GroupProductCacheRepository::class);
        $collectionBuilder = $this->createMock(CollectionBuilder::class);
        $collectionBuilder->expects(self::never())->method('buildForStore');

        $products = [
            1 => $this->product(1, '100', 1),
            2 => $this->product(2, '100', 0),
            3 => $this->product(3, '200', 1),
            5 => ['id' => 5, 'online' => 1, 'status' => 1],
        ];
        $saveCalls = [];
        $this->expectSaves($repository, 2, $saveCalls);

        $progress = 0;
        $result = $this->builder($repository, $collectionBuilder)->buildForStore(
            $store,
            7,
            $products,
            static function () use (&$progress): void {
                $progress++;
            },
            $products
        );

        self::assertSame(
            [
                '100' => ['variants' => [$products[1]]],
                '200' => ['variants' => [$products[3]]],
            ],
            $saveCalls
        );
        self::assertSame(4, $progress, 'Progress callback must run once per input product');
        self::assertSame([$products[1], $products[2], $products[3]], $result);
    }

    public function testPartialBuildLoadsAllSiblingDesignsInOneQuery(): void
    {
        $store = $this->createStub(Store::class);
        $repository = $this->createMock(GroupProductCacheRepository::class);
        $collectionBuilder = $this->createMock(CollectionBuilder::class);

        $siblings = [
            1 => $this->product(1, '100', 1),
            2 => $this->product(2, '100', 0),
            4 => $this->product(4, '100', 1),
            3 => $this->product(3, '200', 1),
        ];
        $collectionBuilder->expects(self::once())
            ->method('buildForStore')
            ->with($store, [], ['100', '200'])
            ->willReturn($siblings);
        $saveCalls = [];
        $this->expectSaves($repository, 2, $saveCalls);

        $progress = 0;
        $changed = [$siblings[1], $siblings[3], ['id' => 5]];
        $result = $this->builder($repository, $collectionBuilder)->buildForStore(
            $store,
            7,
            $changed,
            static function () use (&$progress): void {
                $progress++;
            }
        );

        self::assertSame(
            [
                '100' => ['variants' => [$siblings[1], $siblings[4]]],
                '200' => ['variants' => [$siblings[3]]],
            ],
            $saveCalls
        );
        self::assertSame(3, $progress, 'Progress callback must run once per input product');
        self::assertSame([$siblings[1], $siblings[2], $siblings[4], $siblings[3]], $result);
    }

    public function testCollationEqualDesignIdsShareOneVariantList(): void
    {
        $store = $this->createStub(Store::class);
        $repository = $this->createMock(GroupProductCacheRepository::class);
        $collectionBuilder = $this->createStub(CollectionBuilder::class);

        // utf8mb4_general_ci compares these equal, so the old per-design query
        // returned all three rows for each spelling.
        $products = [
            1 => $this->product(1, 'D10', 1),
            2 => $this->product(2, 'd10', 1),
            3 => $this->product(3, 'D10 ', 1),
        ];
        $saveCalls = [];
        $this->expectSaves($repository, 3, $saveCalls);

        $this->builder($repository, $collectionBuilder)
            ->buildForStore($store, 7, $products, null, $products);

        $all = ['variants' => array_values($products)];
        self::assertSame(['D10' => $all, 'd10' => $all, 'D10 ' => $all], $saveCalls);
    }

    private function product(int $id, string $designId, int $online): array
    {
        return [
            'id' => $id,
            'online' => $online,
            'status' => 1,
            Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE => $designId,
        ];
    }

    /**
     * Expect $expected saves and record them into $saves as design id => data, in save order
     *
     * @param GroupProductCacheRepository&MockObject $repository
     * @param int $expected
     * @param array $saves
     */
    private function expectSaves(
        GroupProductCacheRepository&MockObject $repository,
        int $expected,
        array &$saves
    ): void {
        $repository->expects(self::exactly($expected))
            ->method('saveByDesignId')
            ->willReturnCallback(static function (string $designId, array $data, int $storeId) use (&$saves): void {
                self::assertSame(7, $storeId);
                $saves[$designId] = $data;
            });
    }

    private function builder(
        GroupProductCacheRepository $repository,
        CollectionBuilder $collectionBuilder
    ): VariantBuilder {
        return new VariantBuilder(
            $this->createStub(CollectionFactory::class),
            $repository,
            $this->createStub(ProductVariantReferenceRepository::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(Image::class),
            $this->createStub(CacheInterface::class),
            $this->createStub(Emulation::class),
            $this->createStub(PriceCurrencyInterface::class),
            $this->createStub(LocaleCurrency::class),
            $this->createStub(Config::class),
            $this->createStub(ManagerInterface::class),
            $this->createStub(StockResolverInterface::class),
            $this->createStub(Logger::class),
            $collectionBuilder
        );
    }
}
