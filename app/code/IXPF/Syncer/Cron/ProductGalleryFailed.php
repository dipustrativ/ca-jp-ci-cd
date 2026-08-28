<?php

namespace IXPF\Syncer\Cron;

use GuzzleHttp\Exception\GuzzleException;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Helper\PhotoStudio;
use Exception;
use IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;
use IXPF\Syncer\Services\PhotoStudio\WebhookRetry;

class ProductGalleryFailed
{
    public function __construct(
        protected readonly PhotoStudioLogger $logger,
        protected PhotoStudio $photoStudioHelper,
        protected WebhookRetry $webhookRetry
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
            //-- process photo studio product gallery queue
            $this->photoStudioHelper->runQueue(IxpfPhotoStudioQueue::STATUS_MAGENTO_FAILED);

            //-- mark processed gallery items as ready for webhook
            $this->webhookRetry->retryFailedWebhooks();
            //$this->photoStudioHelper->webhookDispatch(IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED);

        } catch (Exception $e) {
            $this->logger->writeDaily('Error in ProductGallery cron: ' . $e->getMessage());
        }

    }

}