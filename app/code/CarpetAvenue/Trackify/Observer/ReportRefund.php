<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Marketing
 * @package   CarpetAvenue_Trackify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Trackify\Observer;

use CarpetAvenue\Trackify\Model\MeasurementProtocol;
use CarpetAvenue\Trackify\ViewModel\DataLayer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports GA4 `refund` over the Measurement Protocol when a credit memo is created.
 *
 * Bound to sales_order_creditmemo_save_commit_after, not to sales_order_creditmemo_refund.
 * The latter looks like the obvious choice and fires exactly once per refund, but it is
 * dispatched from inside RefundOperation::execute() while the transaction is still open —
 * a rolled-back refund would report money returned that never was. Same reasoning as
 * Observer\RemoveFromWishlist listening on _delete_commit_after.
 *
 * Firing on every save instead means this has to be idempotent, which is what
 * trackify_refund_sent_at is for: it is written only once Google has accepted the event,
 * so a re-saved credit memo never double-reports, and a send that failed is retried by the
 * next save rather than lost.
 *
 * Covers every route into a refund — the admin credit-memo form, the Sales API, and the
 * IXPF Syncer's ERP endpoints — because all of them end up saving a credit memo. That is
 * also why the binding lives in the global etc/events.xml rather than an area-specific one.
 */
class ReportRefund implements ObserverInterface
{
    public function __construct(
        private readonly MeasurementProtocol $measurementProtocol,
        private readonly DataLayer $dataLayer,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $creditmemo = $observer->getEvent()->getData('creditmemo');

            if (!$creditmemo instanceof Creditmemo || !$creditmemo->getId()) {
                return;
            }

            // Already reported and accepted; nothing to do on a later save.
            if ($creditmemo->getData('trackify_refund_sent_at')) {
                return;
            }

            $order = $creditmemo->getOrder();

            if (!$order || !$order->getId()) {
                return;
            }

            $storeId = (int) $order->getStoreId();

            if (!$this->measurementProtocol->isEnabled($storeId)) {
                return;
            }

            $clientId = trim((string) $order->getData('trackify_client_id'));

            if ($clientId === '') {
                // Placed before this feature existed, or by a customer who declined
                // analytics consent. Nothing to attribute the refund to, so nothing is
                // sent — inventing an id would fabricate a user in the reports.
                $this->logger->debug(
                    sprintf(
                        'Trackify: no GA client id on order %s, refund not reported.',
                        $order->getIncrementId()
                    )
                );

                return;
            }

            $event = $this->dataLayer->getRefundEvent($creditmemo);

            if ($event === []) {
                // No refundable lines — a shipping-only or adjustment-only credit memo,
                // which GA4 cannot represent without reversing the whole transaction.
                $this->logger->info(
                    sprintf(
                        'Trackify: credit memo %s on order %s has no refundable items; '
                        . 'GA4 would read that as a full refund, so nothing was reported.',
                        $creditmemo->getIncrementId(),
                        $order->getIncrementId()
                    )
                );

                return;
            }

            if ($this->measurementProtocol->send($event, $clientId, $storeId)) {
                $this->markReported((int) $creditmemo->getId());
            }
        } catch (Throwable $e) {
            // A refund must never fail because of analytics.
            $this->logger->warning('Trackify: refund reporting failed — ' . $e->getMessage());
        }
    }

    /**
     * Flag the credit memo as reported.
     *
     * A direct UPDATE rather than $creditmemo->save(), which would re-enter this very
     * observer through save_commit_after and recurse.
     *
     * @param int $creditmemoId
     * @return void
     */
    private function markReported(int $creditmemoId): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName('sales_creditmemo'),
            ['trackify_refund_sent_at' => new Expression('NOW()')],
            ['entity_id = ?' => $creditmemoId]
        );
    }
}
