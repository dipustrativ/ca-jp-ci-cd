<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates (or refreshes) the "カーペットアベニュージャパンショールーム"
 * (showroom) CMS page served at /showroom, linked from the footer.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateShowroomCmsPage implements DataPatchInterface
{
    private const IDENTIFIER = 'showroom';
    private const TITLE = 'カーペットアベニュージャパンショールーム';
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
<p>Carpet Avenue Japan ショールームへようこそ！私たちのショールームへぜひお越しください。厳選された絨毯をご覧いただけます。</p>
<p>スタッフが喜んでご予約を承り、ご来店時にご希望の絨毯をご用意いたします。</p>
<p>また、選びやすくなるように個別のご案内や情報提供も可能です。ショールームのご訪問をご希望の方は、お問合せをお願いします。</p>
<p>鎌倉店（鎌倉ショールーム）<br>〒248-0022 神奈川県鎌倉市常盤362-4 <br><a href="tel:0467-33-5349" target="_blank" rel="noopener">0467-33-5349</a>　フリーダイヤル <a href="tel:0120-486-617" target="_blank" rel="noopener">0120-486-617</a> <br>※修理クリーニング対応</p>
<p>営業時間<br>月/11:00-16:00　火/11:00-15:00　水/定休日 <br>木/11:00-16:00　金/定休日　土/定休日　日/11:00-18:00 <br>小田原店（小田原ショールーム） <br>〒250-0003　神奈川県小田原市東町3-2-5</p>
<p>倉庫を併設したショールームです。ご来店の際にはご予約をお願いします。</p>
<p>不定休<br>アクセス等は以下のサイトをご参考になさってください。</p>
<p><a class="c-link" href="https://shahram.jp/" target="_blank" rel="noopener" data-sk="tooltip_parent">https://shahram.jp</a></p>
</div>
<div class="col-xs-12 col-md-6">&nbsp;</div>
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
