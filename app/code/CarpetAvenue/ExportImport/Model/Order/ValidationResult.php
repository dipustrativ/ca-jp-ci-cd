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

/**
 * Mutable outcome of validating an order CSV. Not injected — created per run.
 *
 * Entity-agnostic despite the namespace: `Model\Document\Validator` (invoices and
 * credit memos) uses the same class, keyed by document increment id instead of
 * order increment id. It stayed here rather than moving to a neutral namespace so
 * the class the order half has always used keeps its name and history.
 */
class ValidationResult
{
    /** @var array<int, array{line: int|string, column: string, message: string}> */
    private array $errors = [];

    /** @var array<int, array{line: int|string, column: string, message: string}> */
    private array $warnings = [];

    /** @var array<string, array{mode: string, lines: int[]}> increment_id => plan */
    private array $plan = [];

    public function addError(int|string $line, string $column, string $message): void
    {
        $this->errors[] = ['line' => $line, 'column' => $column, 'message' => $message];
    }

    public function addWarning(int|string $line, string $column, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'column' => $column, 'message' => $message];
    }

    public function registerOrder(string $incrementId, string $mode, int $line): void
    {
        if (!isset($this->plan[$incrementId])) {
            $this->plan[$incrementId] = ['mode' => $mode, 'lines' => []];
        }
        $this->plan[$incrementId]['lines'][] = $line;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<int, array{line: int|string, column: string, message: string}> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return array<int, array{line: int|string, column: string, message: string}> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, array{mode: string, lines: int[]}> */
    public function getPlan(): array
    {
        return $this->plan;
    }

    public function countCreates(): int
    {
        return count(array_filter($this->plan, static fn ($p) => $p['mode'] === 'create'));
    }

    public function countUpdates(): int
    {
        return count(array_filter($this->plan, static fn ($p) => $p['mode'] === 'update'));
    }

    /**
     * @return array{orders: int, creates: int, updates: int, errors: int, warnings: int}
     */
    public function getSummary(): array
    {
        return [
            'orders' => count($this->plan),
            'creates' => $this->countCreates(),
            'updates' => $this->countUpdates(),
            'errors' => count($this->errors),
            'warnings' => count($this->warnings),
        ];
    }
}
