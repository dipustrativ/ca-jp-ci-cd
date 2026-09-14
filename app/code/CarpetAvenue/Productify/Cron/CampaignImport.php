<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @author    Strativ Magento Team
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Cron;

use CarpetAvenue\Productify\Helper\CampaignHelper;
use CarpetAvenue\Productify\Helper\CampaingLogger;
use Exception;

/**
 * Hourly counterpart of `bin/magento productify:campaign:import`.
 *
 * A campaign ends by clock, not by anybody saving a widget, so the Google Sheet
 * is re-read on a timer and the callout widgets swapped over when the running
 * campaign's countdown has passed.
 */
class CampaignImport
{
    /**
     * @param CampaignHelper $campaignHelper
     * @param CampaingLogger $campaignLogger
     */
    public function __construct(
        private readonly CampaignHelper $campaignHelper,
        private readonly CampaingLogger $campaignLogger
    ) {
    }

    /**
     * Re-import the dynamic campaign when the running one has expired.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->campaignHelper->isDynamicCampaignImportEnabled()) {
            return;
        }

        if (empty($this->campaignHelper->getGoogleSheetId())) {
            $this->campaignLogger->error('Campaign import cron: Google Sheet ID is not configured.');

            return;
        }

        if ($this->campaignHelper->getGoogleSheetGridId() === null) {
            $this->campaignLogger->error('Campaign import cron: Google Sheet Grid ID is not configured.');

            return;
        }

        $startTime = microtime(true);

        try {
            $this->campaignHelper->importDynamicCampaign();
        } catch (Exception $e) {
            $this->campaignLogger->error('Campaign import cron failed: ' . $e->getMessage());

            return;
        }

        $this->campaignLogger->info(sprintf(
            'Campaign import cron completed in %s seconds.',
            round(microtime(true) - $startTime, 2)
        ));
    }
}
