<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates (or refreshes) the "絨毯のお手入れ" (carpet care) CMS page served
 * at /carpet-care, linked from the footer.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateCarpetCareCmsPage implements DataPatchInterface
{
    private const IDENTIFIER = 'carpet-care';
    private const TITLE = '絨毯のお手入れ';
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
<h2><strong>絨毯は、購入してからが大切です。</strong></h2>
<p>適切なお手入れがきちんと行き届いていると、手織り絨毯は何世代にもわたって長持ちし、美しく年を重ねます。そして購入した時よりも見栄えが良くなります。</p>
<p>&nbsp;</p>
<p><strong> 下敷き </strong><span style="font-weight: 400;">&nbsp;</span></p>
<p>絨毯を固定しズレを防ぐため、保護用下敷きの使用をご検討ください。絨毯の土台として、事故の発生を防ぐだけでなく、不必要な摩耗から絨毯を保護する効果があります。</p>
<p><span class="Apple-converted-space">&nbsp;</span></p>
<p><strong>へこみ</strong></p>
<p>絨毯の上に家具を置く場合、絨毯のパイルにへこみができてしまうのを防ぐため、家具の脚にプロテクターをし、数か月ごとに数センチ家具を移動するようにしてください。家具を動かした直後には多少のへこみが見られる場合がありますが、数日時間を置くことで自然となくなります。ウールには形状記憶性があり、へこみは消える前に徐々に小さくなっていきます。へこみがなかなか消えない際には、水で湿らせた白い布を気になる部分に置いていただくことで早く効果が得られる場合があります。さらに、布の上から絨毯パイルの流れに沿って優しくアイロンがけをしてください。</p>
<p>&nbsp;</p>
<p><strong>掃除機がけ</strong></p>
<p>絨毯を清潔に保つため、定期的に掃除機をおかけください。その際、絨毯のフリンジには特にご注意いただき、掃除機がけはお控えください。絨毯の経糸がそのままフリンジになっているため、掃除機をかけることで絨毯がほつれ、損傷につながる可能性があります。掃除機がけは週に１回程度が目安です。人の出入りが頻繁な場所にあっても、特に必要でない限りは、週２回以下に留めることをお勧めいたします。</p>
<p>掃除機で吸い取ることのできなかった汚れや砂粒は、絨毯のパイルの中に残り、徐々に経糸の奥に入り込んでいきます。そこでサンドペーパーのようにノット（結び目）を擦り摩耗させてしまいます。掃除機をかける時は、常にパイルの流れに沿っておかけください。パイルの流れが分かりにくい時は、猫や犬をなでる時のように絨毯をなでてみてください。滑らかで繊維に沿っているような手触りなら、それが正しい方向です。もし抵抗を感じる場合は、それはパイルの流れに逆らった方向になります。</p>
<p>&nbsp;</p>
<p><strong>抜け毛</strong></p>
<p>絨毯がお手元に届いたばかりの際には、抜け毛が見られる場合があります。ただ、これは絨毯の品質に欠陥があったり劣っている訳ではないのでご安心ください。遊び毛は、絨毯のパイルの長さを揃える工程において、パイルに引っ掛かり、閉じ込められた余分なウールの繊維です。こうした繊維は、絨毯パイルの中から表面に出てきます。これは自然なプロセスであり、掃除機を何度かかけるうちに徐々に見られなくなります。掃除機をかける頻度を若干上げて早めに遊び毛をなくすこともできますが、常にパイルの流れに沿って掃除機をおかけください。また、過度な掃除機がけは、不必要な絨毯の摩耗につながることにご注意ください。<span class="Apple-converted-space">&nbsp;</span></p>
<p>&nbsp;</p>
<p><strong>クリーニング</strong></p>
<p>お客様ご自身で定期的かつ適切にお手入れされている場合、しばらくの間は特別なクリーニングは必要ありません。絨毯の汚れが目立つようになってきた場合は、専門業者にクリーニングをご依頼ください。汚れの程度にもよりますが、通常３～５年ごとのクリーニングをお勧めします。その際には、必ず手編み絨毯のクリーニングを専門としている、資格をもった経験のある専門業者に依頼するようご留意ください。</p>
<p><br><br></p>
<p><strong>シミ</strong></p>
<p>シミができたら、すぐに落とすことが何よりも大切です。一度乾いてしまうと、完全には消せなくなってしまうことがあります。固形物は丁寧にスプーン等で取り除き、液体をこぼしてしまった場合はすぐに拭き取ってください。湿らせた白い布やペーパータオルと刺激の少ない少量の石鹸を使い、できるだけ多くの液体を吸い取ります。化学薬品や洗剤のご使用はお控えください。 洗剤には、通常、ウールの繊維や色に刺激を与える強力な界面活性剤が含まれています。そのため、洗剤を使用することでウール繊維を守るワックスまでも洗い流し、絨毯の天然の保護コーティングまで取り除いてしまう可能性もあります。</p>
<p>汚れた場所はこすらず、優しく拭いてください。そうしないと、シミが絨毯 の奥に入り込んで繊維を傷つけたり、こぼした物が絨毯を通り抜けて床 まで浸透したりする恐れがあります。シミ取りの際は、常に汚れの外側 から内側に向かって行います。ぬるま湯をたたくように当て、乾いた布 で仕上げてください。シミが取れにくい場合は専門業者に相談し、手織 り絨毯やお客様がお使いの絨毯の素材(ウール、シルク、綿)に適さな いシミ抜き剤は決して使用しないでください。様々な種類のシミについて の更なる具体的なアドバイスについては、<a href="https://carpetavenue.jp/wash-repair">クリーニングと修理</a>をご一読 ください。</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
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
