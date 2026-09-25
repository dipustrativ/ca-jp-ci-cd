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

namespace CarpetAvenue\Productify\Test\Unit;

use Magento\Catalog\Model\Product\Image\ConvertImageMiscParamsToReadableFormat;
use PHPUnit\Framework\TestCase;

/**
 * Locks the cache hash of the PLP/PDP colour chip.
 *
 * CollectionBuilder persists the rendered swatch URL into
 * carpetavenue_productify_group_product_cache, so the URL long outlives the file it
 * points at. Magento can only regenerate a derivative whose params come from a
 * view.xml <image> entry (MediaStorage\Service\ImageResize::getViewImages) — change
 * the declared size, or reintroduce a runtime ->resize(), and every stored URL is
 * orphaned: the file can never be rebuilt and get.php serves the placeholder forever.
 *
 * This test recomputes the hash the way Magento does and pins it to the value the
 * live store is already serving.
 */
class VariantSwatchImageHashTest extends TestCase
{
    private const IMAGE_ID = 'productify_variant_swatch';

    /** The hash in every swatch URL already stored in the DB. */
    private const EXPECTED_HASH = '3a98496dd7cb0c8b28c4c254a98f915a';

    private const THEME_VIEW_XML = __DIR__
        . '/../../../../../design/frontend/carpetavenue/rugs/etc/view.xml';

    public function testDeclaredSwatchParamsStillProduceTheStoredHash(): void
    {
        [$width, $height] = $this->declaredSize(self::IMAGE_ID);

        self::assertSame([165, 165], [$width, $height], self::IMAGE_ID . ' changed size');
        self::assertSame(self::EXPECTED_HASH, $this->hash($width, $height));
    }

    /**
     * Read the declared dimensions out of the theme's view.xml.
     *
     * @return array{0: int, 1: int}
     */
    private function declaredSize(string $imageId): array
    {
        $xml = simplexml_load_file(self::THEME_VIEW_XML);
        self::assertNotFalse($xml, 'Could not parse ' . self::THEME_VIEW_XML);

        $nodes = $xml->xpath(sprintf('//image[@id="%s"]', $imageId));
        self::assertNotEmpty($nodes, $imageId . ' is not declared in the theme view.xml');
        self::assertSame(
            'swatch_image',
            (string) $nodes[0]['type'],
            $imageId . ' must keep type="swatch_image"'
        );

        return [(int) $nodes[0]->width, (int) $nodes[0]->height];
    }

    /**
     * Mirror of Magento\Catalog\Model\View\Asset\Image::getImageInfo() for an <image>
     * entry that declares only width/height, i.e. every other param comes from
     * ParamsBuilder's defaults. keep_frame is true because the theme inherits
     * product_image_white_borders=1 from Magento/blank.
     */
    private function hash(int $width, int $height): string
    {
        $params = (new ConvertImageMiscParamsToReadableFormat())->convertImageMiscParamsToReadableFormat([
            'image_height'      => $height,
            'image_width'       => $width,
            'background'        => [255, 255, 255],
            'angle'             => null,
            'quality'           => 80, // Magento's catalog/placeholder JPEG quality default
            'keep_aspect_ratio' => true,
            'keep_frame'        => true,
            'keep_transparency' => true,
            'constrain_only'    => true,
        ]);

        return hash('md5', implode('_', $params));
    }
}
