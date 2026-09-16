<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Marketing
 * @package   CarpetAvenue_Trackify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Trackify\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use CarpetAvenue\Core\Helper\SysConfig;

class TagManagerHelper extends AbstractHelper
{
    /**
     * @var string setting for activating GTM
     */
    public const XML_PATH_ACTIVE = 'carpetavenue_analytics/tagmanager/active';

    /**
     * @var string setting for the GTM container ID
     */
    public const XML_PATH_CONTAINER_ID = 'carpetavenue_analytics/tagmanager/container_id';

    /**
     * @param Context $context
     * @param SysConfig $sysConfig
     */
    public function __construct(
        protected Context $context,
        protected SysConfig $sysConfig
    ) {
        parent::__construct($context);
    }

    /**
     * Check if GTM is enabled.
     *
     * @return bool
     */
    public function isGoogleTagManagerEnabled(): bool
    {
        return (bool) $this->sysConfig->getConfig(self::XML_PATH_ACTIVE);
    }

    /**
     * Configured GTM container ID, or an empty string if it is not a usable one.
     *
     * The value lands in a JS string literal, and the field is the obvious place for
     * somebody to paste an entire <script> snippet copied out of the GTM UI. Validate
     * the shape here, once, rather than trusting the admin input downstream.
     *
     * @return string
     */
    public function getContainerId(): string
    {
        $containerId = trim((string) $this->sysConfig->getConfig(self::XML_PATH_CONTAINER_ID));

        return preg_match('/^GTM-[A-Z0-9]+$/', $containerId) === 1 ? $containerId : '';
    }
}
