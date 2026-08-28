<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Cron;

use CarpetAvenue\Productify\Helper\CategoryAssignLogger;
use CarpetAvenue\Productify\Service\ColorShapeCategoryAssigner;
use Exception;

/**
 * Daily job: create colour/shape categories under "All Carpets" and assign
 * newly-eligible products to them. Delegates to ColorShapeCategoryAssigner,
 * the same service the CLI command uses.
 */
class AssignColorShapeCategories
{
    public function __construct(
        private readonly ColorShapeCategoryAssigner $assigner,
        private readonly CategoryAssignLogger $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->assigner->execute();
        } catch (Exception $e) {
            $this->logger->error('[Cron] Colour/shape category assignment failed - ' . $e->getMessage());
        }
    }
}
