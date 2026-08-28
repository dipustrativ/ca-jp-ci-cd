<?php

namespace IXPF\Syncer\Api\Store;

interface StoreInformationResponseApi
{
    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @return string
     */
    public function getStoreName(): string;

    /**
     * @return string
     */
    public function getStoreCode(): string;

    /**
     * @return string
     */
    public function getStoreBaseCurrency(): string;

    /**
     * @return string
     */
    public function getStoreDefaultDisplayCurrency(): string;

    /**
     * @return string
     */
    public function getStoreLanguage(): string;

    /**
     * @return string
     */
    public function getStoreCountry(): string;

    /**
     * @return string
     */
    public function getWebsiteName(): string;

    /**
     * @return string
     */
    public function getWebsiteCode(): string;

    /**
     * @return int
     */
    public function getWebsiteId(): int;

    /**
     * @return string
     */
    public function getStoreGroupName(): string;

    /**
     * @return string
     */
    public function getStoreGroupCode(): string;
    /**
     * @return int
     */
    public function getStoreGroupId(): int;

}
