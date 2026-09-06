<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Sets the 404 (no-route) CMS page content and forces the cms-full-width
 * page layout so the storefront 404 renders the localized message without
 * the additional sidebar.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class SetNoRouteCmsPage implements DataPatchInterface
{
    private const IDENTIFIER = 'no-route';
    private const PAGE_LAYOUT = 'cms-full-width';

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
            ->setContent($this->getContent())
            ->setPageLayout(self::PAGE_LAYOUT)
            ->setIsActive(1);

        if (!$page->getTitle()) {
            $page->setTitle('404 Not Found');
        }

        $this->pageRepository->save($page);

        return $this;
    }

    private function getContent(): string
    {
        return <<<'HTML'
<div class="text-center">
<h3 class="margin-bottom20"><strong>お探しのページは見つかりませんでした。</strong></h3>
<p class="margin-bottom30">お探しのページは存在しない、または削除された可能性があります。</p>
<div>&nbsp;</div>
<p>&nbsp;</p>
<a class="btn btn-secondary" href="https://carpetavenue.jp/">トップページに戻る</a></div>
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
