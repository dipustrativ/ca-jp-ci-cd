<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Creates the CMS static block rendered on the left of the contact page.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateContactPageLeftBlock implements DataPatchInterface
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
            'contact_page_left' => [
                'title' => 'Contact Page: Left',
                'content' => <<<'HTML'
<p>絨毯探しにお困りの場合や、特定の絨毯について詳細情報が必要な場合など、絨毯に関するご相談があれば弊社がお客様をサポートいたします。</p>
<p>お気軽に電話やメールでお問い合わせください。<span class="Apple-converted-space">&nbsp;</span></p>
<p>&nbsp;</p>
<div class="col-xs-12 col-md-3">&nbsp;</div>
<div class="col-xs-12 col-md-3">
<p>&nbsp;</p>
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
