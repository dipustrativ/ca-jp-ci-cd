<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Block\Adminhtml\System\Config\AttributeOverride;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ManualFixLink extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->getUrl('utilities/attributeoverride/index');
        return sprintf(
            '<a href="%s" class="action-default scalable primary" style="display:inline-block;margin-top:4px;">%s</a>',
            $this->escapeUrl($url),
            $this->escapeHtml(__('Open Manual Fix Page'))
        );
    }
}