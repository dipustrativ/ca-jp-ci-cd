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

use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

/**
 * System Configuration Getter
 *
 * Get Store System Configuration Data
 *
 */
class SysConfig extends AbstractHelper
{
    /**
     * Class Constructor
     * */
    public function __construct(
        Context $context
    ) {
        parent::__construct($context);
    }

    /**
     *
     * Store's System Configuration Data Getter
     *
     * @param string $path <p>
     * Full Config Path Store Configuration Values.
     * Specifically from core_config_data table
     * </p>
     * @return mixed
     */
    public function getConfig(string $path): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
    }
}
