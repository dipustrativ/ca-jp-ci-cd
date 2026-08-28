<?php

namespace IXPF\Syncer\Api\Store;

interface StoreApi
{
    /**
     * @return StoreInformationResponseApi[]
     */
    public function getList(): array;

    /**
     * Get store info by store ID
     *
     * @param int $storeId
     * @return StoreInformationResponseApi
     */
    public function getInformation(int $storeId): StoreInformationResponseApi;
}
