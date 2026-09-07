<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates the two CMS static blocks the checkout_cart_index layout renders in
 * the left column below the item list:
 *   - trustpilot-horizontal-list : Trustpilot review carousel (same widget the
 *     home page uses, so it shows the same reviews horizontally).
 *   - icon-usps : the three trust badges (secure payment / expert consultation
 *     / 30-day money-back).
 *
 * Create-only: if a block with the identifier already exists (e.g. authored in
 * admin on production) it is left untouched, so this never clobbers live
 * content. Only missing blocks are created — which is what makes the cart
 * sections render on environments (local/staging) that lack them.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateCartTrustBlocks implements DataPatchInterface
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
            $this->createBlockIfMissing($identifier, $data['title'], $data['content']);
        }

        return $this;
    }

    private function createBlockIfMissing(string $identifier, string $title, string $content): void
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(BlockInterface::IDENTIFIER, $identifier)
            ->create();

        if ($this->blockRepository->getList($criteria)->getTotalCount() > 0) {
            // Already present (e.g. live admin content) — do not overwrite.
            return;
        }

        /** @var BlockInterface $block */
        $block = $this->blockFactory->create();
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
            'trustpilot-horizontal-list' => [
                'title' => 'Trustpilot: Cart - horizontal list',
                'content' => <<<'HTML'
<div class="trustpilot-widget" data-locale="ja-JP" data-template-id="53aa8912dec7e10d38f59f36" data-businessunit-id="60796b57fc3da40001e564fb" data-style-height="140px" data-style-width="100%" data-theme="light" data-stars="4,5">
<a href="https://jp.trustpilot.com/review/carpetavenue.jp" target="_blank" rel="noopener">Trustpilot</a>
</div>
HTML,
            ],
            'icon-usps' => [
                'title' => 'Cart: Trust badges (USPs)',
                'content' => <<<'HTML'
<div class="icon-usps__container">
<img src="{{view url="images/icons/Check.webp"}}" alt="" width="583" height="492">
<p>安全な決済</p>
</div>
<div class="icon-usps__container">
<img src="{{view url="images/icons/Phone.webp"}}" alt="" width="473" height="429">
<p>専門家へのご相談<br><strong>0467-33-5348</strong><br><strong>info@carpetavenue.jp</strong></p>
</div>
<div class="icon-usps__container">
<img src="{{view url="images/icons/30Days.webp"}}" alt="" width="622" height="523">
<p>30日間の返金保証</p>
</div>
HTML,
            ],
        ];
    }

    public static function getDependencies(): array
    {
        return [CreateHeaderFooterBlocks::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
