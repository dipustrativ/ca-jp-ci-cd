<?php
declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Widget;

use Magento\Catalog\Helper\Data;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;

class CalloutProductWidget extends CalloutWidget
{
    protected $_template = "CarpetAvenue_Widget::widget/callout_product.phtml";

    public function __construct(
        Template\Context $context,
        protected Data $catalogHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProduct(): ?Product
    {
        return $this->catalogHelper->getProduct();
    }

    public function getFontWeight()
    {
        return $this->getData('fontweight');
    }

    public function getFontSizeTimer()
    {
        return $this->getData('fontsize_timer');
    }

    public function getShowDaysBefore()
    {
        return $this->getData('countdown_days_before');
    }
}
