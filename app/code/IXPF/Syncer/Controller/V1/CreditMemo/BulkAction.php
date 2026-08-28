<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\CreditMemo;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\CreditMemo\BulkPrint;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Single endpoint for bulk credit memo actions over selected credit memo ids.
 *
 * POST /ixpfsyncer/V1/CreditMemo/BulkAction
 *
 * Body:
 *   {
 *     "action": "pdf_creditmemos",
 *     "creditmemo_ids": [1, 2, 3]
 *   }
 *
 * Mirrors the admin credit memo grid mass action "PDF Credit Memos" — returns
 * one combined PDF (base64) across all selected credit memos.
 */
class BulkAction extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const ACTION_PDF_CREDITMEMOS = 'pdf_creditmemos';

    private const ACTIONS = [
        self::ACTION_PDF_CREDITMEMOS,
    ];

    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly Config $config,
        protected readonly BulkPrint $bulkPrint,
        protected readonly OrderActionLogger $orderLogger,
        protected readonly ActivityLogger $activityLogger,
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        $action = null;
        try {
            $this->tokenValidator();

            if (!$this->config->isCreditMemoUpdateEnabled()) {
                throw new Exception('Credit memo action is disabled in configuration');
            }

            $payload       = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
            $action        = strtolower(trim((string) ($this->getRequest()->getParam('action') ?? $payload['action'] ?? '')));
            $creditmemoIds = $this->resolveCreditmemoIds($payload);

            if ($action === '') {
                throw new Exception('action is required');
            }
            if (!in_array($action, self::ACTIONS, true)) {
                throw new Exception(
                    "Invalid action '$action'. Allowed: " . implode(', ', self::ACTIONS)
                );
            }
            if (!$creditmemoIds) {
                throw new Exception('creditmemo_ids is required (non-empty array of credit memo IDs)');
            }

            $this->orderLogger->writeDaily(
                "[creditmemo_bulk_action] action=$action creditmemo_ids=" . implode(',', $creditmemoIds)
            );

            $data = $this->bulkPrint->execute($creditmemoIds);

            $message = "[$action] file: {$data['file_name']}, docs: " . ($data['count'] ?? 0)
                . (!empty($data['missing']) ? ', missing: ' . implode(',', $data['missing']) : '');
            $this->orderLogger->writeDaily("[creditmemo_bulk_action] $message");
            $this->activityLogger->success("creditmemo_bulk_action_$action", 'creditmemo', implode(',', $creditmemoIds), $message);

            return $this->jsonResponse(['success' => true, 'action' => $action] + $data);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[creditmemo_bulk_action] Error: ' . $exception->getMessage(), 'error');
            $activityType = $action ? "creditmemo_bulk_action_$action" : 'creditmemo_bulk_action';
            $this->activityLogger->error($activityType, 'creditmemo', null, $exception->getMessage());
            return $this->jsonResponse(['success' => false, 'error' => $exception->getMessage()], 400);
        }
    }

    /**
     * Normalise + de-duplicate credit memo IDs from query param or JSON body.
     *
     * @return int[]
     */
    private function resolveCreditmemoIds(array $payload): array
    {
        $raw = $this->getRequest()->getParam('creditmemo_ids') ?? $payload['creditmemo_ids'] ?? [];
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
