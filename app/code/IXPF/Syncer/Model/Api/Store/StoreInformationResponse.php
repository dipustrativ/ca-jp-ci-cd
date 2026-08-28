<?php

namespace IXPF\Syncer\Model\Api\Store;

use IXPF\Syncer\Api\Store\StoreInformationResponseApi;
use Magento\Framework\DataObject;

class StoreInformationResponse extends DataObject implements StoreInformationResponseApi
{

    public function getStoreId(): int
    {
        return $this->_getData('id');
    }

    public function getStoreName(): string
    {
        return $this->_getData('name');
    }

    public function getStoreCode(): string
    {
        return $this->_getData('code');
    }

    public function getStoreLanguage(): string
    {
        return $this->_getData('language');
    }

    public function getStoreBaseCurrency(): string
    {
        return $this->_getData('base_currency');
    }

    public function getStoreDefaultDisplayCurrency(): string
    {
        return $this->_getData('default_display_currency');
    }


    public function getStoreCountry(): string
    {
        return $this->_getData('country');
    }

    public function getWebsiteName(): string
    {
        return $this->_getData('website_name');
    }

    public function getWebsiteCode(): string
    {
        return $this->_getData('website_code');
    }

    public function getWebsiteId(): int
    {
        return $this->_getData('website_id');
    }

    public function getStoreGroupName(): string
    {
        return $this->_getData('group_name');
    }

    public function getStoreGroupCode(): string
    {
        return $this->_getData('group_code');
    }

    public function getStoreGroupId(): int
    {
       return $this->_getData('group_id');
    }
}
