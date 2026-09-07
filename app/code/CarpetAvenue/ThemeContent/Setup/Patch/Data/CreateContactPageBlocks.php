<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates the CMS static block rendered on the top-right of the contact page.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateContactPageBlocks implements DataPatchInterface
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
            $this->saveBlock($identifier, $data['title'], $data['content']);
        }

        return $this;
    }

    private function saveBlock(string $identifier, string $title, string $content): void
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(BlockInterface::IDENTIFIER, $identifier)
            ->create();
        $existing = $this->blockRepository->getList($criteria)->getItems();

        /** @var BlockInterface $block */
        $block = $existing ? array_shift($existing) : $this->blockFactory->create();

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
            'contact_page_right_top' => [
                'title' => 'Contact Page: Right Top',
                'content' => <<<'HTML'
<div class="cms-content-border block">
<h4 style="margin-top: 0;">カスタマーサービス:</h4>
<div class="block block--table">
<div class="block block-col3 block--tablecell">
<div class="block-content">
<p><strong>営業時間: </strong>10:00~18:00</p>
<p><strong>定休日 : 水曜日、（夏季、年末年始休業）</strong></p>
</div>
</div>
</div>
</div>
<!-- Second block -->
<div class="cms-content-border block">
<h4 style="margin-top: 0;">連絡先:</h4>
<div class="block block--table">
<div class="block block-col3 block--tablecell">
<div class="block-content"><strong>メール</strong></div>
<div class="block-content">{{config path="trans_email/ident_sales/email"}}</div>
</div>
</div>
</div>
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
