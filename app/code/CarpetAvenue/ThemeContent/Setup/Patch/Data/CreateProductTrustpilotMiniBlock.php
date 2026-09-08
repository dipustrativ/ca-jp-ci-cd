<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 *
 * Re-runs CreateProductTrustpilotBlock's create-if-missing sweep so the
 * trustpilot-mini-list block added to its list lands on installs where that
 * patch had already been applied (patch_list is keyed by class name, so editing
 * an applied patch never runs again).
 *
 * Deliberately empty: the block content stays in one place, in the parent's
 * getBlocks(). The sweep skips identifiers that already exist, so re-running it
 * only creates what is missing and never touches admin-authored content.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Setup\Patch\Data;

class CreateProductTrustpilotMiniBlock extends CreateProductTrustpilotBlock
{
    /**
     * Run after the patch whose block list this one re-applies.
     *
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [CreateProductTrustpilotBlock::class];
    }
}
