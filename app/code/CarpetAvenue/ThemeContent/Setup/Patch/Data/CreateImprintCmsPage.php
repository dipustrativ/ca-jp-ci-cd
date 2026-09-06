<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates (or refreshes) the "特定商取引法に基づく表記" (imprint / legal
 * disclosure) CMS page served at /imprint, linked from the footer.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateImprintCmsPage implements DataPatchInterface
{
    private const IDENTIFIER = 'imprint';
    private const TITLE = '法規に基づく表記';
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
<h2>特定商取引法に基づく表記</h2>
<p><strong>販売業者: </strong>株式会社 Carpet Avenue Japan</p>
<p><strong>業務執行取締役:</strong> 中川シャーロム</p>
<p><strong>所在地:</strong>〒248-0031　神奈川県鎌倉市鎌倉山一丁目１１番７号（登記）<br>〒248-0022　神奈川県鎌倉市常盤362-4（店舗)</p>
<p><strong>電話番号: </strong>日本国内　　0467-33-5348</p>
<p>　</p>
<p><strong>電話受付時間: </strong>日本国内（日本語対応）　月、火、木、金、土、日　10：00～18：00（夏季、年末年始休業)</p>
<p><strong>メールアドレス: </strong>info@carpetavenue.jp （日本語にて対応可能）</p>
<p><strong>サイトURL: </strong>https://carpetavenue.jp/</p>
<p><strong>販売価格: </strong>各商品の紹介ページに記載している価格とします。</p>
<p><strong>商品代金以外に必要な料金: </strong>無し　（＊消費税込み。送料込み。返品の際は着払いでご返送下さい。）<br>＊銀行振り込みを選ばれる場合は振込手数料はお客様のご負担となります。</p>
<p><strong>引き渡し時期: </strong>一部のカーペットはヨーロッパの倉庫に保管されており、出荷前に日本のメイン倉庫にて検品を行う必要があります。そのため、発送までに数日お時間をいただく場合がございます。<br>注文品の発送準備が整うと、配送会社の追跡番号と配送情報が記載された確認メールが届きます。<br>特定の注文に関するより正確な情報については、カスタマーサービスチーム info@carpetavenue.jp までいつでもお問い合わせください。<br>ご注文確定（銀行振込の場合弊社が確認した日）から配達にかかる日数は、通常 5～14営業日です。沖縄、北海道、離島などへはプラス数日かかります。</p>
<p><strong>お支払い方法とお支払いの時期:</strong></p>
<p>クレジットカード決済：ご注文時にお支払いが確定します。</p>
<p>銀行振り込み：ご注文確定後、お振込先、お振込み料金をメールにてご案内させて頂きます。５日以内にお振込み頂けない場合、ご注文はキャンセルされます。<br>コンビニ決済：299,999円までの決済が可能です。メールで案内された期日までにお支払い下さい。期日を過ぎますと無効となります。<br>ペイジー決済：999,999円までの決済が可能です。メールで案内された期日までにお支払い下さい。期日を過ぎますと無効となります。<br>クレジットカード決済：ご注文時にお支払いが確定します。</p>
<p><strong>返品について: </strong>ご到着後30日以内に着払いにてご返品下さい。返金はカード決済の場合、基本的に引き落とし手続きをストップさせますので引き落としされません。その他の支払い方法の場合は銀行振込にて返金致します。</p>
<p><strong>返品期限: </strong>できる限り早い段階にメールまたはお電話にてご連絡を頂戴した後、商品到着から30日以内に返品下さい。</p>
<p><strong>返品送料: </strong>着払いにてご返品頂きますので、返品にかかるお客様のご負担はございません。</p>
<p><strong>古物商許可証: </strong>神奈川県【許可番号】第452640003314号【販売管理者】中川シャーロム</p>
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
