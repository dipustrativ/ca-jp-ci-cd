<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Block\Adminhtml\Media;

use CarpetAvenue\Utilities\Helper\Config;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Retrieve extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getDownloadUrl(): string
    {
        return $this->getUrl('utilities/media_retrieve/download');
    }

    public function getDefaultBucket(): string
    {
        return $this->config->getGcsBucket();
    }

    public function getDefaultCredentials(): string
    {
        return $this->config->getGcsCredentials();
    }
}
