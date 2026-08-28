<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Orders;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Model\Order\Pdf\Creditmemo as CreditmemoPdf;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;

/**
 * Returns the credit memo PDF — same document as the admin "Print" button on a credit memo.
 * Accepts creditmemo_id (single credit memo) or order_id (all credit memos of the order, combined).
 */
class CreditmemoPrint extends AbstractController implements
    HttpGetActionInterface,
    HttpPostActionInterface,
    CsrfAwareActionInterface
{
    public function __construct(
        Context                                          $context,
        TokenValidator                                   $tokenValidator,
        JsonFactory                                      $resultFactory,
        protected readonly Config                             $config,
        protected readonly CreditmemoRepositoryInterface $creditmemoRepository,
        protected readonly CreditmemoCollectionFactory   $creditmemoCollectionFactory,
        protected readonly CreditmemoPdf                 $creditmemoPdf,
        protected readonly OrderActionLogger             $orderLogger,
        protected readonly ActivityLogger                $activityLogger,
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            // accept params from query string or JSON body
            $creditmemoId = (int) $this->getRequest()->getParam('creditmemo_id');
            $orderId      = (int) $this->getRequest()->getParam('order_id');

            if (!$creditmemoId && !$orderId) {
                $payload = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
                $creditmemoId = (int) ($payload['creditmemo_id'] ?? 0);
                $orderId      = (int) ($payload['order_id'] ?? 0);
            }

            if (!$creditmemoId && !$orderId) {
                throw new Exception('creditmemo_id or order_id is required');
            }

            if (!$this->config->isCreditMemoUpdateEnabled()) {
                throw new Exception('Credit memo is disabled in configuration');
            }

            $this->orderLogger->writeDaily(
                '[creditmemo_print] Received print request — '
                . ($creditmemoId ? "Credit Memo ID: $creditmemoId" : "Order ID: $orderId")
            );

            [$creditmemos, $fileName] = $creditmemoId
                ? $this->loadByCreditmemo($creditmemoId)
                : $this->loadByOrder($orderId);

            $pdf = $this->creditmemoPdf->getPdf($creditmemos);
            $pdfContent = $pdf->render();

            $this->orderLogger->writeDaily(
                "[creditmemo_print] PDF generated — file: $fileName, count: " . count($creditmemos)
            );

            $this->activityLogger->success(
                'creditmemo_print',
                'creditmemo',
                $creditmemoId ?: $orderId,
                "PDF generated — file: $fileName, count: " . count($creditmemos)
            );

            return $this->jsonResponse([
                'success'       => true,
                'creditmemo_id' => $creditmemoId ?: null,
                'order_id'      => $orderId ?: null,
                'count'         => count($creditmemos),
                'file_name'     => $fileName,
                'file_content'  => base64_encode($pdfContent),
                'mime_type'     => 'application/pdf',
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[creditmemo_print] Error printing credit memo: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error(
                'creditmemo_print',
                'creditmemo',
                ($creditmemoId ?? null) ?: ($orderId ?? null),
                $exception->getMessage()
            );
            return $this->jsonResponse([
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * @return array{0: array, 1: string}
     */
    private function loadByCreditmemo(int $creditmemoId): array
    {
        $creditmemo = $this->creditmemoRepository->get($creditmemoId);
        return [[$creditmemo], "creditmemo_{$creditmemo->getIncrementId()}.pdf"];
    }

    /**
     * @return array{0: array, 1: string}
     */
    private function loadByOrder(int $orderId): array
    {
        $collection = $this->creditmemoCollectionFactory->create()
            ->addFieldToFilter('order_id', $orderId)
            ->setOrder('entity_id', 'ASC');

        $creditmemos = array_values($collection->getItems());

        if (!$creditmemos) {
            throw new Exception("No credit memos found for order #$orderId");
        }

        return [$creditmemos, "creditmemos_order_{$orderId}.pdf"];
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
