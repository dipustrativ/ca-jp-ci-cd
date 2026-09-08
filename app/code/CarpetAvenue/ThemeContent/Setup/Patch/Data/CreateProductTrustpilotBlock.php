<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates the CMS static blocks the catalog_product_view layout renders:
 *   - trustpilot-micro-count : Trustpilot "Micro Combo" TrustBox (green star +
 *     "See our N reviews on Trustpilot"), inside product-info-main above the
 *     product title. Same businessunit as the cart/home widgets, rendered by
 *     the Trustpilot_Reviews bootstrap script.
 *   - trustpilot-mini-list : the phone-sized review carousel, shown below 560px
 *     in .trustpilot-mini-carousel-wrapper where the 140px-tall horizontal
 *     carousel (trustpilot-horizontal-list) is hidden. Trustpilot's horizontal
 *     carousel template collapses to a bare score strip at phone widths — it
 *     renders no review cards — so live pairs it with this taller template, the
 *     same one the home page uses on mobile. Markup copied from live.
 *
 * Create-only: if a block with the identifier already exists (e.g. authored in
 * admin on production) it is left untouched, so this never clobbers live
 * content. Only a missing block is created — which is what makes the widget
 * render on environments (local/staging) that lack it.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateProductTrustpilotBlock implements DataPatchInterface
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
            'trustpilot-micro-count' => [
                'title' => 'Trustpilot: Product - micro count',
                'content' => <<<'HTML'
<div class="trustpilot-widget micro-combo" data-locale="ja-JP" data-template-id="5419b6a8b0d04a076446a9ad" data-businessunit-id="60796b57fc3da40001e564fb" data-style-height="24px" data-style-width="100%" data-theme="light" data-style-alignment="left"><a href="https://jp.trustpilot.com/review/carpetavenue.jp" target="_blank" rel="noopener">Trustpilot</a></div>
<!-- End TrustBox widget -->
HTML,
            ],
            'trustpilot-mini-list' => [
                'title' => 'Trustpilot: Product - mini carousel (mobile)',
                // The negative bottom margin is live's: this template reserves
                // ~40px of empty space under the cards inside its iframe.
                'content' => <<<'HTML'
<div class="trustpilot-widget hide-on-desktop" style="margin-bottom: -40px; margin-top: 0px; position: relative;" data-locale="ja-JP" data-template-id="539ad0ffdec7e10e686debd7" data-businessunit-id="60796b57fc3da40001e564fb" data-style-height="350px" data-style-width="100%" data-theme="light" data-stars="4,5"><a href="https://jp.trustpilot.com/review/carpetavenue.jp" target="_blank" rel="noopener">Trustpilot</a></div>
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
