<?php
/**
 * Copyright © Carpient. All rights reserved.
 */
declare(strict_types=1);

namespace Carpient\Theme\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the Carpient-prefixed CMS blocks used by the carpient/rugs
 * header and footer (content mirrors carpient.com).
 */
class CreateCarpientCmsBlocks implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly BlockInterfaceFactory $blockFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        foreach ($this->getBlocks() as $identifier => $data) {
            $this->upsertBlock($identifier, $data['title'], $data['content']);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Create the block if missing, otherwise refresh its title/content.
     */
    private function upsertBlock(string $identifier, string $title, string $content): void
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

        // Store 0 = All Store Views
        if (method_exists($block, 'setStoreId')) {
            $block->setStoreId(0);
        } elseif ($block instanceof \Magento\Cms\Model\Block) {
            $block->setStores([0]);
        }

        $this->blockRepository->save($block);
    }

    /**
     * @return array<string, array{title: string, content: string}>
     */
    private function getBlocks(): array
    {
        return [
            'carpient_header_usp' => [
                'title' => 'Carpient - Header USP Bar',
                'content' => <<<HTML
<ul class="carpient-usp-list">
    <li><img class="usp-icon" src="{{view url='images/usp/truck.svg'}}" alt="" width="20" height="20"> <span>Free and fast shipping</span></li>
    <li><img class="usp-icon" src="{{view url='images/usp/door.svg'}}" alt="" width="20" height="20"> <span>Home delivery</span></li>
    <li><img class="usp-icon" src="{{view url='images/usp/returns.svg'}}" alt="" width="20" height="20"> <span>60 Day Return Policy</span></li>
</ul>
HTML,
            ],
            'carpient_footer_service' => [
                'title' => 'Carpient - Footer Service Links',
                'content' => <<<HTML
<div class="footer-heading">Service</div>
<ul>
    <li><a href="/terms-and-conditions">Terms &amp; Conditions</a></li>
    <li><a href="/faq">FAQ</a></li>
    <li><a href="/privacy-and-cookie-policy">Privacy &amp; Cookie Policy</a></li>
    <li><a href="/contact">Contact</a></li>
    <li><a href="/become-a-trade-partner">Become a trade partner</a></li>
</ul>
HTML,
            ],
            'carpient_footer_company' => [
                'title' => 'Carpient - Footer Company Links',
                'content' => <<<HTML
<div class="footer-heading">Carpient</div>
<ul>
    <li><a href="/about-us">About us</a></li>
    <li><a href="/imprint">Imprint</a></li>
</ul>
HTML,
            ],
            'carpient_footer_contact' => [
                'title' => 'Carpient - Footer Contact',
                'content' => <<<HTML
<h3>Need Help?</h3>
<p>Our Customer Service team will be happy to assist you!</p>
<ul>
    <li>E-mail: <a href="mailto:info@carpient.com">info@carpient.com</a></li>
</ul>
HTML,
            ],
            'carpient_footer_copyright' => [
                'title' => 'Carpient - Footer Copyright',
                'content' => '<span class="carpient-copyright">&copy; 2026 Carpient.</span>',
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
