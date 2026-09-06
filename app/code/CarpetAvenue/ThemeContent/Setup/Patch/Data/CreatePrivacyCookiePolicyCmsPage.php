<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates (or refreshes) the "Privacy & Cookie Policy" CMS page served at
 * privacy-cookie-policy, linked from the footer.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreatePrivacyCookiePolicyCmsPage implements DataPatchInterface
{
    private const IDENTIFIER = 'privacy-cookie-policy';
    private const TITLE = 'Privacy & Cookie Policy';
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
<p>このプライバシーポリシーは、お客様が当ウェブサイトをご利用の際にカーペットアベニュー（以下、弊社）に対し提供された情報を、弊社がどのように扱い、保護するかを規定するものです。弊社は、お客様のプライバシーを確実に保護することをお約束します。ウェブサイトをご使用中に、個人情報が識別できる特定の情報の提示を求められた際には、このプライバシーに関する声明に沿ってのみ使用いたします。このポリシーは、規約ページの更新により変更されることがございます。随時こちらのページをご覧いただき、変更された点をご確認ください。</p>
<h2>カーペットアベニューが収集するデータ</h2>
<p>弊社は、以下の情報を収集する場合があります。</p>
<p>・名前<br>・メールアドレスを含む連絡先情報<br>・住所や郵便番号などの人口統計情報<br>・支払い情報</p>
<h2>個人情報を収集する目的</h2>
<p>弊社は、お客様のニーズを理解し、より良いサービスを提供するため、特に以下の理由に基づき情報を収集します。</p>
<p>・注文処理のため<br>・お客様のアカウントの登録・管理のため<br>・支払いや配送の際に問題が生じた場合にお客様へ連絡するため<br>・提供されたメールアドレス先に、お客様の興味や関心に合致した新商品やお得な情報、競合商品や他の情報などのプロモーションメールを定期的に配信するため<br>・製品やサービスの向上のため<br>・お客様のご関心に沿ってウェブサイトをカスタマイズするため</p>
<p>&nbsp;</p>
<p>セキュリティ</p>
<p>弊社は、お客様の情報が安全であることを保証いたします。不正なアクセスや情報開示を防止し、オンラインで収集した情報を保護するため、適切な物理的、電子的また経営上の手段で対応いたします。</p>
<h2>cookie（クッキー）の使い方</h2>
<p>cookieとは、コンピュータのハードドライブに保存される小さなファイルです。一度許可すると、デバイスにファイルが追加され、cookieがウェブトラフィックの解析を助けたり、お客様が特定のサイトにアクセスした時に通知したりします。cookieを使用すると、webアプリケーションはお客様を個人として識別します。webアプリケーションは、お客様の好みに関する情報を収集し記憶することにより、お客様のニーズや嗜好に合わせてオペレーションを調整します。</p>
<p>弊社は、トラフィックログcookieを使って、使用されているページを特定します。これによって、ウェブサイトのアクセスに関するデータを分析し、お客様のニーズに合わせてウェブサイトを改善することができます。この情報は統計分析での使用を目的としており、その後はシステムから削除されます。</p>
<p><br>総合的に見ると、cookieを通して、どのページがお客様にとって役に立つかそうでないかを見極めることができるため、よりよいウェブサイトを提供することができます。cookieに同意することで、弊社がお客様の選択した情報以上のデータを得たり、お客様のコンピューターにアクセスしたりすることはできません。大抵のウェブブラウザーは、自動的にcookieが有効になりますが、お客様がご希望される場合は通常ブラウザーの設定変更によってcookieを拒否することもできます。ただ、cookieを拒否することでウェブサイトを十分に利用できなくなる恐れもあります。</p>
<h2>他のウェブサイトへのリンク</h2>
<p>弊社のウェブサイト上は、他のウェブサイトへのリンクを含む場合があります。ただし、これらのリンクを利用して弊社ウェブサイトから離れてしまった場合、その外部ウェブサイトは弊社の管轄下に及ばないことをご留意ください。お客様がカーペットアベニュー以外で提供した情報の保護とプライバシーに関し、弊社では責任を負いかねます。そうしたサイトは、このプライバシーに関する声明に準拠しません。そのため、そのウェブサイトに適用されるプライバシー声明をご確認いただく必要があります。</p>
<p><strong>Facebook ソーシャルプラグインの使用</strong></p>
<p>弊社のウェブサイトでは、Facebook Inc., 1601 S. California Ave, Palo Alto, CA 94304, USA （以下、"Facebook"）が運営するFacebook ソーシャルネットワークに属するソーシャルプラグインが使われています。プラグインはFacebookのロゴ、またはFacebookのソーシャルプラグインの拡張機能（Social Plug-in of Facebookもしくは"Social Plug-in of Facebook" ）によって識別されます。 Facebookのプラグインとその概要はこちら（<strong><a class="external-link-new-window" title="Opens external link in new window" href="https://developers.facebook.com/docs/plugins">developers.facebook.com/docs/plugins</a></strong>）からご確認ください。</p>
<p>もしお客様がプラグインを含むサイトのページにアクセスすると、ブラウザーは直接Facebookのサーバーに接続します。プラグインのコンテンツは、Facebookによって直接お客様のブラウザーに転送され、統合されます。この統合により、Facebookのプロフィールをお持ちではない場合やFacebookにログインしていない場合にも、ブラウザーがウェブサイトに対応するページにアクセスしたという情報がFacebookに提供されます。この情報（IPアドレスを含む）は、お客様のブラウザーからFacebookサーバーに直接提供され、保存されます。Facebookにログインしている場合は、お客様の弊社ウェブサイトへのアクセスは、Facebookアカウントに直接紐付けられます。もし「いいね」ボタンを押したりコメントを記入したりするなどのプラグイン操作があった場合、 そうした情報は直接Facebookサーバーに送信され、保存されます。 さらに、Facebook上でもその情報は公開され、Facebookの友達にも開示されます。</p>
<p>Facebookはこの情報を広告、マーケティングや Facebookページの作成に使用することができます。さらに例えばどのようにウェブサイトを使用しているかを調べるため、 Facebook上に興味・関心、連絡先のプロファイルが生成されます。これは、 Facebookに表示される広告に基づいています。これは、他の Facebookユーザーに弊社ウェブサイト上でのお客様のアクティビティを通知し、 Facebookの使用に関連した更なるサービスを作り出すことを目的としています。もしお客様が Facebookのアカウントと弊社ウェブサイトにおける情報をリンクさせることを望まない場合は、Facebookからログアウトした後、弊社ウェブサイトにログインする必要があります。Facebookによるデータ収集、処理、利用の範囲の目的に関する情報は、Facebook上でご覧いただくことができます。さらに、お客様がお持ちの権利や、コンピューター上のどの設定によりお客様のプライバシーを守ることができるか、データ保護の説明にアクセスできるかをご覧いただけます。情報に関してはこちら&nbsp;(<strong><a href="https://www.facebook.com/policy.php">http://www.facebook.com/policy.php</a></strong>) のリンクをご覧ください。</p>
<p>&nbsp;</p>
<p><strong>Google Analyticsについてs</strong></p>
<p>弊社のウェブサイトは、Google Inc. (Alphabet) が提供するウェブ分析サービスGoogle Analyticsと、Googleコンシューマープロテクションプログラム('Google')が提供するGoogle Certified Dealersを使用しています。Google AnalyticsとGoogle Certified Dealersは、いわゆるコンピューター上に保存されている「cookie」というテキストファイルを使用し、これによってウェブサイトがどのように使用されているかを分析することができます。弊社のウェブサイトの使用に関わる情報（IPアドレスを含む）は、米国に保管されているGoogleサーバーに転送されます。ただし、ウェブサイトでIP匿名化が有効になっている場合、お客様のIPアドレスはEU加盟国もしくは欧州経済領域の協定に同意するグループ内では、Googleによりお客様のIPアドレスが事前に短縮されます。完全なIPアドレスが米国のGoogleサーバーに送信され短縮されるのは例外的な場合のみとなります。</p>
<p>Googleは、Google Analyticsの組織内でブラウザーがデータを転送する際、この情報を基にお客様のウェブサイトの使用状況を査定します。そして、ウェブサイトのアクティビティに関するレポートをまとめ、ウェブサイトのオペレーター対しお客様のウェブサイト及びインターネットの使用に関わる他のサービスを提供します。 Google Analyticsの一部としてお客様のブラウザーから提供されるIPアドレスは、他の Googleのデータとは統合されません。</p>
<p>お客様は、ブラウザーソフトウェアの設定から、cookieの保存を防ぐことができます。ただし、ウェブサイトの全ての機能を、最大限に活用できなくなる恐れもあるためご注意ください。cookieのインストールを防ぐためには、こちらのリンク（<strong><a href="https://tools.google.com/dlpage/gaoptout?hl=en">http://tools.google.com/dlpage/gaoptout?hl=en</a></strong>)　をご覧ください。</p>
<p>このウェブサイトを使用することにより、上記の方法と目的でGoogleが収集したお客様のデータの処理に同意したこととみなします。　</p>
<p>&nbsp;</p>
<p class="p1"><strong><span class="s1">Google Ads </span>とコンバージョントラッキング</strong></p>
<p class="p1"><span class="s1">当</span>サイトでは、オンラインで商品を宣伝するために<span class="s2"> Google Ads </span>を使用しています。これらの広告の効果を測定し、関連性の高い商品を表示するために、広告がクリックされた際、<span class="s2">Google </span>はお客様のデバイスに<span class="s2"> Cookie </span>を配置します。同意をいただいた場合の<span class="s2"> Google </span>による個人データの使用方法に関する詳細は、<strong><span class="s2"><a href="https://business.safety.google/privacy/">Google </a></span><a href="https://business.safety.google/privacy/">のビジネス データ責任に関するサイト</a></strong><span class="s2">&nbsp;</span>をご覧ください。</p>
<p>&nbsp;</p>
<p><span style="font-size: 26px;">ニュースレター</span></p>
<p>お客様または関係者様あてに、弊社はニュースレターをお送りします。もし既に弊社のお客様である場合、同じくe-mailアドレスを使用して、お客様に合わせて類似の商品をお勧めするニュースレターをお送りします。もしあなたが弊社のお客様ではなく、ニュースレターをご希望の場合は、e-mailアドレスが必要です。送付はあなた様の明白な同意 (Art. 6 para. 1 p. 1 lit. a DSGVO) に基づき、またあなた様が既に弊社のお客様である場合は、最新のお勧め商品をお知らせする弊社の正当な利益に基づきます。（ダイレクト・マーケティング）</p>
<p>a. パーソナリゼーション 弊社からのニュースレター送付についてお客様が同意された場合（Art. 6 para. 1 p. 1 lit. a DSGVO に基づく）、宣伝方法をお客様のお好みに合わせたり、弊社ウェブサイト上からのお勧めを最適化したりするために、弊社はニュースレターに関するお客様の反応を診断します。それらの目的のために、以下の専門サービス業者に必要な個人情報（注文データ、商品の詳細、支払い状況）を送信します。　: Klaviyo 125 Summer St, Floor 6 Boston, MA 02111 / USA (hereinafter „Klaviyo“) Klaviyoのデータ保護情報の詳細については、に関してはKlaviyo Privacy Policy here: <strong><a href="https://www.klaviyo.com/legal/dpa">https://www.klaviyo.com/legal/dpa</a></strong>　をご参照下さい。</p>
<p>b.パーソナリゼーションへの異議/同意の撤回/ニュースレターの購読停止 ニュースレターのパーソナリゼーションについては、info@carpetavenue.jp. に e-mail をお送りいただくことにより、いつでも同意を撤回することが可能です。それにより、パーソナライズ（個別化）されたニュースレターは送付されなくなります。ただし、通常のニュースレターは送付されます。 なお、ニュースレターの下段にあるリンクから、いつでもニュースレターの購読を完全に停止することが可能です。もしくは、購読停止のリクエストを info@carpetavenue.jp. までお送りください。ニュースレターに関し、また専らその送信のために集められた個人情報は、購読の停止後ただちに削除いたします。</p>
<h2>個人データの取り扱い</h2>
<p>以下の方法により、お客様の個人情報の収集、使用に制限をかけることができます。</p>
<p><br>ウェブサイト上でフォームへの入力を求められた際には、その情報がマーケティングの目的のために使用されることを希望しない、という旨のチェックボックスにチェックを入れることができます。それ以前にお客様が個人情報について同意をされていた場合、info@carpetavenue.jpへメールでご連絡いただけることで、いつでも変更できます。　弊社は、配送・支払い関連会社がお客様のご注文に関する特定の情報を必要とする場合、または法律により（簿記の規則や規制など）開示が求められた場合を除き、お客様の個人情報を、第三者に販売、流通、貸付することはございません。</p>
<p><span style="font-size: 14px;">データ保護法により、お客様は1998年データ保護法に基づいて登録されたすべての情報の抜粋を受け取る権利があります。関連性がない場合は、情報を削除するか、もし誤りや不備がある場合は訂正を求めることができます。 弊社がもつ情報のコピーに関しては、こちら（info@carpetavenue.jp）からお問い合わせください。</span></p>
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
