<?php

namespace CarpetAvenue\Currency\Model;

class AverageRates extends \Magento\Framework\Model\AbstractExtensibleModel
{
    public function _construct()
    {
        $this->_init(\CarpetAvenue\Currency\Model\ResourceModel\AverageRates::class);
    }

    /**
     * @inheritDoc
     */
    protected function getCustomAttributesCodes()
    {
        return [
            'id',
            'currency_from',
            'currency_to',
            'rate',
            'rate_time'
        ];
    }

}