<?php
declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Widget;

use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;

class CalloutWidget extends Template implements BlockInterface
{
    protected $_template = "CarpetAvenue_Widget::widget/callout.phtml";

    public function __construct(
        Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getMessage()
    {
        return $this->getData('message');
    }

    public function getBgColor()
    {
        return $this->getData('bgcolor');
    }

    public function getTextColor()
    {
        return $this->getData('textcolor');
    }

    public function getFontSize()
    {
        return $this->getData('fontsize');
    }

    public function getCloseBtn()
    {
        return $this->getData('closebtn');
    }

    public function getCountdown()
    {
        return $this->getData('countdown');
    }

    public function getShowDaysBefore()
    {
        return $this->getData('countdown_days_before');
    }
}
