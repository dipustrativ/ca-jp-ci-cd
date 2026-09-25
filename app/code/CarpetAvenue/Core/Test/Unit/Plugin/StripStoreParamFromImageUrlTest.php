<?php

declare(strict_types=1);

namespace CarpetAvenue\Core\Test\Unit\Plugin;

use CarpetAvenue\Core\Plugin\StripStoreParamFromImageUrl;
use Magento\Catalog\Block\Product\Image as ImageBlock;
use Magento\Catalog\Block\Product\ImageFactory;
use Magento\Catalog\Block\Product\View\Gallery as GalleryBlock;
use Magento\Catalog\Model\Product;
use Magento\Catalog\ViewModel\Product\Gallery as GalleryViewModel;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class StripStoreParamFromImageUrlTest extends TestCase
{
    /**
     * @var StripStoreParamFromImageUrl
     */
    private StripStoreParamFromImageUrl $plugin;

    protected function setUp(): void
    {
        $this->plugin = new StripStoreParamFromImageUrl();
    }

    public function testCreateStripsTheParamFromTheBlockUrl(): void
    {
        $block = $this->createMock(ImageBlock::class);
        $block->method('getData')->with('image_url')
            ->willReturn('https://x.test/media/catalog/product/cache/h/a/b.jpg?___store=se');
        $block->expects($this->once())->method('setData')
            ->with('image_url', 'https://x.test/media/catalog/product/cache/h/a/b.jpg');

        $this->plugin->afterCreate($this->createMock(ImageFactory::class), $block);
    }

    public function testCreateLeavesACleanUrlAlone(): void
    {
        $block = $this->createMock(ImageBlock::class);
        $block->method('getData')->with('image_url')->willReturn('https://x.test/media/a.jpg');
        $block->expects($this->never())->method('setData');

        $this->plugin->afterCreate($this->createMock(ImageFactory::class), $block);
    }

    public function testGalleryJsonLosesEveryStoreParam(): void
    {
        $json = json_encode([
            [
                'thumb' => 'https://x.test/media/cache/1/a.jpg?___store=se',
                'img' => 'https://x.test/media/cache/2/a.jpg?___store=se',
                'full' => 'https://x.test/media/cache/3/a.jpg?___store=se',
                'caption' => 'Rug',
            ],
        ]);

        $result = $this->plugin->afterGetGalleryImagesJson($this->galleryBlock('se'), $json);

        // Decoding also proves the replace left valid JSON behind.
        $decoded = json_decode($result, true);

        $this->assertSame(
            [
                [
                    'thumb' => 'https://x.test/media/cache/1/a.jpg',
                    'img' => 'https://x.test/media/cache/2/a.jpg',
                    'full' => 'https://x.test/media/cache/3/a.jpg',
                    'caption' => 'Rug',
                ],
            ],
            $decoded
        );
    }

    public function testGalleryJsonWithoutTheParamNeverTouchesTheProduct(): void
    {
        // A gallery with no images never resolves a product; calling getProduct()
        // unconditionally would fatal there.
        $block = $this->createMock(GalleryBlock::class);
        $block->expects($this->never())->method('getProduct');

        $json = '[{"thumb":"https:\/\/x.test\/media\/placeholder.jpg"}]';

        $this->assertSame($json, $this->plugin->afterGetGalleryImagesJson($block, $json));
    }

    public function testMainProductImageIsStripped(): void
    {
        $this->assertSame(
            'https://x.test/media/cache/1/a.jpg',
            $this->plugin->afterGetMainProductImage(
                $this->createMock(GalleryViewModel::class),
                'https://x.test/media/cache/1/a.jpg?___store=se'
            )
        );
    }

    public function testMainProductImageToleratesNull(): void
    {
        $this->assertNull(
            $this->plugin->afterGetMainProductImage($this->createMock(GalleryViewModel::class), null)
        );
    }

    private function galleryBlock(string $storeCode): GalleryBlock
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn($storeCode);

        $product = $this->createMock(Product::class);
        $product->method('getStore')->willReturn($store);

        $block = $this->createMock(GalleryBlock::class);
        $block->method('getProduct')->willReturn($product);

        return $block;
    }
}
