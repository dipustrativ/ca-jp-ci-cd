<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Core
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Core\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Performance extends AbstractHelper
{
    /**
     * Execution time calculate.
     *
     * @param float|int|null $start Execution start null end timestamp (float)
     * @param string $returnFormat [optional] Return Time Format. Millisecond - ms (Default) | Second - s
     * @param int $precision [optional] Precision
     *
     * @return float|int Start timestamp or Execution time
     */
    public function executionTime(float|int|null $start = null, string $returnFormat = 'ms', int $precision = 5): float|int
    {
        if ($start == null) {
            return microtime(true);
        }

        return match ($returnFormat) {
            's' => round(microtime(true) - $start, $precision),
            default => round(microtime(true) - $start, $precision) * 1000,
        };
    }
}
