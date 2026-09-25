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

namespace CarpetAvenue\Productify\Test\Unit\Model;

use CarpetAvenue\Productify\Model\PrimaryShapeResolver;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrimaryShapeResolverTest extends TestCase
{
    /**
     * Product count per shape option, most used first — as loadUsage() returns it.
     * Two rectangular options exist on the real catalogue (14 "rectangle", 69 "Rectangular").
     */
    private const USAGE = [14 => 922, 147 => 84, 115 => 83, 69 => 10, 188 => 0];

    public static function configuredValues(): array
    {
        return [
            'automatic picks the most used shape' => ['', '14'],
            'a real shape option is kept'          => ['69', '69'],
            'an unused shape option is kept'       => ['188', '188'],
            'an id from another database is ignored' => ['19', '14'],
        ];
    }

    #[DataProvider('configuredValues')]
    public function testResolve(string $configured, string $expected): void
    {
        self::assertSame($expected, $this->resolver(self::USAGE)->resolve($configured));
    }

    public function testWithoutShapeOptionsTheSettingIsReturnedUnchanged(): void
    {
        self::assertSame('19', $this->resolver([])->resolve('19'));
        self::assertSame('', $this->resolver([])->resolve(''));
    }

    public function testUsageIsReadFromCacheWithoutQueryingTheDatabase(): void
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $resolver = $this->resolver(self::USAGE, $resourceConnection);

        self::assertSame(self::USAGE, $resolver->getUsage());
    }

    /**
     * Resolver whose cache already holds $usage
     *
     * @param array<int, int> $usage
     * @param ResourceConnection|null $resourceConnection
     * @return PrimaryShapeResolver
     */
    private function resolver(array $usage, ?ResourceConnection $resourceConnection = null): PrimaryShapeResolver
    {
        $serializer = new Json();
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn($serializer->serialize($usage));

        return new PrimaryShapeResolver(
            $resourceConnection ?? $this->createStub(ResourceConnection::class),
            $this->createStub(EavConfig::class),
            $cache,
            $serializer
        );
    }
}
