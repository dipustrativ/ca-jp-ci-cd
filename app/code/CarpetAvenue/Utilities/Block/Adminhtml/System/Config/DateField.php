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
 * Renders a Magento calendar date-picker for system.xml config fields.
 * Stores and retrieves values in YYYY-MM-DD format (ICU: yyyy-MM-dd)
 * so they can be used directly in SQL date comparisons.
 */
class DateField extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $element->setDateFormat('yyyy-MM-dd');
        $element->setTimeFormat(null);
        return $element->getElementHtml();
    }
}
