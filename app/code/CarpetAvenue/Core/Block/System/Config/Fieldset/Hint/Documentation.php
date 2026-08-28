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

namespace CarpetAvenue\Core\Block\System\Config\Fieldset\Hint;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Element\Template;
use Magento\Framework\Data\Form\Element\Renderer\RendererInterface;

class Documentation extends Template implements RendererInterface
{
    /**
     * @var string
     */
    protected $_template = 'CarpetAvenue_Core::system/config/fieldset/hint/documentation.phtml';

    /**
     * Render fieldset html
     *
     * @param   AbstractElement $element
     * @return  string
     */
    public function render(AbstractElement $element): string
    {
        return $this->toHtml();
    }
}
