<?php
declare(strict_types=1);

namespace CarpetAvenue\Productify\Block\Adminhtml\Button\Campaign;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Import extends Field
{
    protected $_template = 'CarpetAvenue_Productify::system/config/campaign_import_button.phtml';

    public function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }
}