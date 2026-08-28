<?php

namespace IXPF\Syncer\Model\Api\Store;

use IXPF\Syncer\Api\Store\StoreApi;
use IXPF\Syncer\Api\Store\StoreInformationResponseApi;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use IXPF\Syncer\Model\Api\Store\StoreInformationResponseFactory;

class Store implements StoreApi
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resourceConnection,
        private StoreInformationResponseFactory $storeInformationResponseFactory,
    ) {}


    /**
     * @return array|StoreInformationResponseApi[]
     * @throws LocalizedException
     */
    public function getList(): array
    {
       $stores = $this->storeManager->getStores();
       $storeData = [];
       foreach ($stores as $store) {
           $storeData[] = $this->getInformation($store->getId());
       }
       return $storeData;
    }


    /**
     * @param int $storeId
     * @return StoreInformationResponseApi
     * @throws LocalizedException
     */
    public function getInformation(int $storeId): StoreInformationResponseApi
    {
        try {
            $connection = $this->resourceConnection->getConnection();

            $store = $this->storeManager->getStore($storeId);
            if (!$store || !$store->getId()) {
                throw new LocalizedException(__('Store with ID %1 does not exist.', $storeId));
            }

            $language = $this->scopeConfig->getValue(
                'general/locale/code',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $store->getId()
            );

            $currency = $this->scopeConfig->getValue(
                'currency/options/default',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $store->getId()
            );

            $country = $this->scopeConfig->getValue(
                'general/country/default',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $store->getId()
            );

            //-- get store website details
            $website = $connection->fetchRow("SELECT * FROM `store_website` WHERE website_id = {$store->getWebsiteId()}");

            //-- get store group details
            $group = $connection->fetchRow("SELECT * FROM `store_group` WHERE group_id = {$store->getStoreGroupId()}");


            $response = $this->storeInformationResponseFactory->create();
            return $response->setData([
                'id' => $store->getId(),
                'name' => $store->getName(),
                'code' => $store->getCode(),
                'default_display_currency' => $currency,
                'base_currency' => $store->getBaseCurrencyCode(),
                'language' => $language,
                'country' => $country,
                'website_name' => $website['name'] ?? '',
                'website_code' => $website['code'] ?? '',
                'website_id' => $website['website_id'] ?? '',
                'group_name' => $group['name'] ?? '',
                'group_code' => $group['code'] ?? '',
                'group_id' => $group['group_id'] ?? '',
            ]);

        } catch (\Exception $e) {
            throw new LocalizedException(__('Error: %1', $e->getMessage()));
        }
    }
}
