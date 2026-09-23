<?php

namespace CarpetAvenue\Productify\Helper;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Combine;
use Magento\SalesRule\Model\Rule\Condition\Product\Combine as CombineProductRule;
use Magento\SalesRule\Model\RuleFactory;
use IntlDateFormatter;
use Exception;
use DateTime;
use DateTimeZone;

class CampaignHelper
{
    private const DYNAMIC_CAMPAIGN_IMPORT_ENABLED = "campaign/dynamic_campaign/enable";
    private const DYNAMIC_CAMPAIGN_IMPORT_SHEET_ID = "campaign/dynamic_campaign/sheet_id";
    private const DYNAMIC_CAMPAIGN_IMPORT_GRID_ID = "campaign/dynamic_campaign/grid_id";
    private const DYNAMIC_CAMPAIGN_CREATE_COUPON = "campaign/dynamic_campaign/create_coupon";

    private const DYNAMIC_CAMPAIGN_LAST_IMPORT_TIME = "ca/productify/dynamic_campaign/last_import_time";
    private const CURRENT_CAMPAIGN_END_TIME = "ca/productify/dynamic_campaign/current_campaign_end_time";

    protected array $stores = [];

    public function __construct(
        protected SearchCriteriaBuilder    $searchCriteriaBuilder,
        protected BlockRepositoryInterface $blockRepository,
        protected ResourceConnection       $resourceConnection,
        protected ScopeConfigInterface     $_scopeConfig,
        protected CampaingLogger           $campaignLogger,
        protected TimezoneInterface        $timezone,
        protected Manager                  $cacheManager,
        protected RuleFactory              $ruleFactory,
    )
    {
    }

    /**
     * @return array
     */
    public function getActiveCampaigns(): array
    {
        $this->stores = $this->getStores();
        $activeCalloutCampaigns = $this->getActiveCalloutCampaign();
        $activeBanners = $this->getActiveBanner();
        return array_merge($activeCalloutCampaigns, $activeBanners);
    }

    /**
     * @return array
     */
    public function getActiveCalloutCampaign(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $campaignCalloutWidgetQuery = $connection->select()
            ->from(['wi' => 'widget_instance'], ['instance_id', 'title', 'store_ids', 'widget_parameters'])
            ->where(
                'wi.instance_type IN (?)',
                [
                    'CarpetAvenue\Widget\Block\Widget\CalloutWidget',
                    'CarpetAvenue\Widget\Block\Widget\CalloutProductWidget'
                ]
            )
            ->where("wi.widget_parameters IS NOT NULL AND wi.widget_parameters != ''")
            ->where("JSON_VALID(wi.widget_parameters) = 1")
            ->where("JSON_UNQUOTE(JSON_EXTRACT(wi.widget_parameters, '$.countdown')) IS NOT NULL")
            ->where(
                "STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(wi.widget_parameters, '$.countdown')), '%a, %d %b %Y %H:%i:%s') >= DATE_SUB(CONVERT_TZ(NOW(), 'UTC', 'Europe/Stockholm'), INTERVAL 65 MINUTE)"
            );
        $campaignCalloutWidgets = $connection->fetchAll($campaignCalloutWidgetQuery);
        $campaignCalloutWidgetsData = [];
        foreach ($campaignCalloutWidgets as $widget) {
            $data = [
                'id' => $widget['instance_id'],
                'title' => $widget['title'],
                'status' => 'Active',
            ];

            $storeIds = explode(',', $widget['store_ids']);
            if (count($storeIds) == 0) {
                $data['stores'] = 'All Store Views';
            } else {
                $storeNames = [];
                foreach ($storeIds as $storeId) {
                    if (isset($this->stores[$storeId])) {
                        $storeNames[] = $this->stores[$storeId];
                    }
                }
                $data['stores'] = implode(', ', $storeNames);
            }

            $data['start_date'] = '';
            $params = json_decode($widget['widget_parameters'], true);
            if (!empty($params['countdown'])) {
                $data['end_date'] = $params['countdown'];
                $data['end_datetime'] = date(
                    'Y-m-d H:i:s',
                    strtotime($params['countdown'])
                );
            } else {
                $data['end_date'] = '';
                $data['end_datetime'] = '';
            }

            if (!empty($widget['instance_type']) && $widget['instance_type'] == 'Evalent\\Callout\\Block\\Widget\\CalloutProductWidget') {
                $data['type'] = 'Product Callout';
            } else {
                $data['type'] = 'Header Callout';
            }
            $campaignCalloutWidgetsData[] = $data;
        }

        return $campaignCalloutWidgetsData;
    }

    public function getAllCalloutCampaign(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $campaignCalloutWidgetQuery = $connection->select()
            ->from(['wi' => 'widget_instance'], ['instance_id', 'instance_type', 'title', 'store_ids', 'widget_parameters'])
            ->joinLeft(
                ['wip' => 'widget_instance_page'],
                'wi.instance_id = wip.instance_id',
                ['page_id', 'layout_handle', 'page_group', 'block_reference','page_for']
            )
            ->where(
                'wi.instance_type IN (?)',
                [
                    'CarpetAvenue\Widget\Block\Widget\CalloutWidget',
                    'CarpetAvenue\Widget\Block\Widget\CalloutProductWidget'
                ]
            );

        $campaignCalloutWidgets = $connection->fetchAll($campaignCalloutWidgetQuery);
        $campaigns = [];
        foreach ($campaignCalloutWidgets as $widget) {
            $decoded = json_decode($widget['widget_parameters'], true);
            $widget['widget_parameters'] = is_array($decoded) ? $decoded : [];
            $campaigns[] = $widget;
        }
        return $campaigns;
    }

    /**
     * @return array
     */
    public function getActiveBanner(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $bannerStartDate = $connection->select()
            ->from('eav_attribute', ['attribute_id'])
            ->where('attribute_code = ?', 'date_start')
            ->where('entity_type_id = ?', 9);
        $bannerStartAttributeId = $connection->fetchOne($bannerStartDate);

        $bannerEndDate = $connection->select()
            ->from('eav_attribute', ['attribute_id'])
            ->where('attribute_code = ?', 'date_end')
            ->where('entity_type_id = ?', 9);
        $bannerEndAttributeId = $connection->fetchOne($bannerEndDate);

        $campaignBannerQuery = $connection->select()
            ->from(
                ['ebe' => 'evalent_banner_banner_entity'],
                ['entity_id', 'name']
            )
            ->join(
                ['start_date' => 'evalent_banner_banner_entity_varchar'],
                'ebe.entity_id = start_date.entity_id AND start_date.attribute_id = ' . $bannerStartAttributeId,
                ['start_date' => 'start_date.value', 'store_id' => 'start_date.store_id']
            )
            ->join(
                ['end_date' => 'evalent_banner_banner_entity_varchar'],
                'ebe.entity_id = end_date.entity_id AND end_date.attribute_id = ' . $bannerEndAttributeId . ' AND start_date.store_id = end_date.store_id',
                ['end_date' => 'end_date.value']
            )
            //->where('start_date.value <= NOW()')
            ->where("(end_date.value > NOW() OR end_date.value >= DATE_SUB(NOW(), INTERVAL 1 HOUR))");
        $campaignBanners = $connection->fetchAll($campaignBannerQuery);
        $campaignBannersData = [];
        foreach ($campaignBanners as $banner) {
            $data = [
                'id' => $banner['entity_id'],
                'title' => $banner['name'],
                'status' => 'Active',
                'start_date' => $this->timezone->formatDateTime(
                    $banner['start_date'],
                    IntlDateFormatter::MEDIUM,
                    IntlDateFormatter::MEDIUM
                ),
                'end_date' => $this->timezone->formatDateTime(
                    $banner['end_date'],
                    IntlDateFormatter::MEDIUM,
                    IntlDateFormatter::MEDIUM
                ),
                'end_datetime' => date(
                    'Y-m-d H:i:s',
                    strtotime($banner['end_date'])
                ),
                'type' => 'Banner',
            ];
            if (isset($this->stores[$banner['store_id']])) {
                $data['stores'] = $this->stores[$banner['store_id']];
            } else {
                $data['stores'] = 'Default Store View';
            }
            $campaignBannersData[] = $data;
        }
        return $campaignBannersData;
    }

    /**
     * @return array
     */
    private function getStores(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $storeQuery = $connection->select()
            ->from('store', ['store_id', 'name']);
        $stores = $connection->fetchAll($storeQuery);

        $storeList = [];
        foreach ($stores as $store) {
            if ($store['store_id'] == 0) {
                $store['name'] = 'Default Store View';
            }
            $storeList[$store['store_id']] = $store['name'];
        }
        $this->stores = $storeList;
        return $storeList;
    }

    /**
     * Check if dynamic campaign import is enabled
     * @return bool
     */
    public function isDynamicCampaignImportEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::DYNAMIC_CAMPAIGN_IMPORT_ENABLED) == 1;
    }

    /**
     * Get the Google Sheet ID
     * @return string|null
     */
    public function getGoogleSheetId(): ?string
    {
        $value = $this->_scopeConfig->getValue(self::DYNAMIC_CAMPAIGN_IMPORT_SHEET_ID);
        return !empty($value) ? trim($value) : null;
    }

    /**
     * Get the Google Sheet Grid ID
     * @return string|null
     */
    public function getGoogleSheetGridId(): ?string
    {
        $value = $this->_scopeConfig->getValue(self::DYNAMIC_CAMPAIGN_IMPORT_GRID_ID);
        return ($value !== null && $value !== '') ? trim($value) : null;
    }

    public function isDynamicCouponCreateEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::DYNAMIC_CAMPAIGN_CREATE_COUPON);
    }

    /**
     * @return array
     * @throws Exception
     */
    public function getSheetData(): array
    {
        if (!$this->isDynamicCampaignImportEnabled()) {
            throw new Exception('Dynamic campaign import is not enabled.');
        }

        $spreadsheetId = $this->getGoogleSheetId();
        $gid = $this->getGoogleSheetGridId();

        if (empty($spreadsheetId)) {
            throw new Exception('Google Sheet ID is not configured.');
        }

        if ($gid === null) {
            throw new Exception('Google Sheet Grid ID is not configured.');
        }

        $exportUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid={$gid}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $exportUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/csv, text/plain, */*',
        ]);

        $csv = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        unset($ch);

        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch sheet. HTTP: $httpCode");
        }

        $rows = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
            explode("\n", trim($csv))
        );
        $headers = array_shift($rows);

        $data = [];
        foreach ($rows as $row) {
            if (count($row) === count($headers)) {
                $data[] = array_combine($headers, $row);
            }
        }

        return $data;
    }

    /**
     * @throws Exception
     */
    public function importDynamicCampaign(): void
    {
        $googleSheetData = $this->getSheetData();
        $campaigns = $this->getAllCalloutCampaign();
        $allAvailableCaches = $this->cacheManager->getAvailableTypes();

        $endCampaigns = [];

        foreach ($campaigns as $campaign) {
            if (isset($campaign['widget_parameters']['countdown'])) {

                // Parse the countdown string as Swedish time
                $swedishTime = new DateTime(
                    $campaign['widget_parameters']['countdown'],
                    new DateTimeZone('Europe/Stockholm')
                );

                // Convert to UTC
                $swedishTime->setTimezone(new DateTimeZone('UTC'));
                $currentCampaignEndTimeUtc = $swedishTime->getTimestamp();

                // Get current UTC time
                $currentUtcTime = time();

                if ($currentUtcTime > $currentCampaignEndTimeUtc) {
                    // Campaign has ended — add to endCampaigns
                    $endCampaigns[] = $campaign;
                }
            }
        }

        //-- clear the cache if there are campaigns that have ended
        if (!empty($endCampaigns)) {
            $this->campaignLogger->info('The following campaigns have ended and will be removed: ' . implode(', ', array_column($endCampaigns, 'title')));
            //-- cache clear
            $this->clearBlockCache();
            $this->cacheManager->clean($allAvailableCaches);
        } else {
            $this->campaignLogger->info('No ended campaigns found. Skipping import.');
            return;
        }


        // Only consider campaigns that started at least 1 day ago
        $campaignRun = null;
        $latestStartDate = null;

        foreach ($googleSheetData as $campaignData) {
            if (
                isset($campaignData['Start Date']) &&
                isset($campaignData['End Date']) &&
                isset($campaignData['Promo Code']) &&
                isset($campaignData['Discount']) &&
                isset($campaignData['Color Code'])
            ) {
                $startDate = new DateTime(trim($campaignData['Start Date']), new DateTimeZone('Europe/Stockholm'));
                $startDate->setTimezone(new DateTimeZone('UTC'));

                $endDate = new DateTime(trim($campaignData['End Date']), new DateTimeZone('Europe/Stockholm'));
                $endDate->setTimezone(new DateTimeZone('UTC'));

                $currentDate = new DateTime('now', new DateTimeZone('UTC'));

                if ($currentDate >= $startDate && $currentDate <= $endDate) {
                    if ($latestStartDate === null || $startDate > $latestStartDate) {
                        $latestStartDate = $startDate;
                        $campaignRun = $campaignData;
                    }
                }
            }
        }

        if (empty($campaignRun)) {
            $this->campaignLogger->info('No active campaign found in Google Sheet for current date. Skipping import.');
            return;
        }
        $this->campaignLogger->info('Current campaign from Google Sheet: ' . $campaignRun['Promo Code'] . ' with discount ' . $campaignRun['Discount'] . '%. Campaign color code: ' . $campaignRun['Color Code']);

        foreach ($endCampaigns as $campaign) {
            try {
                $widgetParameters = $campaign['widget_parameters'] ?? [];
                if (empty($widgetParameters)) {
                    $this->campaignLogger->warning('Skipping campaign ID ' . $campaign['instance_id'] . ' (' . $campaign['title'] . '): widget_parameters is empty or null.');
                    continue;
                }
                // Parse End Date as Swedish time, keep it in Swedish timezone
                $endDate = new DateTime(trim($campaignRun['End Date']), new DateTimeZone('Europe/Stockholm'));

                // Format to required format: "Fri, 17 Apr 2026 08:56:37"
                $widgetParameters['countdown'] = $endDate->format('D, d M Y H:i:s');
                $widgetParameters['bgcolor'] = $campaignRun['Color Code'];

                // Normalize once at the top
                $message = $widgetParameters['message'] ?? '';

                // --- Promo code ---
                preg_match('/\b([A-Z]+[0-9]+)\b/', $message, $matches);
                $existingPromoCode = $matches[1] ?? null;
                if ($existingPromoCode && !empty($campaignRun['Promo Code'])) {
                    $message = str_replace($existingPromoCode, $campaignRun['Promo Code'], $message);
                }

                // --- Discount ---
                // Match discount like: 10%, -10%, 10％, 10 %
                preg_match('/(?<![A-Za-z])(\d+(?:\.\d+)?)\s*[%％]/u', $message, $discountMatches);
                $existingDiscount = $discountMatches[1] ?? null;

                if ($existingDiscount && isset($campaignRun['Discount']) && strlen((string)$campaignRun['Discount']) > 0) {
                    $discountValue = rtrim((string)$campaignRun['Discount'], '%％');
                    $message = preg_replace(
                        '/(?<![A-Za-z])' . preg_quote($existingDiscount, '/') . '\s*[%％]/u',
                        $discountValue . '%',
                        $message
                    ) ?? $message;
                }
                $widgetParameters['message'] = $message ?? '';

                if ($campaign['instance_type'] == "CarpetAvenue\Widget\Block\Widget\CalloutProductWidget") {
                    $widgetParameters['discount'] = $campaignRun['Discount'];
                }

                $this->campaignLogger->info('Updated widget parameters for campaign ID ' . $campaign['instance_id'] . ': ' . json_encode($widgetParameters));
                $connection = $this->resourceConnection->getConnection();
                $encodedParameters = json_encode($widgetParameters);

                if (empty($encodedParameters) || $encodedParameters === 'null' || $encodedParameters === '[]' || $encodedParameters === '{}') {
                    $this->campaignLogger->warning('Skipping DB update for campaign ID ' . $campaign['instance_id'] . ' (' . $campaign['title'] . '): widget_parameters would be empty or null after encoding.');
                    continue;
                }

                //1. Update widget_parameters (used by admin UI)
                $connection->update(
                    'widget_instance',
                    ['widget_parameters' => $encodedParameters],
                    ['instance_id = ?' => $campaign['instance_id']]
                );

                // 2. Update layout_update XML (what Magento actually renders from)
                //  without touching widget_instance_page, so placement is preserved
                $layoutUpdateIds = $connection->fetchCol(
                    $connection->select()
                        ->from(['wip' => 'widget_instance_page'], [])
                        ->join(['wipl' => 'widget_instance_page_layout'], 'wip.page_id = wipl.page_id', ['layout_update_id'])
                        ->where('wip.instance_id = ?', $campaign['instance_id'])
                );

                foreach ($layoutUpdateIds as $layoutUpdateId) {
                    $layoutXml = $connection->fetchOne(
                        $connection->select()
                            ->from('layout_update', ['xml'])
                            ->where('layout_update_id = ?', $layoutUpdateId)
                    );

                    if ($layoutXml) {
                        foreach ($widgetParameters as $paramName => $paramValue) {
                            // XML format: <action method="setData">
                            //   <argument name="name" xsi:type="string">PARAM_NAME</argument>
                            //   <argument name="value" xsi:type="string">PARAM_VALUE</argument>
                            // </action>
                            $layoutXml = preg_replace(
                                '/(<action method="setData">\s*<argument name="name" xsi:type="string">'
                                . preg_quote($paramName, '/')
                                . '<\/argument>\s*<argument name="value" xsi:type="string">)([^<]*)(<\/argument>\s*<\/action>)/',
                                '${1}' . htmlspecialchars((string)$paramValue, ENT_QUOTES) . '${3}',
                                $layoutXml
                            );
                        }
                        $connection->update(
                            'layout_update',
                            ['xml' => $layoutXml],
                            ['layout_update_id = ?' => $layoutUpdateId]
                        );
                    }
                }
            } catch (Exception $exception) {
                $this->campaignLogger->error('Error updating campaign ID ' . $campaign['instance_id'] . ' (' . $campaign['title'] . '): ' . $exception->getMessage());
            }
        }

        if ($this->isDynamicCouponCreateEnabled()) {
            $this->createOrUpdateCoupon($campaignRun);
        } else {
            $this->campaignLogger->info('Dynamic coupon creation is disabled. Skipping coupon create/update.');
        }

        //-- cache clear after import
        $this->cacheManager->clean($allAvailableCaches);

        return;
    }

    private function createOrUpdateCoupon(array $campaignData): void
    {
        $couponCode = trim($campaignData['Promo Code'] ?? '');
        if (empty($couponCode)) {
            $this->campaignLogger->warning('Skipping coupon create/update: Promo Code is empty.');
            return;
        }

        try {
            $startDateRaw = trim($campaignData['Start Date'] ?? '');
            $endDateRaw   = trim($campaignData['ENDDATECOUPON'] ?? $campaignData['End Date'] ?? '');

            $startDate = !empty($startDateRaw)
                ? (new DateTime($startDateRaw, new DateTimeZone('Europe/Stockholm')))->format('Y-m-d')
                : null;

            $endDate = !empty($endDateRaw)
                ? (new DateTime($endDateRaw, new DateTimeZone('Europe/Stockholm')))->format('Y-m-d')
                : null;

            $discount = (float) rtrim(trim((string)($campaignData['Discount'] ?? '0')), '%％');

            // Check if coupon already exists
            $connection = $this->resourceConnection->getConnection();
            $existingRow = $connection->fetchRow(
                $connection->select()
                    ->from(['c' => 'salesrule_coupon'], ['rule_id', 'coupon_id'])
                    ->where('c.code = ?', $couponCode)
                    ->limit(1)
            );

            if ($existingRow) {
                $rule = $this->ruleFactory->create()->load($existingRow['rule_id']);
                $this->campaignLogger->info("Coupon '{$couponCode}' found (rule_id: {$existingRow['rule_id']}). Updating.");
            } else {
                $rule = $this->ruleFactory->create();
                $this->campaignLogger->info("Coupon '{$couponCode}' not found. Creating new rule.");
            }

            $conditionsSerialized = json_encode([
                'type'       => Combine::class,
                'aggregator' => 'all',
                'value'      => '1',
                'conditions' => [
                    [
                        'type'       => Combine::class,
                        'aggregator' => 'all',
                        'value'      => '1',
                        'conditions' => [],
                    ],
                ],
            ]);

            $actionsSerialized = json_encode([
                'type'       => CombineProductRule::class,
                'aggregator' => 'all',
                'value'      => '1',
                'conditions' => [],
            ]);

            $rule->addData([
                'name'                    => $couponCode,
                'is_active'               => 1,
                'website_ids'             => [1, 2, 3, 4, 5, 6],
                'customer_group_ids'      => [0, 1, 2, 3],
                'coupon_type'             => Rule::COUPON_TYPE_SPECIFIC,
                'coupon_code'             => $couponCode,
                'uses_per_coupon'         => 1000000,
                'uses_per_customer'       => 1000000,
                'from_date'               => $startDate,
                'to_date'                 => $endDate,
                'is_rss'                  => 1,
                'pos_enabled'             => 1,
                'simple_action'           => 'by_percent',
                'discount_amount'         => $discount,
                'stop_rules_processing'   => 1,
                'conditions_serialized'   => $conditionsSerialized,
                'actions_serialized'      => $actionsSerialized,
            ]);

            $rule->save();

            $this->campaignLogger->info("Coupon '{$couponCode}' saved successfully (rule_id: " . $rule->getId() . ").");
        } catch (Exception $e) {
            $this->campaignLogger->error("Error creating/updating coupon '{$couponCode}': " . $e->getMessage());
        }
    }

    private function clearBlockCache(): void
    {
        try {
            $searchCriteria = $this->searchCriteriaBuilder->addFilter('identifier', 'topmenu', 'eq')->create();
            foreach ($this->blockRepository->getList($searchCriteria)->getItems() as $cmsBlock) {
                $cmsBlock->setContent('');
                $this->blockRepository->save($cmsBlock);
            }
        } catch (Exception $exception) {
            $this->campaignLogger->error($exception->getMessage());
        }
    }


}
