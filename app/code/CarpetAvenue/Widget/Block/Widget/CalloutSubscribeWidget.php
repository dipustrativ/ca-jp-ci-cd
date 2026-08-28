<?php
declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Widget;

use Magento\Framework\View\Element\Template;

class CalloutSubscribeWidget extends CalloutWidget
{
    protected $_template = "CarpetAvenue_Widget::widget/callout_subscribe.phtml";

    public function __construct(
        Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCountdown()
    {
        return $this->getData('countdown') ?? "";
    }

    public function getButtonText()
    {
        return $this->getData('popup_button_text');
    }

    public function getPopupId()
    {
        return $this->getData('popup_id');
    }

    public function getIsSticky()
    {
        return $this->getData('is_sticky');
    }
}
