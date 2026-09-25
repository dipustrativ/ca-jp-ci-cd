<?php

declare(strict_types=1);

namespace CarpetAvenue\Core\Test\Unit\Plugin;

use CarpetAvenue\Core\Plugin\NoStoreOnMediaPlaceholder;
use Magento\MediaStorage\Model\File\Storage\Response;
use PHPUnit\Framework\TestCase;

class NoStoreOnMediaPlaceholderTest extends TestCase
{
    /**
     * @var NoStoreOnMediaPlaceholder
     */
    private NoStoreOnMediaPlaceholder $plugin;

    protected function setUp(): void
    {
        $this->plugin = new NoStoreOnMediaPlaceholder();
    }

    public function testPlaceholderIsMarkedUncacheable(): void
    {
        $paths = [
            '/var/www/html/pub/media/catalog/product/placeholder/image.jpg',
            '/var/www/html/pub/static/frontend/carpient/rugs/en_US/Magento_Catalog'
                . '/images/product/placeholder/image.jpg',
        ];

        foreach ($paths as $path) {
            $headers = [];
            $response = $this->createMock(Response::class);
            $response->method('setHeader')->willReturnCallback(
                function ($name, $value) use (&$headers) {
                    $headers[strtolower((string)$name)] = $value;
                }
            );

            $this->assertSame([$path], $this->plugin->beforeSetFilePath($response, $path));
            $this->assertSame(
                'no-store, no-cache, must-revalidate, max-age=0',
                $headers['cache-control'] ?? null,
                $path
            );
            $this->assertSame('no-cache', $headers['pragma'] ?? null, $path);
            $this->assertSame('0', $headers['expires'] ?? null, $path);
        }
    }

    public function testRealFileKeepsItsCacheability(): void
    {
        $paths = [
            // The resize-on-demand path: get.php built the real file, so it must
            // stay cacheable.
            '/var/www/html/pub/media/catalog/product/cache/79d62b5a/p/a/rug.png',
            '/var/www/html/pub/media/catalog/product/p/a/rug.png',
            null,
        ];

        foreach ($paths as $path) {
            $response = $this->createMock(Response::class);
            $response->expects($this->never())->method('setHeader');

            $this->assertSame([$path], $this->plugin->beforeSetFilePath($response, $path));
        }
    }
}
