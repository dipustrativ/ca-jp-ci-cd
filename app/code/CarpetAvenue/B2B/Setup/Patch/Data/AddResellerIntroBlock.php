<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Seeds the CMS block that renders as the intro/description on the
 * "Become a Carpient® Reseller" page. Admin-editable under Content → Blocks;
 * the block identifier is configurable at carpetavenue_b2b/reseller/intro_block.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Setup\Patch\Data;

use CarpetAvenue\B2B\Model\Config;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Cms\Api\GetBlockByIdentifierInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\Store;

class AddResellerIntroBlock implements DataPatchInterface
{
    private const CONTENT = <<<HTML
    <p>Carpient partners exclusively with professional resellers in retail, interior design, and contract projects. We understand that every business has different needs &mdash; which is why we offer flexible partnership models:</p>
    <ul>
    <li><strong>Dropshipping</strong> from our logistics center in Germany &ndash; speed and reach without tying up capital in stock.</li>
    <li><strong>Stock purchase</strong> &ndash; unique collections and exclusivity to strengthen your positioning and margins.</li>
    <li><strong>Hybrid model</strong> &ndash; combining reach and differentiation on your terms.</li>
    </ul>
    <p>As a Carpient reseller, you gain access to our brand, exclusive collections, and the ability to tailor colors, shapes, and formats to your market. Every product is made with certified materials in CSR-approved facilities, where craftsmanship and responsibility set the standard.</p>
    <p>With Carpient, you get more than a supplier. You gain a committed creative partner who brings craftsmanship, sustainability, and design quality together &mdash; giving your assortment a clear and lasting advantage.</p>
    <p><strong>Contact us today and take the first step toward a partnership that builds value for your business.</strong></p>
    HTML;

    public function __construct(
        private readonly BlockInterfaceFactory $blockFactory,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly GetBlockByIdentifierInterface $getBlockByIdentifier
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        try {
            // Already present (e.g. re-run after admin edits): leave it untouched.
            $this->getBlockByIdentifier->execute(Config::RESELLER_INTRO_BLOCK_ID, (int)Store::DEFAULT_STORE_ID);

            return $this;
        } catch (NoSuchEntityException $e) {
            // Not there yet — create it below.
        }

        $block = $this->blockFactory->create();
        $block->setData([
            'title' => 'Become a Carpient Reseller — Intro',
            'identifier' => Config::RESELLER_INTRO_BLOCK_ID,
            'is_active' => 1,
            'stores' => [Store::DEFAULT_STORE_ID],
            'content' => self::CONTENT,
        ]);
        $this->blockRepository->save($block);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
