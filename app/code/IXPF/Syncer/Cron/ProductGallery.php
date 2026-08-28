<?php
declare(strict_types=1);

namespace IXPF\Syncer\Cron;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Helper\PhotoStudio;
use IXPF\Syncer\Helper\Helpers as ProductHelper;

class ProductGallery
{

    public function __construct(
        protected readonly PhotoStudioLogger $logger,
        protected PhotoStudio $photoStudioHelper,
        protected readonly ProductHelper $productHelper
    )
    {
    }

    /**
     * @return void
     * @throws GuzzleException
     */
    public function execute(): void
    {
        try {
            if (!$this->productHelper->getEnablePhotoStudioPreviousFunctionality()) {
                return;
            }

            //-- process photo studio product gallery queue
            $this->photoStudioHelper->runQueue();

            //-- mark processed gallery items as ready for webhook
            $this->photoStudioHelper->webhookDispatch();

        } catch (Exception $e) {
            $this->logger->writeDaily('Error in ProductGallery cron: ' . $e->getMessage());
        }

    }
}