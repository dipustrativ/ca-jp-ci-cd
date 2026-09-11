<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Block\Order\Info;

use Magento\Sales\Block\Order\Info\Buttons;

class CancelButton extends Buttons
{

    protected $_template = "Evalent_OrderMods::cancel-button.phtml";
}