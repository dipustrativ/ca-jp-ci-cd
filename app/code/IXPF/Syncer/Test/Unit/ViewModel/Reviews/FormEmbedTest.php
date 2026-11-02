<?php

declare(strict_types=1);

namespace IXPF\Syncer\Test\Unit\ViewModel\Reviews;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReviewLogger;
use IXPF\Syncer\ViewModel\Reviews\FormEmbed;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The iframe URL ends up in a src attribute, so what this class refuses matters more than what
 * it builds. Each test below names a way a crafted invite link could otherwise reach the form.
 */
class FormEmbedTest extends TestCase
{
    private const VALID_TOKEN = 'eessG37jcBLrMOzfjhKaOiFvXwNdNkF7Uqkm7JbzLOs';

    private const STORE_URL = 'https://www.carpetavenue.de/';

    public function testBuildsUrlFromAllowedParams(): void
    {
        $url = $this->viewModel(['token' => self::VALID_TOKEN, 'sku' => 'VAN211640', 'rating' => '4'])
            ->getFormUrl();

        $this->assertStringStartsWith('https://review.carpetavenue.com/r/' . self::VALID_TOKEN . '?', $url);
        $this->assertStringContainsString('sku=VAN211640', $url);
        $this->assertStringContainsString('rating=4', $url);
        $this->assertStringContainsString('parent=' . rawurlencode('https://www.carpetavenue.de'), $url);
    }

    public function testUnknownParamsAreDropped(): void
    {
        $url = $this->viewModel([
            'token'    => self::VALID_TOKEN,
            'redirect' => 'https://evil.example',
            'foo'      => 'bar',
        ])->getFormUrl();

        $this->assertStringNotContainsString('evil.example', $url);
        $this->assertStringNotContainsString('foo', $url);
    }

    #[DataProvider('rejectedTokens')]
    public function testRejectedTokenMakesPageUnavailable(?string $token): void
    {
        $this->assertFalse($this->viewModel(['token' => $token])->isAvailable());
    }

    public static function rejectedTokens(): array
    {
        return [
            'missing'      => [null],
            'empty'        => [''],
            'too short'    => ['abc'],
            'traversal'    => ['../../etc/passwd'],
            'query break'  => ['abcdefghijklmnop&admin=1'],
            'quote'        => ['abcdefghijklmnop"onload="x'],
            'too long'     => [str_repeat('a', 129)],
        ];
    }

    #[DataProvider('rejectedSkus')]
    public function testRejectedSkuIsDroppedButPageStillRenders(string $sku): void
    {
        $viewModel = $this->viewModel(['token' => self::VALID_TOKEN, 'sku' => $sku]);

        $this->assertTrue($viewModel->isAvailable());
        $this->assertStringNotContainsString('sku=', $viewModel->getFormUrl());
    }

    public static function rejectedSkus(): array
    {
        return [
            'attribute break' => ['A" onload="x'],
            'space'           => ['VAN 211640'],
            'too long'        => [str_repeat('A', 65)],
        ];
    }

    #[DataProvider('ratings')]
    public function testRatingIsClampedToTheStarScale(string $rating, bool $expected): void
    {
        $url = $this->viewModel(['token' => self::VALID_TOKEN, 'rating' => $rating])->getFormUrl();

        $this->assertSame($expected, str_contains($url, 'rating='));
    }

    public static function ratings(): array
    {
        return [
            'one'          => ['1', true],
            'five'         => ['5', true],
            'zero'         => ['0', false],
            'six'          => ['6', false],
            'negative'     => ['-3', false],
            'not a number' => ['four', false],
        ];
    }

    /**
     * A cleartext form under an https page is blocked as mixed content anyway, so this fails
     * loudly at the source rather than as a blank frame with no explanation.
     */
    public function testNonHttpsBaseUrlDisablesThePage(): void
    {
        $this->assertFalse(
            $this->viewModel(['token' => self::VALID_TOKEN], 'http://review.carpetavenue.com')->isAvailable()
        );
    }

    public function testEmptyBaseUrlDisablesThePage(): void
    {
        $this->assertFalse($this->viewModel(['token' => self::VALID_TOKEN], '')->isAvailable());
    }

    public function testDisabledByConfig(): void
    {
        $this->assertFalse(
            $this->viewModel(['token' => self::VALID_TOKEN], 'https://review.carpetavenue.com', false)->isAvailable()
        );
    }

    /**
     * The port is part of an origin and part of event.origin, so dropping it here would silently
     * break the height listener against any host not on 443 - which is every local harness.
     */
    public function testExpectedOriginKeepsThePort(): void
    {
        $viewModel = $this->viewModel(['token' => self::VALID_TOKEN], 'https://localhost:8443');

        $this->assertSame('https://localhost:8443', $viewModel->getExpectedOrigin());
    }

    public function testTrailingSlashInBaseUrlDoesNotDoubleUp(): void
    {
        $url = $this->viewModel(['token' => self::VALID_TOKEN], 'https://review.carpetavenue.com/')->getFormUrl();

        $this->assertStringStartsWith('https://review.carpetavenue.com/r/', $url);
    }

    private function viewModel(
        array $params,
        string $baseUrl = 'https://review.carpetavenue.com',
        bool $enabled = true
    ): FormEmbed {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, $default = null) => $params[$key] ?? $default
        );

        $config = $this->createStub(Config::class);
        $config->method('isReviewFormEnabled')->willReturn($enabled);
        $config->method('getReviewFormBaseUrl')->willReturn($baseUrl);

        // The concrete Store, not StoreInterface: getBaseUrl() is not on the interface.
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn(self::STORE_URL);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new FormEmbed($request, $config, $storeManager, $this->createStub(ReviewLogger::class));
    }
}
