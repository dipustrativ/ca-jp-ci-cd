<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Recreates the header + footer CMS static blocks that the carpetavenue/rugs
 * theme layout renders. Block HTML ported from the reference project.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateHeaderFooterBlocks implements DataPatchInterface
{
    public function __construct(
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly BlockInterfaceFactory $blockFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function apply(): self
    {
        foreach ($this->getBlocks() as $identifier => $data) {
            $this->saveBlock($identifier, $data['title'], $data['content']);
        }

        return $this;
    }

    private function saveBlock(string $identifier, string $title, string $content): void
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(BlockInterface::IDENTIFIER, $identifier)
            ->create();
        $existing = $this->blockRepository->getList($criteria)->getItems();

        /** @var BlockInterface $block */
        $block = $existing ? array_shift($existing) : $this->blockFactory->create();

        $block->setIdentifier($identifier)
            ->setTitle($title)
            ->setContent($content)
            ->setIsActive(1);

        if (method_exists($block, 'setStoreId')) {
            $block->setStoreId([0]);
        }

        $this->blockRepository->save($block);
    }

    /**
     * @return array<string, array{title: string, content: string}>
     */
    private function getBlocks(): array
    {
        return [
            'top_header_static-desktop' => [
                'title' => 'Header Top Static Desktop',
                'content' => <<<'HTML'
<div class="top-static-content desktop">
<ul>
<li><span class="navicon-check">&nbsp;</span>More than 32000 carpets</li>
<li><span class="navicon-check">&nbsp;</span>Free Shipping &amp; Returns within the EU</li>
<li><span class="navicon-check">&nbsp;</span>30-Day Money Back Guarantee</li>
<li><span class="navicon-check">&nbsp;</span>Satisfaction Guaranteed</li>
</ul>
</div>
HTML,
            ],
            'footer-bottom-logo' => [
                'title' => 'Footer Bottom Logo',
                'content' => <<<'HTML'
<p><img src="{{view url="images/logo_white.svg"}}" alt="Carpet Avenue"></p>
HTML,
            ],
            'footer-carpet-links' => [
                'title' => 'Footer: Carpet links',
                'content' => <<<'HTML'
<h3>カーペットアベニュー</h3>
<ul>
<li><a href="/subete-no-jutan/about">当社について</a></li>
<li><a href="/imprint">法規に基づく表記</a></li>
</ul>
HTML,
            ],
            'footer-customer-service' => [
                'title' => 'Footer: Customer service',
                'content' => <<<'HTML'
<h3>お困りですか？</h3>
<p>日本語対応のサポートチームがお手伝いいたします</p>
<p>営業時間: 10:00~18:00</p>
<p>定休日：定休日 : 水曜日、（夏季、年末年始休業）</p>
<p>メール: <a href="mailto:info@carpetavenue.jp">info@carpetavenue.jp</a></p>
HTML,
            ],
            'footer-social-links' => [
                'title' => 'Footer Social Links',
                'content' => <<<'HTML'
<p class="footer-social"><a href="https://www.facebook.com/people/Carpet-Avenue-japan/61563834092159/" target="_blank" rel="noopener"><img src="{{media url="wysiwyg/fb_inv.png"}}" alt="Facebook"></a><a href="https://www.instagram.com/carpetavenue_japan" target="_blank" rel="noopener"><img src="{{media url="wysiwyg/insta_inv.png"}}" alt="Instagram"></a><a href="https://www.youtube.com/@carpetavenue" target="_blank" rel="noopener"><img src="{{media url="wysiwyg/youtube_inv.png"}}" alt="YouTube"></a></p>
HTML,
            ],
            'footer-service-links' => [
                'title' => 'Footer: Service links',
                'content' => <<<'HTML'
<h3>サービス</h3>
<ul>
<li><a href="/catalogue-2026">ニューカタログ 2026</a></li>
<li><a href="/terms-conditions">ご利用規約</a></li>
<li><a href="/faq">よくある質問</a></li>
<li><a href="/privacy-cookie-policy">プライバシー・Cookie（クッキー）ポリシー</a></li>
<li><a href="/tips-for-buying-your-carpet">購入の際のポイント</a></li>
<li><a href="/carpet-care">絨毯のお手入れ</a></li>
<li><a href="/wash-repair">クリーニングと修理</a></li>
<li><a href="/contact">お問い合わせ</a></li>
<li><a href="/showroom">カーペットアベニュージャパンショールーム</a></li>
</ul>
HTML,
            ],
            'footer-trustpilot-badge' => [
                'title' => 'Footer Customer Loyalty',
                'content' => <<<'HTML'
<h3>顧客ロイヤルティ</h3>
<p><a href="https://jp.trustpilot.com/review/carpetavenue.jp" target="_blank" rel="noopener"><img src="{{view url="images/icons/Trustpilot_brandmark.png"}}" alt="Trustpilot badge" width="150" height="37"></a></p>
HTML,
            ],
            'footer-empty-links' => [
                'title' => 'Footer Empty Links',
                'content' => '',
            ],
            'footer-payment-logos' => [
                'title' => 'Footer: Payment logos',
                'content' => <<<'HTML'
<h3>お支払方法</h3>
<p><img src="https://cdn.carpetavenue.net/media/badges/unnamed.png" alt="Payment methods" width="291" height="95"></p>
HTML,
            ],
            'footer-delivery-partners' => [
                'title' => 'Footer: Delivery partners',
                'content' => <<<'HTML'
<h3>配送方法</h3>
<div class="row footer-ship-row">
<div class="col-xs-2 col-md-3"><img src="https://cdn.carpetavenue.net/media/badges/175px-UPS_Logo_Shield_2017.svg.png" alt="UPS"></div>
<div class="col-xs-5 col-md-6" style="padding-top: 5px;"><img src="https://cdn.carpetavenue.net/media/badges/6a1fa9dc9b25c8e37df8f9094af1388afa477267c7d7cc988126a40d80f85dab.jpeg" alt="Sagawa" width="100" height="23"></div>
</div>
HTML,
            ],
        ];
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
