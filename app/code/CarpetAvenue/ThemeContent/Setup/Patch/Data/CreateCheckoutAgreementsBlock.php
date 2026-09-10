<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates the CMS static block the one-step-checkout layout renders below the
 * checkout app:
 *   - checkout-agreements-text : the consent sentence stating that placing an
 *     order accepts the privacy policy and the terms of use, with both linked.
 *
 * The `agreements-block` wrapper class is load-bearing — the theme styles the
 * sentence through `.osc-cms-block .agreements-block` in
 * `app/design/frontend/carpetavenue/rugs/Mageplaza_Osc/web/css/source/_extend.less`
 * (grey 12px, underlined black links, right-hand 66% above @screen__m). Without
 * it the block renders as unstyled body copy.
 *
 * Create-only: if a block with the identifier already exists (e.g. authored in
 * admin on production) it is left untouched, so this never clobbers live
 * content. Only missing blocks are created — which is what makes the sentence
 * render on environments (local/staging) that lack it.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateCheckoutAgreementsBlock implements DataPatchInterface
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
            'checkout-agreements-text' => [
                'title' => 'Checkout: Terms and privacy consent notice',
                'content' => <<<'HTML'
<div class="agreements-block">商品のご注文をもって、お客様が当<a href="/privacy-cookie-policy">プライバシーポリシー</a>および<a href="/terms-conditions">ご利用規約</a>に同意されたものとみなします。</div>
HTML,
            ],
        ];
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
