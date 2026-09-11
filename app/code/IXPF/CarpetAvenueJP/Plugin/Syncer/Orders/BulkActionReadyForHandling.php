<?php
declare(strict_types=1);

namespace IXPF\CarpetAvenueJP\Plugin\Syncer\Orders;

use IXPF\CarpetAvenueJP\Model\Order\ReadyForHandling as ReadyForHandlingService;
use IXPF\Syncer\Controller\V1\Orders\BulkAction;

/**
 * Adds CarpetAvenue-specific actions to the IXPF Syncer bulk-action endpoint —
 * without touching the Syncer module.
 *
 * IXPF_Syncer owns only core Magento actions. Its BulkAction::resolveActionData()
 * returns null for actions it does not own; this after-plugin supplies the
 * response data for the store-specific actions:
 *   - ready_for_handling   (CarpetAvenue order status flow)
 *   - print_shipping_slip  (Evalent picking slip PDF)
 *
 * @see \IXPF\Syncer\Controller\V1\Orders\BulkAction::resolveActionData()
 */
class BulkActionReadyForHandling
{
    private const ACTION_READY_FOR_HANDLING = 'ready_for_handling';

    public function __construct(
        protected readonly ReadyForHandlingService $readyForHandling
    ) {
    }

    /**
     * @param BulkAction $subject
     * @param array|null $result   Data resolved by core (null if unhandled)
     * @param string     $action
     * @param int[]      $orderIds
     * @param array      $payload
     * @return array|null
     */
    public function afterResolveActionData(
        BulkAction $subject,
        ?array $result,
        string $action,
        array $orderIds,
        array $payload
    ): ?array {
        if ($result !== null) {
            return $result;
        }

        return match ($action) {
            self::ACTION_READY_FOR_HANDLING  => $this->readyForHandlingData($orderIds),
            default => null,
        };
    }

    /**
     * @param int[] $orderIds
     * @return array{summary: array, results: array}
     */
    private function readyForHandlingData(array $orderIds): array
    {
        $results = $this->readyForHandling->execute($orderIds, 'IXPF API');
        $ok = count(array_filter($results, static fn ($r) => !empty($r['success'])));

        return [
            'summary' => [
                'total'   => count($orderIds),
                'success' => $ok,
                'failed'  => count($orderIds) - $ok,
            ],
            'results' => $results,
        ];
    }
}
