<?php

declare(strict_types=1);

namespace CarpetAvenue\Japan\Api;

/**
 * Config accessor for the JP royalty reports (carpet_reports/jp/*).
 */
interface ReportsConfigInterface
{
    /**
     * @return float
     */
    public function royaltyPercent(): float;

    /**
     * @return float
     */
    public function costMultiplier(): float;

    /**
     * @return float
     */
    public function costBase(): float;

    /**
     * Store view id of the JP store the royalty reports run against.
     *
     * @return int
     */
    public function storeId(): int;
}
