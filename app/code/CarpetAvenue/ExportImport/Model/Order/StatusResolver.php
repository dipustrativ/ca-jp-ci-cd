<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Order
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Model\Order;

use Magento\Sales\Model\Order\Config as OrderConfig;

/**
 * Works out the status/state pair an order row should get when the CSV leaves
 * one or both of `order_status` / `order_state` empty.
 *
 *  - both given     -> used as-is
 *  - status only    -> state is the one the status is assigned to
 *                      (sales_order_status_state), preferring the state it is
 *                      the default status of
 *  - state only     -> status is the state's default status
 *  - neither        -> both null; the importer decides what null means (create
 *                      falls back to new/pending, update leaves the order alone)
 *
 * Shared by the validator (to report what will be written) and the importer
 * (to write it), so the warning and the result cannot drift apart.
 */
class StatusResolver
{
    /** @var array<string, string>|null status => state (per-run cache) */
    private ?array $stateByStatus = null;

    public function __construct(
        private readonly OrderConfig $orderConfig,
    ) {
    }

    /**
     * @return array{status: ?string, state: ?string}
     */
    public function resolve(string $status, string $state): array
    {
        if ($status === '' && $state !== '') {
            $default = $this->orderConfig->getStateDefaultStatus($state);
            $status = $default !== null ? (string)$default : '';
        } elseif ($state === '' && $status !== '') {
            $state = $this->stateByStatus()[$status] ?? '';
        }

        return [
            'status' => $status !== '' ? $status : null,
            'state' => $state !== '' ? $state : null,
        ];
    }

    /** @return array<string, string> */
    private function stateByStatus(): array
    {
        if ($this->stateByStatus !== null) {
            return $this->stateByStatus;
        }

        $map = [];
        foreach (array_keys($this->orderConfig->getStates()) as $state) {
            $state = (string)$state;
            $default = (string)$this->orderConfig->getStateDefaultStatus($state);
            if ($default !== '') {
                // A state's own default status wins over any other assignment.
                $map[$default] = $state;
            }
            foreach ($this->orderConfig->getStateStatuses($state, false) as $status) {
                $map[(string)$status] ??= $state;
            }
        }

        return $this->stateByStatus = $map;
    }
}
