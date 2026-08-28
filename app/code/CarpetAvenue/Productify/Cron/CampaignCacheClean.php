<?php
declare(strict_types=1);

namespace CarpetAvenue\Productify\Cron;

use CarpetAvenue\Productify\Helper\CampaignHelper;
use CarpetAvenue\Productify\Helper\CampaingLogger;
use DateTime;
use DateTimeZone;
use Exception;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class CampaignCacheClean
{
    const XML_PATH_CAMPAIGN_CACHE_CLEAN_ENABLED = 'campaign/cache_clean/enabled';
    const XML_PATH_CAMPAIGN_END_DATE_CACHE_CLEAN = 'campaign/cache_clean/end_date';

    public function __construct(
        protected Manager $cacheManager,
        protected ScopeConfigInterface $scopeConfig,
        protected StoreManagerInterface $storeManager,
        protected CampaignHelper $campaignHelper,
        protected CampaingLogger $campaignLogger
    ) {
    }

    public function execute(): void
    {
        $stores = $this->storeManager->getStores();
        foreach ($stores as $store) {
            try {
                $isEnabled = $this->scopeConfig->isSetFlag(
                    self::XML_PATH_CAMPAIGN_CACHE_CLEAN_ENABLED,
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                );

                if (!$isEnabled) {
                    continue;
                }

                /*$endDateTime = $this->scopeConfig->getValue(
                    self::XML_PATH_CAMPAIGN_END_DATE_CACHE_CLEAN,
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                );

                if (!$endDateTime) {
                    continue;
                }*/

                //$endDateTimeConverted = new DateTime($endDateTime, new DateTimeZone('Europe/Stockholm'));
                $currentDateTime = new DateTime('now', new DateTimeZone('Europe/Stockholm'));
                /*if ($currentDateTime < $endDateTimeConverted) {
                    continue;
                }*/
                $campaignsCleared = false;
                $clearCampaignName = [];
                foreach ($this->campaignHelper->getActiveCampaigns() as $campaign) {
                    // cache clear if campaign end_date_time has passed
                    if (isset($campaign['end_datetime']) && $campaign['end_datetime']) {
                        $campaignEndDate =  new DateTime($campaign['end_datetime']);
                        if ($currentDateTime >= $campaignEndDate) {
                            $campaignsCleared = true;
                            $clearCampaignName[] = $campaign['title'] ?? 'N/A';
                        }
                    }
                }
                if ($campaignsCleared) {
                    //-- Clean all caches
                    //$allAvailableCaches = $this->cacheManager->getAvailableTypes();
                    $allAvailableCaches = ['layout', 'block_html', 'carpetavenue_top_menu_cache', 'full_page', 'config'];
                    $this->cacheManager->flush($allAvailableCaches);
                    $this->campaignLogger->info(
                        'Cache cleared for store: ' . $store->getName() . ' (ID: ' . $store->getId() . ') due to campaign end date. Cleared campaigns: ' . implode(', ', $clearCampaignName)
                    );
                }
            } catch (Exception $e) {
                $this->campaignLogger->error(
                    'Error while checking campaign cache clean for store: ' . $store->getName() . ' (ID: ' . $store->getId() . '). Error: ' . $e->getMessage()
                );
                continue;
            }
        }
    }
}