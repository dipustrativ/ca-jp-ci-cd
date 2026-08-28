<?php
declare(strict_types=1);

namespace CarpetAvenue\Productify\Block\Adminhtml\System\Config\Date;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Stdlib\DateTime;

class DateTimeFormat extends Field
{
    public function __construct(
        Context  $context,
        array    $data = []
    )
    {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->setTime(true);
        $element->setDateFormat('yyyy-MM-dd HH:mm:ss');
        $html = parent::render($element);
        $html .= '<script>
                    require(["jquery", "mage/calendar"], function ($) {
                        $("#' . $element->getHtmlId() . '").calendar({
                            dateFormat: "yy-mm-dd",
                            showsTime: true,
                            timeFormat: "HH:mm:ss"
                        });
                    });
                  </script>';
        return $html;

    }
}