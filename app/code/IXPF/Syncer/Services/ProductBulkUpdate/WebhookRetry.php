<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\ProductBulkUpdate;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\BulkUpdateRevertLogger;
use Magento\Framework\App\ResourceConnection;

class WebhookRetry
{
    private const MAX_RETRY_ATTEMPTS = 3;

    public function __construct(
        protected ResourceConnection     $resourceConnection,
        protected Config                 $config,
        protected BulkUpdateRevertLogger $logger,
    )
    {
    }

    /**
     * Claim the next WEBHOOK_FAILED job — same atomic pattern as claimNextPendingJob().
     */
    public function claimNextFailedWebhookJob(): array|false
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('ixpf_product_bulk_update_queue');

        $connection->beginTransaction();
        try {
            $select = $connection->select()
                ->from($tableName)
                ->where('status = ?', QueueStatus::WEBHOOK_FAILED)
                ->where('attempts < ?', self::MAX_RETRY_ATTEMPTS)
                ->order('created_at ASC')
                ->limit(1)
                ->forUpdate();

            $row = $connection->fetchRow($select);
            if (!$row) {
                $connection->commit();
                return false;
            }

            // Mark as processing so no other worker picks it up
            $connection->update(
                $tableName,
                ['status' => QueueStatus::PROCESSING],
                ['entity_id = ?' => $row['entity_id']]
            );

            $connection->commit();
            return $row;

        } catch (Exception $e) {
            $connection->rollBack();
            $this->logger->writeDaily("Failed to claim webhook retry job: " . $e->getMessage(), 'error');
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Retry the webhook for one WEBHOOK_FAILED queue row.
     * Does NOT touch product data or queue status beyond webhook concerns.
     *
     * @throws Exception
     */
    public function process(): void
    {
        $row = $this->claimNextFailedWebhookJob();
        if (!$row) {
            return;
        }

        $queueId = (int)$row['entity_id'];
        $ixpfBatchId = (string)($row['ixpf_batch_id'] ?? '');
        $resultData = json_decode($row['response_result'] ?? '{}', true) ?? [];
        $retryCount = (int)($row['attempts'] ?? 0) + 1;

        $webhookOk = $this->sendWebhook($ixpfBatchId, $queueId, $resultData);
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName('ixpf_product_bulk_update_queue'),
            [
                'attempts' => $retryCount,
            ],
            ['entity_id = ?' => $queueId]
        );
    }

    /**
     * Pure HTTP send — mirrors sendResultToIXPF() but returns bool, no queue side-effects.
     */
    private function sendWebhook(string $ixpfBatchId, int $queueId, array $resultData): bool
    {
        $webhookUrl = $this->config->getProductWebhookUrl();
        $webhookSecret = $this->config->getProductWebhookSecret();
        $ixpfShopId = $this->config->getShopObjectId();
        $endpoint = $webhookUrl . '?token=' . $webhookSecret;
        $status = QueueStatus::COMPLETED;

        try {
            $client = new \GuzzleHttp\Client(['connect_timeout' => 3, 'timeout' => 10]);
            $response = $client->post($endpoint, [
                'json' => [
                    'shop_object_id' => $ixpfShopId,
                    'type' => 'revert_bulk_update',
                    'batch_id' => $ixpfBatchId,
                    'result' => $resultData,
                ],
            ]);

            $responseBody = (string)$response->getBody();
            $httpStatus = $response->getStatusCode();
            $decodedResponse = json_decode($responseBody, true);
            if (isset($decodedResponse['success']) && $decodedResponse['success'] === false) {
                $status = QueueStatus::WEBHOOK_FAILED;
            }

            $this->saveIxpfResponse($queueId, $responseBody, $httpStatus >= 400
                ? "HTTP {$httpStatus} from IXPF endpoint"
                : null,
                $status
            );

            return $httpStatus < 400;

        } catch (Exception $e) {
            $this->logger->writeDaily("Webhook retry failed for queue $queueId: " . $e->getMessage(), 'error');
            $status = QueueStatus::WEBHOOK_FAILED;
            $this->saveIxpfResponse($queueId, null, $e->getMessage(), $status);
            return false;
        }
    }

    private function saveIxpfResponse(int $queueId, ?string $response, ?string $error, string $status): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName('ixpf_product_bulk_update_queue'),
            ['ixpf_response' => $response, 'error_message' => $error, 'status' => $status],
            ['entity_id = ?' => $queueId]
        );
    }


}