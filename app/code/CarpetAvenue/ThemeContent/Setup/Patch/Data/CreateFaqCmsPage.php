<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates (or refreshes) the "FAQ" CMS page served at faq,
 * linked from the footer.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateFaqCmsPage implements DataPatchInterface
{
    private const IDENTIFIER = 'faq';
    private const TITLE = 'FAQ';
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
<h3><span style="text-decoration: underline;"><strong>カーペット全般について</strong></span></h3>
<p>&nbsp;</p>
<p><strong><em>パソコン画面に表示されている絨毯の色が正確に表示されていることを確認するためにはどうすればよいですか?</em></strong></p>
<p>全ての写真やビデオは、絨毯の色を可能な限り正確に示しています。コンピューターのモニターの違いによって、色合いはわずかに異なる場合があります。</p>
<p>&nbsp;</p>
<p><strong><em>それぞれの絨毯に関する情報はどのくらい正確ですか？</em></strong></p>
<p>私達はお客様に快適なお買い物をしていただくために、できるだけ多くの情報を提供するよう努めております。</p>
<p>カーペットアベニューのウェブサイトにある絨毯は全て仕入れの際に入念に検査しています。仕入れ後には写真を撮って再度検査し、さらに梱包の段階で三度目の検査を行います。</p>
<p>もし何らかの情報に不備があった場合は、出荷前にお客様にお知らせしております。</p>
<p>&nbsp;</p>
<p><strong><em>カーペットの寸法にフリンジは含まれますか？</em></strong></p>
<p>ウェブサイトに表示されている寸法には、フリンジの長さは含まれておりません。 手織り絨毯は一つ一つ手作業で作られているため、サイズと形状に若干のばらつきが生じる可能性があります。特に質素な条件下で作られている遊牧民の絨毯、村絨毯にこの傾向が見られます。</p>
<p>&nbsp;</p>
<p><strong><em>なぜ手作りの絨毯には不揃いがあるのでしょうか？</em></strong></p>
<p>絨毯の性質上、意図しない不揃いがどうしても起こり得ます。手織りの絨毯に見られる僅かな違いは、絨毯の魅力や個性とみなされ、総合的な価値を高めます。</p>
<p>一般的に、遊牧民の絨毯は農村部の原始的な織機で織られるため、工房で織られる絨毯よりも不揃いが目立ちやすくなります。</p>
<p>&nbsp;</p>
<p><strong><em>絨毯のノット密度を計算するにはどうすればよいですか？</em></strong></p>
<p>絨毯のノット密度は、１平方メートルあたりのノットの数のことを示します。お持ちのどの手織り絨毯も、簡単に計算することができます。絨毯の裏側の均一な場所を選び、縦、横共に１cmの長さに含まれるノットの数を数えます。その数を互いに掛け合わせ、さらに10,000をかけます。つまり、縦の１cm内に３ノット、横の１cm内に４ノットある場合、３×４×10,000＝120,000　つまり１平方メートルあたり、12万ノットになります。</p>
<p>&nbsp;</p>
<p><strong><em>なぜ絨毯に抜け毛や遊び毛が見られるのでしょうか？</em></strong></p>
<p><em><span style="font-weight: 400;">新しい絨毯の毛が抜けるのは珍しいことではありません。これは特に心配することではなく、ご使用を始められてから最初の数週間以内に徐々に見られなくなります。遊び毛は、絨毯のパイルの長さを揃える工程において、パイルの中に閉じ込められた余分な繊維であり、使用している内に自然とパイル表面に出てきます。</span></em></p>
<p><em><span style="font-weight: 400;">週に１～２回ほど定期的にパイルの流れに沿って掃除機をかけていただくことで、徐々になくなっていきます。これよりも早く抜け毛・遊び毛をなくすことはできません。<br>無理に処理しようとすると、絨毯を不必要に傷めてしまう原因となります。</span></em></p>
<p><em><span style="font-weight: 400;">ペルシャ絨毯は、数回掃除機をかけると抜け毛が見られなくなりますが、インド絨毯においてはもう少し時間がかかるかもしれません。</span></em></p>
<p>&nbsp;</p>
<p><strong><em>カーペットアベニューの絨毯は子ども達によって作られた物ですか？</em></strong></p>
<p>カーペットアベニューのバイヤーは、弊社の絨毯の仕入れ・生産が行われる工房や村に対し継続的な監督及び査察を行っています。</p>
<p>万が一違反が見つかった場合には、業務提携を即座に終了しています。</p>
<p>&nbsp;</p>
<h3><span style="text-decoration: underline;"><strong>支払いとセキュリティ</strong></span></h3>
<p><span style="font-weight: 400;">&nbsp;</span></p>
<p><strong><em>どの支払方法を選択できますか？</em></strong></p>
<p>銀行振込、またはクレジットカード (VISA / MasterCard, JCB)でお支払いただけます。お会計時にご希望の支払い方法を選択し、表示された手順に沿ってお進みください。</p>
<p><em><span style="font-weight: 400;"><br>&nbsp;</span></em></p>
<p><strong><em>お支払方法は安全ですか？</em></strong></p>
<p>弊社は、当ウェブサイトにおける全ての取引が、最新のセキュリティー基準に従って安全に対応されることを最優先にしています。情報保護と不正行為防止のため、クレジットカードによるお支払いは、全て暗号化された回線を通して処理されます。</p>
<p>&nbsp;</p>
<h3><span style="font-weight: 400;">&nbsp;</span><strong><span style="text-decoration: underline;">ご注文</span></strong></h3>
<p><span style="font-weight: 400;">&nbsp;</span></p>
<p><strong><em>絨毯の購入方法を教えてください。</em></strong><em>&nbsp;</em></p>
<p>お探しの絨毯が見つかった場合、緑の「カートに追加する」のボタンをクリックし、ショッピングカートに入れてください。</p>
<p>複数の絨毯を購入する際も、同じ手順でそれぞれの絨毯をショッピングカートに追加してください。</p>
<p>ご希望商品を選び終えたら「会計に進む」のボタンをクリックし、手順に従います。ご注文が確定されたら、注文番号とともに確認メールが届きます。</p>
<p>&nbsp;</p>
<p><strong><em>注文を変更またはキャンセルできますか？</em></strong></p>
<p>ご注文が確定しお支払いがお済みになると、弊社倉庫にてご注文の対応が行われます。この場合、ご注文のキャンセルまたは変更を保証することはできません。</p>
<p>ご注文の変更を希望される場合には、メール（info@carpetavenue.jp）にてお早めにカスタマーサービスまでご連絡ください。</p>
<p>&nbsp;</p>
<p><strong><em>注文はどこで確認できますか？</em></strong></p>
<p>お客様のご注文が確定されると、注文番号とともに確認メールが届きます。カーペットアベニューのアカウントにログインいただくか、もしくは確認メールのリンクをクリックすると、ご注文の最新情報がご確認いただけます。</p>
<p>さらにサポートが必要な場合は、メール（info@carpetavenue.jp）にてカスタマーサービスまでお問い合わせください。</p>
<p><span style="font-weight: 400;">&nbsp;</span><span style="font-weight: 400;">&nbsp;</span></p>
<h3><span style="text-decoration: underline;"><strong>配送、返品</strong></span></h3>
<p><span style="font-weight: 400;">&nbsp;</span></p>
<p><strong><em>送料はいくらかかりますか？</em></strong></p>
<p>1回のご注文金額が5,000円（税込）未満の場合、送料として一律900円を頂戴いたします。<br>なお、1回のご注文金額が5,000円（税込）以上の場合は、送料無料でお届けいたします。</p>
<p><em><span style="font-weight: 400;">&nbsp;</span></em></p>
<p><strong><em>配送日数はどのぐらいですか？</em></strong></p>
<p>一部のカーペットはヨーロッパの倉庫に保管されており、出荷前に日本のメイン倉庫にて検品を行う必要があります。そのため、発送までに数日お時間をいただく場合がございます。<br>注文品の発送準備が整うと、配送会社の追跡番号と配送情報が記載された確認メールが届きます。<br>特定の注文に関するより正確な情報については、カスタマーサービスチーム&nbsp;<a class="c-link" href="mailto:info@carpetavenue.jp" target="_blank" rel="noopener noreferrer" data-stringify-link="mailto:info@carpetavenue.jp" data-sk="tooltip_parent" aria-haspopup="menu" aria-expanded="false">info@carpetavenue.jp</a>&nbsp;までいつでもお問い合わせください。<br>ご注文確定（銀行振込の場合弊社が確認した日）から配達にかかる日数は、通常 5～14営業日です。沖縄、北海道、離島などへはプラス数日かかります。</p>
<p><em>&nbsp;</em></p>
<p><strong><em>絨毯が安全に配送されるため、どのような梱包をしていますか？</em></strong></p>
<p>弊社が提携しているUPS/SAGAWAの規則に従い梱包されます。</p>
<p>絨毯は全て折りたたまれ、厚い保護包装の中に梱包されます。表面先が小さくなりコンパクトになることで、絨毯を安全にお届けすることができます。</p>
<p><em><span style="font-weight: 400;">&nbsp;</span></em></p>
<p><strong><em>オンライン上で注文と発送を追跡できますか？</em></strong></p>
<p>お客様の注文が弊社の倉庫で手続きされ絨毯の集荷の準備が整いますと、弊社からのメールがお客様に届きます。このメールには、オンラインで注文を確認されるために必要な情報が記載されております。<br>詳細につきましては、注文の発送確認メールに記載された内容をご確認の上、配送会社までお問い合わせください。</p>
<p><em><span style="font-weight: 400;">&nbsp;</span></em></p>
<p><strong><em>私書箱へ配送していただくことは可能ですか？</em></strong></p>
<p><span style="font-weight: 400;">私書箱への配送はできません。全ての商品は、お受け取りの際にお客様の受け取りの印もしくはサインを頂く必要がございます。</span></p>
<p><em><span style="font-weight: 400;">&nbsp;</span></em></p>
<p><strong><em>注文した絨毯に満足していません。返品は可能でしょうか？</em></strong></p>
<p>ご注文いただいた絨毯にご満足いただけない場合は、商品が届いた日から30日以内にご返品ください。<br>返品の詳細につきましては、カスタマーサービスまでメール(info@carpetavenue.jp)、にてお問い合わせください。</p>
<p>&nbsp;</p>
<p><strong><em>絨毯の返品手続きについて教えてください。</em></strong></p>
<p>絨毯がお気に召されず返品をご希望の場合は、メール（info@carpetavenue.jp）、にてカスタマーサービスまでお問い合わせください。</p>
<p>&nbsp;</p>
<p><strong><em>返品の際の送料は自己負担ですか？</em></strong></p>
<p>返品の際にカスタマーサービスチームへ連絡を頂いた場合、日本国内への返品は無料です。<br>その際、商品はお届け時と同じ状態でご返品ください。もし汚れが見つかった場合、クリーニング代として5000円を請求させていただきます。費用は、お客様へのご返金金額から差し引かせていただきます。</p>
<p>&nbsp;</p>
<p><strong><em>注文した絨毯が傷ついていた場合はどうすればよいですか？</em></strong></p>
<p>梱包と絨毯自体に明らかな損傷が見られた場合、お受け取りを拒否していただくことが可能です。お品物を開封した際に配送上の損傷が見られた場合、写真を撮るなど証拠を残し、お早めに配送会社までご報告ください。</p>
<p>さらにカスタマーサービスまでメール（info@carpetavenue.jp）、にてお知らせください。</p>
<p>&nbsp;</p>
<h3><span style="text-decoration: underline;"><strong>絨毯のお手入れとメンテナンス</strong></span></h3>
<p><span style="font-weight: 400;">&nbsp;</span></p>
<p><strong><em>絨毯に掃除機をかける場合、どのようにすればよいですか？</em></strong></p>
<p>砂利、砂、土などが絨毯のパイルの中に入り込んでしまわないよう、掃除機のご利用をお願いします週に１度から２度以内で掃除機をかけることをお勧めいたします。過度に掃除機がけを行うと、不必要な摩耗や破損につながり、劣化を早めます。</p>
<p>掃除機をかけていただく際、強弱切替が強になっていないかお確かめください。また、一箇所に掃除機をかけたらノズルを持ち上げて移動し、新しい箇所を吸引するようにしてください。ノズルを持ち上げずに掃除機を前後に動かしながら吸引したり、フリンジに掃除機をかけたりすることはお避けください。絨毯のほつれにつながります。</p>
<p>掃除機は常にパイルの流れに沿っておかけください。まず手でパイルの流れを確認します。もしパイルを手でなでた際滑らかに感じましたら、それが正しい方向となります。逆に絨毯をなでた時、抵抗や盛り上がりを感じる場合、パイルの流れに反した間違った方向となります。</p>
<p>&nbsp;</p>
<p><strong><em>絨毯をどのように保管すればよいですか？</em></strong></p>
<p>絨毯は天然繊維でできており、「呼吸」できる状態にしてあげる必要があります。地下室や倉庫など、じめじめした湿気の多い場所での保管は絶対にお止めください。</p>
<p>パイル部分を内側にして優しく巻き、絨毯がほどけないよう２、３本のひもでしっかりと縛ってください。保護用の綿やリネンのベッドシーツで防虫剤とともに包み、寝かせて保管するのが理想的です。立てた状態で保管すると、時間の経過によって形状を損なう恐れがあるため絶対にお止めください。</p>
<p>絨毯を長時間保管する必要がある場合には、３か月ごとに絨毯を広げて掃除機をかけ、効果の薄れた防虫剤を交換することをお勧めいたします。</p>
<p>&nbsp;</p>
<p><strong><em>絨毯の折り目やしわを取り除くにはどのようにすればよいですか？</em></strong></p>
<p>絨毯を数日間平らな場所に広げておけば、通常折り目やしわは自然となくなります。ウールは形状記憶性があり、日常的に使用しているうちに、通常折り目やしわは徐々に見られなくなります。</p>
<p>それでも折り目やしわが残っている場合は、重い本やその他安全な物を気になる部分に置くと、早めに効果が得られます。</p>
<p>もし効果が見られない場合は、布を冷水に浸しすすいだ後、気になる部分の上に置く方法もあります。次に、パイルの流れに沿って、数回布の上を優しくアイロンがけしてください。</p>
<p><em><span style="font-weight: 400;">&nbsp;</span></em></p>
<p><strong><em>自分で絨毯を洗うことはできますか？</em></strong></p>
<p>手織り絨毯をご家庭で洗うことはお勧めしておりません。必要がある場合は、絨毯のクリーニングを取り扱う専門業者に依頼してください。</p>
<p><span style="font-weight: 400;">&nbsp;</span></p>
<p><strong><em>シミを取り除くためにはどうすればよいですか？</em></strong></p>
<p>シミを取り除くためには、シミがついたらすぐに取り除くことが肝心です。一度乾いてしまうと、完全には消せなくなってしまうことがあります。万が一に備え、手織り絨毯に適した、なるべく刺激の少なく強力なシミ抜き剤をご自宅にご用意ください。</p>
<p>様々な種類のシミの落とし方の詳細につきましては、絨毯のお手入れ方法のページをご参照ください。</p>
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
