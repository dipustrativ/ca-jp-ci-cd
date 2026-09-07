<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates (or refreshes) the "ニューカタログ 2026" catalogue-request CMS page
 * served at catalogue-2026. The page hosts the Webkul AjaxContactForm
 * ContactCatalogue widget (left column) plus a PDF download row / sticky bar.
 *
 * reCAPTCHA v3 on that form is switched on separately by
 * CarpetAvenue\Japan\Setup\Patch\Data\EnableCatalogueFormRecaptcha.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateCatalogue2026CmsPage implements DataPatchInterface
{
    private const IDENTIFIER = 'catalogue-2026';
    private const TITLE = 'ニューカタログ 2026';
    private const HEADING = 'ニューカタログ 2026';
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

        $isNew = !$existing;
        /** @var PageInterface $page */
        $page = $isNew ? $this->pageFactory->create() : array_shift($existing);

        $page->setIdentifier(self::IDENTIFIER)
            ->setTitle(self::TITLE)
            ->setContentHeading(self::HEADING)
            ->setContent($this->getContent())
            ->setIsActive(1);

        if ($isNew) {
            $page->setPageLayout(self::PAGE_LAYOUT);
            // All store views.
            $page->setData('stores', [0]);
        }

        $this->pageRepository->save($page);

        return $this;
    }

    private function getContent(): string
    {
        return <<<'HTML'
<p class="order_catalog_form_content">最新版のカタログが届きました！<br>ファンの皆さま、お待たせいたしました！<br>厳選された商品、スタイリングのヒントなど暮らしを豊かにする情報が満載です。<br>お部屋にぴったりの色合いを見つけたい方や、どんな絨毯があるのかを覗いてみたい方など<br>お気軽にカタログをご請求下さい。<br>無料でお届けします。<br>下記のフォームにご記入いただければ、カタログをご自宅へお送りします。 <span class="ca-pdf-inline"> お急ぎの方は <a href="https://carpetavenue.jp/media/catalogue/CAJ_2026catalog.pdf" target="_blank" rel="noopener"> PDF版をダウンロード </a> いただけます。 </span> <br>＊到着まで2週間前後かかりますのでご了承ください。</p>
<!-- PDF download row -->
<div class="ca-pdf-row"><a class="ca-pdf-btn" href="https://carpetavenue.jp/media/catalogue/CAJ_2026catalog.pdf" target="_blank" rel="noopener"> PDF版をダウンロード <em>(約 28 MB)</em> </a> <span class="ca-pdf-hint"> または下のフォームから冊子をご請求ください ↓ </span></div>
<!-- Existing form -->
<div class="row">
<div class="col-xs-12 col-md-6">{{widget
        type="Webkul\AjaxContactForm\Block\Widget\ContactCatalogue"
        alignment="4"
        name="ニューカタログ 2025"
        identifier="kakel_2025"
        address="-"
        email_to="shuvankar@strativ.se"
        show_full_address="2"
        thanku_msg="ご登録ありがとうございました。<br>
        その間にご不明な点がございましたら、お気軽にカスタマーサービスまでお問い合わせください。<br>
        *到着まで2週間前後かかりますのでご了承ください。"
        }}</div>
<div class="col-xs-12 col-md-6">&nbsp;</div>
</div>
<!-- Mobile sticky download bar -->
<div class="ca-pdf-sticky"><span class="ca-pdf-sticky__label"> PDF版もご用意しています </span> <a class="ca-pdf-sticky__btn" href="https://carpetavenue.jp/media/catalogue/CAJ_2026catalog.pdf" target="_blank" rel="noopener"> ダウンロード </a></div>
<style>

    /* -----------------------------------
       Catalogue 2026 PDF styles
    ----------------------------------- */

    /* Intro inline link */
    .ca-pdf-inline {
        color: #555;
    }

    .ca-pdf-inline a {
        color: #111;
        font-weight: 600;
        text-decoration: underline;
        text-underline-offset: 3px;
    }

    .ca-pdf-inline a:hover {
        color: #b327a0;
    }

    /* PDF button row */
    .ca-pdf-row {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        margin: 24px 0 32px;
    }

    .ca-pdf-btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 11px 20px;
        border: 1.5px solid #111;
        background: #fff;
        color: #111;
        font-size: 14px;
        line-height: 1;
        border-radius: 2px;
        text-decoration: none;
        transition: background .15s ease,
        color .15s ease;
    }

    .ca-pdf-btn:hover,
    .ca-pdf-btn:focus {
        background: #111;
        color: #fff;
        text-decoration: none;
    }

    .ca-pdf-btn svg {
        flex-shrink: 0;
    }

    .ca-pdf-btn em {
        font-style: normal;
        opacity: .7;
        font-size: 12px;
        margin-left: 2px;
    }

    .ca-pdf-hint {
        font-size: 12px;
        color: #888;
    }

    /* Sticky mobile bar */
    .ca-pdf-sticky {
        display: none;
    }

    /* Mobile styles */
    @media (max-width: 767px) {

        .ca-pdf-row {
            margin: 18px 0 24px;
        }

        .ca-pdf-btn {
            width: 100%;
            justify-content: center;
            padding: 13px 16px;
        }

        .ca-pdf-hint {
            width: 100%;
            text-align: center;
        }

        .ca-pdf-sticky {
            display: flex;
            align-items: center;
            gap: 10px;

            position: sticky;
            bottom: 0;
            z-index: 5;

            margin: 24px -15px 0;
            padding: 12px 16px;

            background: #fff;
            border-top: 1px solid #ddd;
            box-shadow: 0 -6px 16px rgba(0,0,0,.06);
        }

        .ca-pdf-sticky__label {
            flex: 1;
            font-size: 12px;
            color: #555;
            line-height: 1.4;
        }

        /* Sticky mobile button */
        .ca-pdf-sticky__btn {
            background: #111;
            color: #fff !important;
            padding: 10px 16px;
            font-size: 13px;
            white-space: nowrap;
            border-radius: 2px;
            text-decoration: none;
            display: inline-block;
        }

        /* Force white color for all states */
        .ca-pdf-sticky__btn,
        .ca-pdf-sticky__btn:link,
        .ca-pdf-sticky__btn:visited,
        .ca-pdf-sticky__btn:hover,
        .ca-pdf-sticky__btn:focus,
        .ca-pdf-sticky__btn:active,
        a.ca-pdf-sticky__btn,
        a.ca-pdf-sticky__btn:visited,
        .alink.ca-pdf-sticky__btn:visited {
            color: #fff !important;
            text-decoration: none;
        }

        /* Hover effect */
        .ca-pdf-sticky__btn:hover,
        .ca-pdf-sticky__btn:focus {
            opacity: .9;
        }
    }

    /* Hide address block */
    .contact_catalogue-form-panel .cform .address p {
        display: none;
    }

    /* Hide reCAPTCHA attribution notice */
    .recaptcha-attribution {
        display: none;
    }

    /* Desktop: stack field labels above inputs (override Magento's 25% right-aligned labels) */
    @media (min-width: 768px), print {
        .contact_catalogue-form-panel .fieldset > .field:not(.choice) > .label {
            padding: 6px 15px 0 0;
            text-align: left;
            width: 100%;
            box-sizing: border-box;
            float: left;
        }
    }

</style>
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
