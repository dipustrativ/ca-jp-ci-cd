<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Plugins\Adminhtml\Creditmemo;

use Magento\Sales\Block\Adminhtml\Order\Creditmemo\Create\Adjustments;

class DisableCreditmemoCurrencyFormatting
{


    /**
     * This disabled the formatting of price in creditmemo adjustments text fields.
     * This was causing issues when value was 1000 and above.
     * $1200 would become $1,2 because the formatted price was $1,200
     *
     * @param Adjustments $subject
     * @param callable $proceed
     * @param $value
     * @return mixed
     */
    public function aroundFormatValue(Adjustments $subject, callable $proceed, $value)
    {
        if ($value && $value > 999) {
            $retVal = $value;
        }
        else{
            $retVal = $proceed($value);
        }
        return preg_replace('/[^\\d.,]+/','', (string)$retVal);
    }
}
