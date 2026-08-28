<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a "Go to Retrieve Originals" link button inside the system config panel.
 */
class RetrieveLink extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->getUrl('utilities/media_retrieve/index');
        return sprintf(
            '<a href="%s" class="action-default scalable primary" style="display:inline-block;margin-top:4px;">%s</a>',
            $this->escapeUrl($url),
            $this->escapeHtml(__('Open Retrieve Originals Page'))
        );
    }
}
