<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates (or refreshes) the "Terms & Conditions" CMS page served at
 * terms-conditions, linked from the footer.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateTermsConditionsCmsPage implements DataPatchInterface
{
    private const IDENTIFIER = 'terms-conditions';
    private const TITLE = 'Terms & Conditions';
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
<h3><strong>購入における同意／契約の締結</strong></h3>
<p>ご注文に対する全額のお支払いが受領、承認されると、購入の合意に至ります。</p>
<h3><strong>価格</strong></h3>
<p>すべての価格に消費税、送料、関税等が含まれています。ご注文いただいた後、お会計時に表示される合計金額が最終的にお支払いいただく金額となります。追加費用は一切発生しません。カーペットアベニューは、為替レートや世界の絨毯市場の変動により、価格を変更する権利を有します。</p>
<h3><strong>配送</strong></h3>
<p>送料は無料となっております。<br>一部のカーペットはヨーロッパの倉庫に保管されており、出荷前に日本のメイン倉庫にて検品を行う必要があります。そのため、発送までに数日お時間をいただく場合がございます。<br>注文品の発送準備が整うと、配送会社の追跡番号と配送情報が記載された確認メールが届きます。<br>特定の注文に関するより正確な情報については、カスタマーサービスチーム info@carpetavenue.jp までいつでもお問い合わせください。<br>ご注文確定（銀行振込の場合弊社が確認した日）から配達にかかる日数は、通常 5～14営業日です。沖縄、北海道、離島などへはプラス数日かかります。</p>
<h3><strong>私書箱へのお届け</strong></h3>
<p>私書箱への配送はお受けできません。</p>
<h3><strong>返品保証（30日以内）</strong></h3>
<p>お客様に十分納得していただくため、30日の返品保証期間を提供させていただいております。商品をお受け取りいただいた日から30日以内にご返品いただいた場合は、ご返金いたします。ご返金は、お会計時にご利用になった方法で行われます。すべての払い戻しを受けるためには、ご返品いただく絨毯がお受け取り時と変わらず、未使用の状態である必要があります。配送中の損傷を防ぐため、絨毯をお受け取りになった時と同じように梱包してください。</p>
<h3><strong>返品</strong></h3>
<p>返品をご希望の際は、まず弊社のカスタマーサービスまでご連絡いただくと、返品の手順をお伝えいたします。お客様の責任で、絨毯が傷つかないよう弊社の倉庫まで返品していただきますようお願いいたします。</p>
<p>絨毯が返品・検品された後、お買い上げ時にご利用になったクレジットカード、もしくは銀行口座に返金いたします。</p>
<p>返品の際にカスタマーサービスチームへ連絡を頂いた場合、日本国内への返品は無料です。<br>その際、商品はお届け時と同じ状態でご返品ください。</p>
<p>絨毯が発送時と同じ状態でご返却いただけなかった場合、お品物はお受け取りできかねます。その場合、お客様負担での絨毯の返送をご希望されるかどうか確認いたします。</p>
<p>&nbsp;</p>
<h3><strong>お支払い方法</strong></h3>
<p><strong>・クレジットカード </strong></p>
<p>クレジットカード決済 (VISA/MasterCard, JCB)</p>
<p>お客様のデータは、暗号化されたリンクを通して転送され、SSLで保護されます。</p>
<p>弊社がお客様のカード、またはアカウント情報を保存することは一切ありません。</p>
<p><strong>・銀行振込</strong></p>
<p>銀行振込によるお支払い方法をご選択いただけます。</p>
<p>お客様のご注文後、弊社の銀行の情報がご注文の確認メールと共に届きます。<br>銀行にお振込の際は、ご注文番号をご記入ください。ご入金が確認され次第、お品物を発送いたします。</p>
<p>５日以内に銀行口座にご入金いただけない場合、お客様のご注文はキャンセルされます。</p>
<h3><strong>保証</strong></h3>
<p>弊社からご購入いただいたすべての製品には、保証が発生いたします。保証期間は、お受け取りの日から１年間です。保証は、絨毯が通常のご家庭で、日常的にご使用をされていた時のみ適用されます。絨毯が不適切、または過度なご使用（シミ、化学物質のご使用、外観の損傷、ペットによる汚れ、取り扱い不良等）と見なされた時は、お客様に修復費用をお支払いいただきます。弊社の専門スタッフが絨毯を調べ、損傷の原因を特定いたします。保証サービスと修復にかかる費用は、お客様にご負担いただきます。</p>
<h3><strong>本物保証</strong></h3>
<p>お届けする絨毯は、ウェブサイトに記載されている通り、本物の手織り製品であることを保証いたします。ご注文の際、25,000円以上の手織り絨毯それぞれに、本物であることを示す署名入りの証明書をおつけいたします。25,000円未満の絨毯に証明書をご希望される場合、お問い合わせいただければ発行させていただきます。</p>
<h3><strong>配送時の問題、損傷</strong></h3>
<p>配送時にご注文内容に問題が発生した場合、（間違った絨毯が届いた、絨毯が損傷していた、絨毯の数に誤りがあった等）お早めにカスタマーサービスまでご連絡ください。問題は、お受け取り後24時間以内にご報告いただく必要があります。</p>
<p>お受け取り時に外側の梱包に目に見える損傷がある場合は、開封をお控えください。手つかずのまま写真を撮り、配送会社（SAGAWA/UPS）に直接ご報告ください。</p>
<h3>&nbsp;</h3>
<h3><strong>個人情報</strong></h3>
<p>ご注文またはカスタマー情報の登録の際、お客様の個人情報の一部のご提供をお願いいたします。弊社が保存する情報は、ご注文処理、またお客様にご満足いただけるサービスを提供させていただくために必要となります。配送・支払い関連会社によって開示を求められた時以外、お支払いお客様の個人情報を第三者と共有することは一切ありません。</p>
<p>個人情報の取り扱いに関するドイツの法律により、お客様は弊社が保存するご自身の情報の抜粋をお受け取りいただくことができます。もし関連性のない場合は、お客様から弊社に情報の削除及び更新を依頼することができます。</p>
<p>情報の抜粋をお望みの際は、カスタマーサービスまでご連絡ください。</p>
<h3>&nbsp;</h3>
<h3><strong>連絡先</strong></h3>
<p>株式会社 Carpet Avenue Japan（住所: 神奈川県鎌倉市常盤362-4</p>
<p>メールl: info@carpetavenue.jp</p>
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
