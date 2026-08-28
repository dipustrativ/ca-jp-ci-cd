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
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use PHPUnit\Framework\TestCase;

class DataTest extends TestCase
{
    /**
     * @param array<string, string> $config
     */
    private function helper(array $config = []): Data
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $config[$path] ?? null
        );

        $context = $this->createMock(Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);

        return new Data($context);
    }

    public function testShapeOptionIdsFallBackToDefaultsWhenUnset(): void
    {
        $helper = $this->helper();

        $this->assertSame('20', $helper->getRoundShapeOptionId());
        $this->assertSame('19', $helper->getPrimaryShapeOptionId());
    }

    public function testShapeOptionIdsComeFromConfigWhenSet(): void
    {
        $helper = $this->helper([
            Data::XML_PATH_ROUND_SHAPE_OPTION_ID   => '77',
            Data::XML_PATH_PRIMARY_SHAPE_OPTION_ID => '76',
        ]);

        $this->assertSame('77', $helper->getRoundShapeOptionId());
        $this->assertSame('76', $helper->getPrimaryShapeOptionId());
    }

    public function testFormatSizeLabelUsesDiameterOnlyForConfiguredRoundShape(): void
    {
        $helper = $this->helper([Data::XML_PATH_ROUND_SHAPE_OPTION_ID => '77']);

        // Round shape id from config, square label -> diameter.
        $this->assertSame('∅ 150 cm', $helper->formatSizeLabel('150x150', '77'));
        $this->assertSame('∅ 150 cm', $helper->formatSizeLabel('150 x 150 cm', 77));

        // Old hardcoded id is no longer special.
        $this->assertSame('150x150 cm', $helper->formatSizeLabel('150x150', '20'));

        // Round but not square, and non-round shapes, keep the stored label.
        $this->assertSame('160x230 cm', $helper->formatSizeLabel('160x230', '77'));
        $this->assertSame('160x230 cm', $helper->formatSizeLabel('160x230 cm', '19'));
        $this->assertSame('80x250 cm', $helper->formatSizeLabel(' 80x250 ', null));

        // Labels that are not W x L are left alone apart from the unit.
        $this->assertSame('One size cm', $helper->formatSizeLabel('One size', '19'));
    }
}
