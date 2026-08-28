<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\PhotoStudio;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Helper\PhotoStudio as PhotoStudioHelper;
use IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;
use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

class WebhookRetry
{
    private const MAX_RETRY_ATTEMPTS = 2;

    public function __construct(
        protected readonly PhotoStudioHelper  $photoStudioHelper,
        protected readonly PhotoStudioLogger  $logger,
        protected readonly ResourceConnection $resourceConnection,
        protected readonly Config             $configHelper,
    ) {}

    /**
     * Retry webhook_failed jobs up to MAX_RETRY_ATTEMPTS
     *
     * @return void
     * @throws GuzzleException
     */
    public function retryFailedWebhooks(): void
    {
        try {
            $jobs = $this->getRetryableJobs();

            if (empty($jobs)) {
                $this->logger->writeDaily('WebhookRetry: No retryable webhook_failed jobs found.');
                return;
            }

            foreach ($jobs as $job) {
                $entityId   = (int) $job['entity_id'];
                $retryCount = (int) $job['retry_count'];

                if ($retryCount >= self::MAX_RETRY_ATTEMPTS) {
                    $this->logger->writeDaily(
                        "WebhookRetry: Job ID {$entityId} has reached max retry attempts (" . self::MAX_RETRY_ATTEMPTS . "). Skipping."
                    );
                    continue;
                }

                $this->logger->writeDaily(
                    "WebhookRetry: Dispatching job ID {$entityId} (attempt " . ($retryCount + 1) . "/" . self::MAX_RETRY_ATTEMPTS . ")"
                );

                $this->incrementRetryCount($entityId);
                $this->dispatchJob($job);
            }

        } catch (Exception $e) {
            $this->logger->writeDaily('WebhookRetry: Error during retry process: ' . $e->getMessage());
        }
    }

    /**
     * Dispatch a single job to the webhook
     *
     * @param array $job
     * @return void
     * @throws GuzzleException
     */
    private function dispatchJob(array $job): void
    {
        $webhookEnabled   = $this->configHelper->isProductWebhookEnabled();
        $shopObjectId     = $this->configHelper->getShopObjectId();
        $webHookUrl       = $this->configHelper->getProductWebhookUrl();
        $webHookUrlSecret = $this->configHelper->getProductWebhookSecret();
        $entityId         = (int) $job['entity_id'];

        if (!$webhookEnabled || !$webHookUrl || !$webHookUrlSecret || !$shopObjectId) {
            $this->logger->writeDaily("WebhookRetry: Config incomplete or disabled for job ID {$entityId}. Skipping.");
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        try {
            $connection->beginTransaction();

            $client = new Client();
            $data   = [
                'shop_object_id' => $shopObjectId,
                'type'           => $job['type'],
                'object_id'      => (int) $job['object_id'],
                'response'       => json_decode($job['response_result'], true),
            ];

            $url          = $webHookUrl . "?token=" . $webHookUrlSecret;
            $response     = $client->post($url, ['body' => json_encode($data), 'timeout' => 60]);
            $statusCode   = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);
            $ixpfResponse = [
                'status_code'     => $statusCode,
                'response_body'   => $responseData,
                'webhook_payload' => $data,
            ];

            if ($statusCode >= 200 && $statusCode < 300 && isset($responseData['success']) && $responseData['success']) {
                $this->photoStudioHelper->updateStatus($entityId, IxpfPhotoStudioQueue::STATUS_COMPLETED);
                $this->logger->writeDaily("WebhookRetry: Job ID {$entityId} completed successfully.");
            } else {
                $this->photoStudioHelper->updateStatus($entityId, IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED);
                $this->logger->writeDaily("WebhookRetry: Job ID {$entityId} failed with status code {$statusCode}.");
            }

            $this->photoStudioHelper->updateIXPFResponse($entityId, $ixpfResponse);
            $connection->commit();

        } catch (Exception $e) {
            $connection->rollBack();
            $this->photoStudioHelper->updateStatus($entityId, IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED);
            $this->logger->writeDaily("WebhookRetry: Exception dispatching job ID {$entityId}: " . $e->getMessage());
        }
    }

    /**
     * Get webhook_failed jobs still under the max retry limit
     *
     * @return array
     */
    private function getRetryableJobs(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName  = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);

            $select = $connection->select()
                ->from(['psq' => $tableName])
                ->where('psq.status = ?', IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED)
                ->where('psq.retry_count < ?', self::MAX_RETRY_ATTEMPTS)
                ->order('psq.updated_at ASC')
                ->limit(25)
                ->forUpdate();

            return $connection->fetchAll($select) ?: [];
        } catch (Exception $e) {
            $this->logger->writeDaily('WebhookRetry: Error fetching retryable jobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Increment retry_count for a given job
     *
     * @param int $entityId
     * @return void
     */
    private function incrementRetryCount(int $entityId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName  = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);

        $connection->update(
            $tableName,
            [
                'retry_count' => new Zend_Db_Expr('retry_count + 1'),
                'updated_at'  => new Zend_Db_Expr('NOW()'),
            ],
            ['entity_id = ?' => $entityId]
        );
    }
}