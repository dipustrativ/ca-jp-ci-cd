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

namespace CarpetAvenue\Productify\Test\Unit\Helper;

use CarpetAvenue\Productify\Helper\Data;
use CarpetAvenue\Productify\Model\PrimaryShapeResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use PHPUnit\Framework\TestCase;

class DataTest extends TestCase
{
    /**
     * @param array<string, string> $config
     */
    private function helper(array $config = [], ?PrimaryShapeResolver $resolver = null): Data
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $config[$path] ?? null
        );

        $context = $this->createMock(Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);

        return new Data($context, $resolver ?? $this->createStub(PrimaryShapeResolver::class));
    }

    public function testPrimaryShapeOptionIdAsksResolverForAutomaticWhenUnset(): void
    {
        $resolver = $this->createMock(PrimaryShapeResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('')->willReturn('14');

        $this->assertSame('14', $this->helper([], $resolver)->getPrimaryShapeOptionId());
    }

    public function testPrimaryShapeOptionIdPassesConfiguredValueToResolver(): void
    {
        $resolver = $this->createMock(PrimaryShapeResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('76')->willReturn('76');
        $helper = $this->helper([Data::XML_PATH_PRIMARY_SHAPE_OPTION_ID => '76'], $resolver);

        $this->assertSame('76', $helper->getPrimaryShapeOptionId());
    }

    public function testFormatSizeUsesDiameterForSquareDimensions(): void
    {
        $helper = $this->helper();

        // Equal sides -> diameter, whatever the shape option id is.
        $this->assertSame('∅ 240 cm', $helper->formatSize(240, 240, '29'));
        $this->assertSame('∅ 145 cm', $helper->formatSize('145', '145', '67'));
        $this->assertSame('∅ 150 cm', $helper->formatSize(150, 150, null));

        // Unequal sides keep W x L.
        $this->assertSame('160 x 230 cm', $helper->formatSize(160, 230, '29'));

        // Missing dimensions render nothing.
        $this->assertSame('', $helper->formatSize(null, 230, '29'));
        $this->assertSame('', $helper->formatSize(160, '', '29'));
    }

    public function testFormatSizeLabelUsesDiameterForSquareLabels(): void
    {
        $helper = $this->helper();

        $this->assertSame('∅ 150 cm', $helper->formatSizeLabel('150x150'));
        $this->assertSame('∅ 150 cm', $helper->formatSizeLabel('150 x 150 cm'));

        // Unequal sides keep the stored spelling and only gain the unit.
        $this->assertSame('160x230 cm', $helper->formatSizeLabel('160x230'));
        $this->assertSame('160x230 cm', $helper->formatSizeLabel('160x230 cm'));
        $this->assertSame('80x250 cm', $helper->formatSizeLabel(' 80x250 '));

        // Labels that are not W x L are left alone apart from the unit.
        $this->assertSame('One size cm', $helper->formatSizeLabel('One size'));
    }
}
