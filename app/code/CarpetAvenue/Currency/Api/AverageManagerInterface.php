<?php
namespace CarpetAvenue\Currency\Api;

interface AverageManagerInterface{
    /**
     * @param int $id
     * @param float $rate
     * @return void
     */
    public function updateRate($id,$rate): void;

    /**
     * @return void
     */
    public function insertAverageCurrencyRows(): void;

    /**
     * @return array
     */
    public function getUnsyncedData(): array;
}