<?php
declare(strict_types=1);

namespace IXPF\Syncer\Helper;

use DateTime;
use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\Syncer\Model\IxpfWebhookJobsFactory as IxpfWebhookJobsModelFactory;
use IXPF\Syncer\Model\ResourceModel\IxpfWebhookJobsFactory as IxpfWebhookJobsResourceFactory;
use IXPF\Syncer\Model\ResourceModel\IxpfWebhookJobs\CollectionFactory as IxpfWebhookJobsCollectionFactory;
use IXPF\Syncer\Services\Notification\SlackNotifier;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;
use RuntimeException;
use Throwable;
use Zend_Db_Expr;

class Webhook extends AbstractHelper
{
    public const XML_PATH_GENERAL_SHOP_OBJECT_ID = 'ixpfng/general/shop_object_id';
    public const XML_PATH_WEBHOOK_PRODUCT_ENABLE_JOBS = 'ixpfng/product/enable_webhook';
    public const XML_PATH_WEBHOOK_PRODUCT_URL = 'ixpfng/product/webhook_url';
    public const XML_PATH_WEBHOOK_PRODUCT_URL_SECRET = 'ixpfng/product/secret_code';

    private const CHUNK_SIZE = 100;
    private const DEFAULT_BATCH_SIZE = 50;
    private const DEFAULT_WORKERS = 4;
    private const MAX_ATTEMPTS = 30;
    private const MAX_DEADLOCK_RETRIES = 3;
    private const PROCESSING_TIMEOUT_MINUTES = 15;

    protected IxpfWebhookJobsModelFactory $ixpfWebhookJobsModelFactory;
    protected IxpfWebhookJobsResourceFactory $ixpfWebhookJobsResourceFactory;
    protected IxpfWebhookJobsCollectionFactory $ixpfWebhookJobsCollectionFactory;
    protected ResourceConnection $resourceConnection;
    protected WebhookLogger $webhookLogger;
    protected SlackNotifier $slackNotifier;

    public function __construct(
        Context                          $context,
        IxpfWebhookJobsModelFactory      $ixpfWebhookJobsFactory,
        IxpfWebhookJobsResourceFactory   $ixpfWebhookJobsResourceFactory,
        IxpfWebhookJobsCollectionFactory $ixpfWebhookJobsCollectionFactory,
        ResourceConnection               $resourceConnection,
        SlackNotifier                    $slackNotifier,
        WebhookLogger                    $webhookLogger
    )
    {
        parent::__construct($context);
        $this->ixpfWebhookJobsModelFactory = $ixpfWebhookJobsFactory;
        $this->ixpfWebhookJobsResourceFactory = $ixpfWebhookJobsResourceFactory;
        $this->ixpfWebhookJobsCollectionFactory = $ixpfWebhookJobsCollectionFactory;
        $this->resourceConnection = $resourceConnection;
        $this->webhookLogger = $webhookLogger;
        $this->slackNotifier = $slackNotifier;
    }

    /**
     * Upsert (or reset) webhook jobs for a list of object IDs.
     *
     * Ids are sorted so every concurrent upsert locks rows in the same order.
     * A deadlock / lock wait timeout is retried (the upsert is idempotent), but
     * only outside a caller transaction: a deadlock rolls back the whole outer
     * transaction, so retrying inside it is not possible.
     *
     * @param bool $throwOnFailure Throw when a chunk still fails after retries.
     *                             Mview callers set it so the changelog version
     *                             is not advanced and the ids are retried on the
     *                             next cron run instead of being lost.
     * @throws RuntimeException
     */
    public function updateOrCreateJob(string $type, array $objectIds = [], bool $throwOnFailure = false): static
    {
        if (!$objectIds) {
            return $this;
        }

        $conn     = $this->resourceConnection->getConnection();
        $table    = $conn->getTableName("ixpf_webhook_jobs");
        $objectIds = array_values(array_unique(array_map('intval', $objectIds)));
        sort($objectIds);
        $chunkSize = self::CHUNK_SIZE;
        foreach (array_chunk($objectIds, $chunkSize) as $chunk) {
            $now  = (new DateTime())->format('Y-m-d H:i:s');
            $rows = [];
            foreach ($chunk as $objectId) {
                $rows[] = [
                    'type'           => $type,
                    'object_id'      => $objectId,
                    'payload'        => null,
                    'trigger_status' => 'pending',
                    'total_trigger'  => 0,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            for ($attempt = 1; ; $attempt++) {
                try {
                    // Upsert: insert new rows; update these fields if row already exists
                    $conn->insertOnDuplicate(
                        $table,
                        $rows,
                        [
                            'payload',
                            'trigger_status',
                            'updated_at',
                        ]
                    );
                    break;
                } catch (DeadlockException|LockWaitException $e) {
                    if ($attempt < self::MAX_DEADLOCK_RETRIES && $conn->getTransactionLevel() === 0) {
                        usleep(100_000 * $attempt);
                        continue;
                    }
                    $this->failUpsert($e, $type, $chunk, $attempt, $throwOnFailure);
                    break;
                } catch (Throwable $e) {
                    $this->failUpsert($e, $type, $chunk, $attempt, $throwOnFailure);
                    break;
                }
            }
        }

        return $this;
    }

    /**
     * @param int[] $chunk
     * @throws RuntimeException
     */
    private function failUpsert(Throwable $e, string $type, array $chunk, int $attempts, bool $throw): void
    {
        $this->webhookLogger->writeExceptionDaily(
            $e,
            sprintf(
                'Webhook bulk upsert exception (type %s, %d ids, %d attempt(s)): %s',
                $type,
                count($chunk),
                $attempts,
                implode(',', $chunk)
            )
        );

        if ($throw) {
            throw new RuntimeException(
                sprintf('IXPF webhook job upsert failed for type "%s"', $type),
                0,
                $e
            );
        }
    }

    /**
     * Fire a webhook immediately, fire-and-forget.
     *
     * Sends the request and closes the socket without waiting for the HTTP
     * response, so the caller (e.g. an order-save observer) is never blocked
     * by the remote endpoint. No job row, no cron — instant dispatch.
     */
    public function sendNow(string $type, int $objectId): void
    {
        if (!$this->isWebhookEnabled()) {
            return;
        }

        $shopObjectId     = $this->scopeConfig->getValue(self::XML_PATH_GENERAL_SHOP_OBJECT_ID);
        $webHookUrl       = $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PRODUCT_URL);
        $webHookUrlSecret = $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PRODUCT_URL_SECRET);

        if (!$webHookUrl || !$webHookUrlSecret || !$shopObjectId) {
            $this->slackNotifier->alert('Webhook Configuration Missing', [[
                'title' => 'Details',
                'value' => 'Missing: ' . implode(', ', array_filter([
                        !$webHookUrl ? 'Webhook URL' : null,
                        !$webHookUrlSecret ? 'Webhook Secret' : null,
                        !$shopObjectId ? 'Shop Object ID' : null,
                    ])),
                'short' => false,
            ]]);
            return;
        }

        $payloadJson = json_encode([
            'shop_object_id' => $shopObjectId,
            'type'           => $type,
            'object_id'      => $objectId,
        ]);

        $this->fireAndForget($webHookUrl . '?token=' . $webHookUrlSecret, (string)$payloadJson);
    }

    /**
     * Send a raw POST and close the socket without reading the response.
     *
     * The request bytes are handed to the OS TCP buffer, then the connection
     * is closed — delivery happens without blocking on the endpoint's reply.
     */
    private function fireAndForget(string $url, string $body): void
    {
        try {
            $parts  = parse_url($url);
            $scheme = $parts['scheme'] ?? 'http';
            $host   = $parts['host'] ?? '';
            $port   = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $path   = ($parts['path'] ?? '/')
                . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $remote = $scheme === 'https' ? 'ssl://' . $host : $host;

            if ($host === '') {
                $this->webhookLogger->writeDaily('Webhook sendNow: bad URL ' . $url, 'error');
                return;
            }

            // 2s connect cap so a dead endpoint never hangs the order request.
            $fp = @fsockopen($remote, (int)$port, $errno, $errstr, 2);
            if (!$fp) {
                $this->webhookLogger->writeDaily(
                    "Webhook sendNow connect failed [$errno]: $errstr",
                    'error'
                );
                return;
            }

            $request  = "POST $path HTTP/1.1\r\n";
            $request .= "Host: $host\r\n";
            $request .= "Content-Type: application/json\r\n";
            $request .= 'Content-Length: ' . strlen($body) . "\r\n";
            $request .= "Connection: Close\r\n\r\n";
            $request .= $body;

            fwrite($fp, $request);
            fclose($fp);
        } catch (Throwable $e) {
            $this->webhookLogger->writeExceptionDaily($e, 'Webhook sendNow exception');
        }
    }

    /**
     * Run all pending jobs for $type.
     *
     * @param bool $parallel Use pcntl_fork()-based parallel dispatch.
     * @param int $workers Max concurrent child processes (parallel mode only).
     * @param int|null $limit Cap total jobs processed in this run (null = no cap).
     */
    public function run(
        string $type,
        bool   $parallel = false,
        int    $workers = self::DEFAULT_WORKERS,
        ?int   $limit = null
    ): void
    {
        if (!$this->isWebhookEnabled()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $pendingCount = (int)$connection->fetchOne(
            "SELECT COUNT(*) FROM `ixpf_webhook_jobs`
             WHERE trigger_status = 'pending' AND type = :type",
            ['type' => $type]
        );

        if ($limit !== null) {
            $pendingCount = min($pendingCount, $limit);
        }

        $batches = (int)ceil($pendingCount / self::DEFAULT_BATCH_SIZE);

        for ($i = 0; $i < $batches; $i++) {
            $this->runBatch($type, 'pending', self::DEFAULT_BATCH_SIZE, $parallel, $workers);
        }
    }

    /**
     * Claim one batch of jobs and dispatch webhooks — sequentially or in parallel.
     *
     * @param bool $parallel Fork child processes for concurrent HTTP calls.
     * @param int $workers Max concurrent children (parallel mode only).
     */
    public function runBatch(
        string $type,
        string $status = 'pending',
        int    $batchSize = self::DEFAULT_BATCH_SIZE,
        bool   $parallel = false,
        int    $workers = self::DEFAULT_WORKERS
    ): bool
    {
        if (!$this->isWebhookEnabled()) {
            return false;
        }

        $this->recoverStuckProcessingJobs($type);

        $rows = $this->claimBatch($type, $status, $batchSize);
        if (empty($rows)) {
            return false;
        }

        if ($parallel && function_exists('pcntl_fork')) {
            $this->dispatchParallel($rows, $workers);
        } else {
            $this->dispatchSequential($rows);
        }

        return true;
    }


    /**
     * Lock and mark a batch of pending rows as "processing".
     * Returns the claimed rows, or [] if nothing was available.
     *
     * @return array<int, array<string, mixed>>
     */
    private function claimBatch(string $type, string $status, int $batchSize): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $connection->getTableName('ixpf_webhook_jobs');
        $rows = [];

        try {
            $connection->beginTransaction();

            $select = $connection->select()
                ->from($table)
                ->where('trigger_status = ?', $status)
                ->where('type = ?', $type)
                ->order('updated_at DESC')
                ->limit($batchSize)
                ->forUpdate();

            $rows = $connection->fetchAll($select);

            if (empty($rows)) {
                $connection->rollBack();
                return [];
            }

            $connection->update(
                $table,
                ['trigger_status' => 'processing', 'updated_at' => new Zend_Db_Expr('NOW()')],
                ['entity_id IN (?)' => array_column($rows, 'entity_id')]
            );

            $connection->commit();
        } catch (Throwable $e) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
            }
            $this->webhookLogger->writeExceptionDaily($e, 'Webhook claim batch exception');
            return [];
        }

        return $rows;
    }


    /**
     * Call each webhook one-by-one in the current process.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function dispatchSequential(array $rows): void
    {
        foreach ($rows as $row) {
            $this->processOneJob($row);
        }
    }

    /**
     * Dispatch webhooks concurrently using child processes, capped at $workers
     * concurrent forks at any given time.
     *
     * Each child:
     *   1. Reopens its own DB connection (avoids shared-socket corruption).
     *   2. Calls the external webhook.
     *   3. Writes the result back to the DB.
     *   4. Exits cleanly.
     *
     * The parent waits for all children before returning.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function dispatchParallel(array $rows, int $workers): void
    {
        $active = 0;

        foreach ($rows as $row) {
            // ---- throttle: wait for a slot if at worker cap
            while ($active >= $workers) {
                $exited = pcntl_wait($status);
                if ($exited > 0) {
                    $active--;
                }
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed — fall back to processing in the parent
                $this->webhookLogger->writeDaily('pcntl_fork() failed, processing inline', 'warning');
                $this->processOneJob($row);
                continue;
            }

            if ($pid === 0) {
                // ---- CHILD process
                $this->closeAndReopenConnection();
                $this->processOneJob($row);
                exit(0);
            }

            // ---- PARENT: a child was forked
            $active++;
        }

        // Wait for all remaining children
        while ($active > 0) {
            $exited = pcntl_wait($status);
            if ($exited > 0) {
                $active--;
            }
        }
    }

    /**
     * Drop and reopen the Magento DB connection inside a child process.
     * Required so each fork has its own socket — sharing the parent's
     * connection causes query corruption and "MySQL has gone away" errors.
     */
    private function closeAndReopenConnection(): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $connection->closeConnection();
        } catch (Throwable $e) {
            $this->webhookLogger->writeExceptionDaily($e, 'Webhook child DB reconnect exception');
        }
    }

    /**
     * Call the external webhook for a single job row and persist the result.
     *
     * @param array<string, mixed> $row
     */
    private function processOneJob(array $row): void
    {
        try {
            $response = $this->callWebHook($row);

            $this->updateJob($row, is_array($response) ? $response : [
                'success' => false,
                'payload' => null,
                'response' => 'No Response',
            ], true);
        } catch (Throwable $e) {
            $this->webhookLogger->writeExceptionDaily($e, 'Webhook process job exception');
            $this->updateJob($row, [
                'success' => false,
                'payload' => null,
                'response' => 'Exception: ' . $e->getMessage(),
            ], true);
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>|false
     */
    public function callWebHook(array $job): false|array
    {
        $shopObjectId = $this->scopeConfig->getValue(self::XML_PATH_GENERAL_SHOP_OBJECT_ID);
        $webHookUrl = $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PRODUCT_URL);
        $webHookUrlSecret = $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PRODUCT_URL_SECRET);

        if (!$webHookUrl || !$webHookUrlSecret || !$shopObjectId) {
            $this->slackNotifier->alert('Webhook Configuration Missing', [[
                'title' => 'Details',
                'value' => 'Missing: ' . implode(', ', array_filter([
                        !$webHookUrl ? 'Webhook URL' : null,
                        !$webHookUrlSecret ? 'Webhook Secret' : null,
                        !$shopObjectId ? 'Shop Object ID' : null,
                    ])),
                'short' => false,
            ]]);
            return false;
        }

        $payloadJson = json_encode([
            'shop_object_id' => $shopObjectId,
            'type' => (string)$job['type'],
            'object_id' => (int)$job['object_id'],
        ]);

        $ch = curl_init($webHookUrl . '?token=' . $webHookUrlSecret);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        unset($ch);

        if ($errno || $httpCode < 200 || $httpCode >= 300) {
            $result = [
                'success' => false,
                'error' => $error,
                'errno' => $errno,
                'http_code' => $httpCode,
                'payload' => $payloadJson,
                'response' => $response,
            ];
            $this->webhookLogger->writeDaily('Webhook Error: ' . json_encode($result), 'error');
            $this->slackNotifier->alert("Webhook Error: HTTP $httpCode", [[
                'title' => 'Error Details',
                'value' => json_encode($result, JSON_PRETTY_PRINT),
                'short' => false,
            ]]);
            return $result;
        }

        $returnData = json_decode((string)$response, true);
        $success = is_array($returnData) && ($returnData['success'] ?? false) === true;

        return [
            'success' => $success,
            'http_code' => $httpCode,
            'payload' => $payloadJson,
            'response' => $response,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $webhookResponse
     * @param bool $onlyIfProcessing Skip the write when the row left "processing"
     *                               meanwhile — an upsert reset it to "pending" for a
     *                               newer change, which must not be marked done.
     */
    public function updateJob(array $job, array $webhookResponse, bool $onlyIfProcessing = false): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $connection->getTableName('ixpf_webhook_jobs');

        $attempts = ((int)($job['total_trigger'] ?? 0)) + 1;
        $success = (bool)($webhookResponse['success'] ?? false);
        $status = $success ? 'success' : 'failed';

        try {
            $connection->update(
                $table,
                [
                    'payload' => $webhookResponse['payload'] ?? null,
                    'trigger_status' => $status,
                    'total_trigger' => $attempts,
                    'updated_at' => new Zend_Db_Expr('NOW()'),
                    'last_response' => json_encode($webhookResponse),
                ],
                $onlyIfProcessing
                    ? ['entity_id = ?' => (int)$job['entity_id'], 'trigger_status = ?' => 'processing']
                    : ['entity_id = ?' => (int)$job['entity_id']]
            );
        } catch (Throwable $e) {
            $this->webhookLogger->writeExceptionDaily($e, 'Webhook update job exception');
        }
    }

    /**
     * Reset jobs stuck in "processing" for longer than PROCESSING_TIMEOUT_MINUTES.
     */
    private function recoverStuckProcessingJobs(string $type): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $connection->getTableName('ixpf_webhook_jobs');

        for ($attempt = 0; $attempt < self::MAX_DEADLOCK_RETRIES; $attempt++) {
            try {
                $connection->beginTransaction();

                $select = $connection->select()
                    ->from($table, ['entity_id'])
                    ->where('type = ?', $type)
                    ->where('trigger_status = ?', 'processing')
                    ->where(sprintf(
                        'updated_at < (NOW() - INTERVAL %d MINUTE)',
                        self::PROCESSING_TIMEOUT_MINUTES
                    ))
                    ->forUpdate();

                $ids = $connection->fetchCol($select);

                if (!empty($ids)) {
                    $connection->update(
                        $table,
                        ['trigger_status' => 'pending', 'updated_at' => new Zend_Db_Expr('NOW()')],
                        ['entity_id IN (?)' => $ids]
                    );
                }

                $connection->commit();
                return;

            } catch (Throwable $e) {
                $connection->rollBack();

                $isDeadlock = str_contains($e->getMessage(), '1213')
                    || str_contains($e->getMessage(), 'Deadlock');

                if ($isDeadlock && $attempt < self::MAX_DEADLOCK_RETRIES - 1) {
                    usleep(100_000 * ($attempt + 1));
                    continue;
                }

                $this->webhookLogger->writeExceptionDaily(
                    $e,
                    'Webhook recover stuck processing exception'
                );
                return;
            }
        }
    }

    private function isWebhookEnabled(): bool
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_PRODUCT_ENABLE_JOBS) === 1;
    }
}
