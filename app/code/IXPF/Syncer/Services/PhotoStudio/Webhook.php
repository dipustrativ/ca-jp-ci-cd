<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\PhotoStudio;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;
use IXPF\Syncer\Services\Notification\SlackNotifier;
use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

class Webhook
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------
    private const DISPATCH_BATCH_SIZE = 25;
    private const HTTP_TIMEOUT        = 60;
    private const DEFAULT_WORKERS     = 1;
    private const MAX_WORKERS         = 10;


    public function __construct(
        private readonly Config             $config,
        private readonly PhotoStudioLogger  $logger,
        private readonly ResourceConnection $resourceConnection,
        private readonly SlackNotifier      $slackNotifier,
    ) {}


    /**
     * Dispatch all ready_for_webhook jobs to the configured webhook endpoint.
     *
     * @param int $workers  Number of parallel worker processes (1 = sequential).
     * @param int $limit    Total jobs to fetch for this run.
     *
     */
    public function dispatch(int $workers = self::DEFAULT_WORKERS, int $limit = self::DISPATCH_BATCH_SIZE): bool
    {
        if (!$this->isConfigValid()) {
            $this->logger->writeDaily('Webhook dispatch skipped: configuration incomplete or disabled.');
            return false;
        }

        $jobs = $this->fetchJobs($limit);
        if (empty($jobs)) {
            return true;
        }

        $workers = max(1, min($workers, self::MAX_WORKERS));

        if ($workers === 1 || !$this->isPcntlAvailable()) {
            $this->processJobs($jobs);
            return true;
        }

        $this->dispatchParallel($jobs, $workers);
        return true;
    }

    private function isConfigValid(): bool
    {
        return $this->config->isProductWebhookEnabled()
            && !empty($this->config->getShopObjectId())
            && !empty($this->config->getProductWebhookUrl())
            && !empty($this->config->getProductWebhookSecret());
    }


    /**
     * Splits $jobs into $workers slices and forks one child per slice.
     * Parent waits for all children before returning.
     */
    private function dispatchParallel(array $jobs, int $workers): void
    {
        $slices = $this->partition($jobs, $workers);
        $pids   = [];

        foreach ($slices as $index => $slice) {
            if (empty($slice)) {
                continue;
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->logger->writeDaily("pcntl_fork failed for worker #{$index}; running slice sequentially.");
                $this->processJobs($slice);
                continue;
            }

            if ($pid === 0) {
                // ── child process
                // pcntl_fork() duplicates the parent's entire memory space,
                // including the open MySQL socket (file descriptor). At this
                // point both the parent and this child share the exact same
                // physical TCP connection to MySQL. If both send queries
                // simultaneously on that shared socket, packets interleave
                // and MySQL receives corrupt data, causing deadlocks or
                // silent query failures.
                //
                // closeConnection() tells Magento's connection object to drop
                // the inherited socket immediately. The connection object itself
                // stays in memory; Magento will transparently open a fresh,
                // child-exclusive socket on the next DB call inside
                // processJobs(). This gives every child its own isolated
                // MySQL connection and makes concurrent writes safe.
                $this->resourceConnection->getConnection()->closeConnection();
                $this->processJobs($slice);
                exit(0);
            }

            // ── parent: record child PID
            $pids[] = $pid;
            $this->logger->writeDaily(
                sprintf('Forked worker #%d (PID %d) for %d job(s).', $index, $pid, count($slice))
            );
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exit = pcntl_wexitstatus($status);
            if ($exit !== 0) {
                $this->logger->writeDaily("Worker PID {$pid} exited with status {$exit}.");
            }
        }
    }


    private function processJobs(array $jobs): void
    {
        foreach ($jobs as $job) {
            $this->processJob($job);
        }
    }

    private function processJob(array $job): void
    {
        $conn = $this->resourceConnection->getConnection();

        try {
            $conn->beginTransaction();

            $payload  = $this->buildPayload($job);
            $response = $this->sendRequest($payload);

            $ixpfResponse = [
                'status_code'     => $response['status_code'],
                'response_body'   => $response['body'],
                'webhook_payload' => $payload,
            ];

            if ($this->isSuccessResponse($response)) {
                $this->updateStatus((int) $job['entity_id'], IxpfPhotoStudioQueue::STATUS_COMPLETED);
                $this->logger->writeDaily("Webhook dispatched successfully — job #{$job['entity_id']}");
            } else {
                $this->updateStatus((int) $job['entity_id'], IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED);
                $this->logger->writeDaily("Webhook dispatch failed — job #{$job['entity_id']} HTTP {$response['status_code']}");
            }

            $this->updateIxpfResponse((int) $job['entity_id'], $ixpfResponse);
            $conn->commit();

        } catch (Exception $e) {
            $conn->rollBack();
            $this->updateStatus((int) $job['entity_id'], IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED);
            $this->logger->writeDaily("Webhook dispatch exception — job #{$job['entity_id']}: {$e->getMessage()}");
            $this->alertSlack($job, $e->getMessage());
        }
    }

    /**
     * @throws GuzzleException
     */
    private function sendRequest(array $payload): array
    {
        $url      = $this->config->getProductWebhookUrl() . '?token=' . $this->config->getProductWebhookSecret();
        $client   = new Client();
        $response = $client->post($url, [
            'body'    => json_encode($payload),
            'timeout' => self::HTTP_TIMEOUT,
        ]);

        return [
            'status_code' => $response->getStatusCode(),
            'body'        => json_decode($response->getBody()->getContents(), true),
        ];
    }

    private function isSuccessResponse(array $response): bool
    {
        return $response['status_code'] >= 200
            && $response['status_code'] < 300
            && isset($response['body']['success'])
            && $response['body']['success'] === true;
    }

    private function fetchJobs(int $limit): array
    {
        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);

        $select = $conn->select()
            ->from(['psq' => $table])
            ->where('psq.status = ?', IxpfPhotoStudioQueue::STATUS_READY_FOR_WEBHOOK)
            ->order('psq.created_at ASC')
            ->limit($limit)
            ->forUpdate();

        return $conn->fetchAll($select) ?: [];
    }

    private function updateStatus(int $entityId, string $status): void
    {
        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);

        $conn->update(
            $table,
            ['status' => $status, 'updated_at' => new Zend_Db_Expr('NOW()')],
            ['entity_id = ?' => $entityId]
        );
    }

    private function updateIxpfResponse(int $entityId, array $data): void
    {
        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);

        $conn->update(
            $table,
            ['ixpf_response' => json_encode($data), 'updated_at' => new Zend_Db_Expr('NOW()')],
            ['entity_id = ?' => $entityId]
        );
    }

    private function buildPayload(array $job): array
    {
        $raw = $job['response_result'] ?? null;

        return [
            'shop_object_id' => $this->config->getShopObjectId(),
            'type'           => $job['type'],
            'object_id'      => (int) $job['object_id'],
            'response'       => is_string($raw) ? json_decode($raw, true) : null,
        ];
    }

    private function alertSlack(array $job, string $errorMessage): void
    {
        $this->slackNotifier->alert('PhotoStudio Webhook Dispatch Failed', [
            [
                'title' => 'Error Details',
                'value' => json_encode([
                    'job_id'     => $job['entity_id'] ?? null,
                    'product_id' => $job['object_id']  ?? null,
                    'type'       => $job['type']        ?? null,
                    'error'      => $errorMessage,
                ], JSON_PRETTY_PRINT),
                'short' => false,
            ],
        ]);
    }

    private function partition(array $items, int $n): array
    {
        $size = (int) ceil(count($items) / $n);
        return $size > 0 ? array_chunk($items, $size) : [$items];
    }

    private function isPcntlAvailable(): bool
    {
        return function_exists('pcntl_fork');
    }
}