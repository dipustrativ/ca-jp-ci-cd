<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates (or refreshes) the "About us" CMS page served at
 * subete-no-jutan/about, linked from the footer.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateAboutCmsPage implements DataPatchInterface
{
    private const IDENTIFIER = 'subete-no-jutan/about';
    private const TITLE = 'About us';
    private const PAGE_LAYOUT = '1column';

    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly PageInterfaceFactory $pageFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function apply(): self
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(PageInterface::IDENTIFIER, self::IDENTIFIER)
            ->create();
        $existing = $this->pageRepository->getList($criteria)->getItems();

        /** @var PageInterface $page */
        $page = $existing ? array_shift($existing) : $this->pageFactory->create();

        $page->setIdentifier(self::IDENTIFIER)
            ->setTitle(self::TITLE)
            ->setContent($this->getContent())
            ->setIsActive(1);

        if (!$existing) {
            $page->setPageLayout(self::PAGE_LAYOUT);
        }

        $this->pageRepository->save($page);

        return $this;
    }

    private function getContent(): string
    {
        return <<<'HTML'
<div class="row">
<div class="col-xs-12 col-md-6">
<h4><strong>お気軽に立ち寄れる、バラエティに富んだ手織り絨毯の世界へようこそ！</strong></h4>
<p>カーペットアベニューでは、お客様の使い易さを追求したウェブサイトを通して、幅広い種類の手織り絨毯を良心的な価格で提供するよう努めています。お客様に簡単·快適に楽しいお買い物をしていただけると幸いです。</p>
<h4><strong>弊社の絨毯</strong></h4>
<p>弊社のラインナップには、質素な条件下で作られた原始的かつ素朴な遊牧民絨毯から、時代を超えて愛されるクラシックなペルシャ絨毯、熟練した織り職人によってのみ制作可能な、ノット密度が１平方メートルあたり100万ノットを超える見事なシルク絨毯まで、バラエティに富んだ絨毯がございます。また、すべての形・サイズの手織り絨毯を提供させていただいております。</p>
<p>古代の中東都市からお客様のご自宅へ</p>
<h4><strong>カーペットアベニューを選ぶ理由</strong></h4>
<p>オンライン、店頭販売共に、膨大な知識と経験を結集した40年以上の実績がございます。</p>
<p><span style="font-weight: 400;"><strong>大切にしていること</strong> </span></p>
<p>弊社の熱意と経験を兼ね備えたベテランチームが、お客様にぴったりの絨毯を見つけるお手伝いをいたします。我々は、お客様に満足していただけることを最優先しております。</p>
<p>長期間お楽しみいただける手織り絨毯</p>
<h4><strong>最高の市場価格</strong></h4>
<p>長年にわたるサプライヤーとの強い信頼関係があるおかげで、我々は最適な絨毯を仕入れることが可能です。長期にわたって信頼関係を築いてきた絨毯生産国のパートナーから、直接絨毯を買い付けています。これにより価格を抑えることができるため、お客様に最高品質の絨毯をより良い市場価格でお求めいただくことができます。</p>
<h4><strong>公正な労働条件</strong></h4>
<p>弊社は、最高基準の倫理原則にのっとり、生産国で最高の労働条件を保証しております。また、生産者に与えられる権利と基準は定期的に見直しております。</p>
<h4><strong>たゆまぬ努力</strong></h4>
<p>弊社は、お客様に最高のオンラインショッピングをお楽しみいただくため、絶えず新製品を取り入れ、ウェブサイトを常に改善·向上させております。</p>
<h4><strong>満足いただける一枚を</strong></h4>
<p>お客様がまさにお探しの一枚を、そして長年お楽しみいただける絨毯を、市場価格でご入手いただくことができます。</p>
<h3><strong>お探しの絨毯はぜひカーペットアベニューからお求めください！</strong></h3>
</div>
<div class="col-xs-12 col-md-6"></div>
</div>
HTML;
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
