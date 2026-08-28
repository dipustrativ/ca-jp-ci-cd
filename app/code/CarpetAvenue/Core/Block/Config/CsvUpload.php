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

namespace CarpetAvenue\Core\Block\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class CsvUpload extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = '<input type="file" id="' . $element->getHtmlId() . '" name="csv_file" accept=".csv" class="input-file" />';
        $html .= '<div id="csv-upload-result-' . $element->getHtmlId() . '" style="margin-top: 10px; min-width: 500px;"></div>';
        return $html;
    }
}
