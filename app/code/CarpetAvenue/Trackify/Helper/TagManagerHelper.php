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
     * @param Context $context
     * @param SysConfig $sysConfig
     */
    public function __construct(
        protected Context $context,
        protected SysConfig $sysConfig
    ) {
        parent::__construct($context);
    }

    /** @var string setting for activating GTM
     */
    const XML_PATH_ACTIVE = 'carpetavenue_analytics/tagmanager/active';

    /**
     * @var string setting for the GTM container
     */
    const XML_PATH_CONTAINER = 'carpetavenue_analytics/tagmanager/container';
    
    /**
     * @var string setting for the GTM no-script container
     */
    const XML_PATH_NOSCRIPT_CONTAINER = 'carpetavenue_analytics/tagmanager/noscript_container';

    /**
     * Check if GTM is enabled.
     * @return boolean
     */
    public function isGoogleTagManagerEnabled(): bool
    {
        return (bool) $this->sysConfig->getConfig(self::XML_PATH_ACTIVE);
    }

    /**
     *
     * Get GTM container code.
     *
     * @return string the tag manager container (script)
     */
    public function getContainer(): string
    {
        return (string) $this->sysConfig->getConfig(self::XML_PATH_CONTAINER);
    }

    /**
     *
     * Get the GTM no-script container.
     *
     * @return string the tag manager container (script)
     */
    public function getNoScriptContainer(): string
    {
        return (string) $this->sysConfig->getConfig(self::XML_PATH_NOSCRIPT_CONTAINER);
    }

}
