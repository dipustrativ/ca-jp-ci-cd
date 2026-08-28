<?php

declare(strict_types=1);

namespace IXPF\Syncer\Cron;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use IXPF\Syncer\Helper\Helpers;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Services\PhotoStudio\Webhook;

class PhotoGalleryWebhookTrigger
{
    public function __construct(
        private readonly Webhook           $webhook,
        private readonly Helpers           $config,
        private readonly PhotoStudioLogger $logger,
    )
    {
    }

    public function execute(): void
    {
        try {
            $workers = $this->config->isWebhookParallelProcessingEnabled()
                ? $this->config->getWebhookParallelWorkers()
                : 1;

            $this->webhook->dispatch(
                workers: $workers,
                limit: $this->config->getWebhookDispatchLimit(),
            );
        } catch (Exception $e) {
            $this->logger->writeDaily('Error in PhotoGalleryWebhookTrigger cron: ' . $e->getMessage());
        }
    }
}