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

    public function testBuildsOncePerDesignAndPreservesReturnAndProgressContracts(): void
    {
        $store = $this->createStub(Store::class);
        $repository = $this->createMock(GroupProductCacheRepository::class);
        $collectionBuilder = $this->createMock(CollectionBuilder::class);

        $collectionCalls = [];
        $groupProducts = [
            '100' => [
                ['id' => 1, 'online' => 1, 'status' => 1],
                ['id' => 2, 'online' => 0, 'status' => 1],
            ],
            '200' => [
                ['id' => 3, 'online' => 1, 'status' => 1],
            ],
        ];
        $collectionBuilder->expects(self::exactly(2))
            ->method('buildForStore')
            ->willReturnCallback(
                static function (
                    Store $actualStore,
                    array $ids,
                    string $designId
                ) use (
                    $store,
                    $groupProducts,
                    &$collectionCalls
                ): array {
                    self::assertSame($store, $actualStore);
                    self::assertSame([], $ids);
                    $collectionCalls[] = $designId;

                    return $groupProducts[$designId];
                }
            );

        $saveCalls = [];
        $repository->expects(self::exactly(2))
            ->method('saveByDesignId')
            ->willReturnCallback(
                static function (string $designId, array $data, int $storeId) use (&$saveCalls): void {
                    $saveCalls[] = $designId;
                    self::assertSame(7, $storeId);
                    if ($designId === '100') {
                        self::assertSame(
                            ['variants' => [['id' => 1, 'online' => 1, 'status' => 1]]],
                            $data
                        );
                    } elseif ($designId === '200') {
                        self::assertSame(
                            ['variants' => [['id' => 3, 'online' => 1, 'status' => 1]]],
                            $data
                        );
                    } else {
                        self::fail('Unexpected design id: ' . $designId);
                    }
                }
            );

        $progress = 0;
        $products = [
            ['id' => 1, Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE => '100'],
            ['id' => 2, Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE => '100'],
            ['id' => 4, Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE => '100'],
            ['id' => 3, Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE => '200'],
            ['id' => 5],
        ];

        $result = $this->builder($repository, $collectionBuilder)->buildForStore(
            $store,
            7,
            $products,
            static function () use (&$progress): void {
                $progress++;
            }
        );

        self::assertSame(['100', '200'], $collectionCalls);
        self::assertSame(['100', '200'], $saveCalls);
        self::assertSame(5, $progress, 'Progress callback must still run once per input product');
        self::assertSame(array_merge($groupProducts['100'], $groupProducts['200']), $result);
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
