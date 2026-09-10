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

use Magento\Framework\File\Csv;
use Magento\Framework\Exception\LocalizedException;

/**
 * Parses an order CSV file into a header + associative rows structure.
 *
 * The first line is treated as the header. Each subsequent line is mapped to an
 * associative row keyed by the header columns. `line` is the 1-based file line
 * number (header = line 1) so validation messages point at the real row.
 */
class CsvReader
{
    public function __construct(
        private readonly Csv $csv,
    ) {
    }

    /**
     * @param string $absolutePath
     * @return array{header: string[], rows: array<int, array{line: int, data: array<string, string>}>}
     * @throws LocalizedException
     */
    public function read(string $absolutePath): array
    {
        if (!is_file($absolutePath)) {
            throw new LocalizedException(__('CSV file not found: %1', $absolutePath));
        }

        $data = $this->csv->getData($absolutePath);
        if (empty($data)) {
            throw new LocalizedException(__('The CSV file is empty.'));
        }

        $header = array_map(static fn ($h) => trim((string)$h), array_shift($data));
        if (empty(array_filter($header))) {
            throw new LocalizedException(__('The CSV header row is empty.'));
        }

        $rows = [];
        $line = 1; // header consumed
        foreach ($data as $rawRow) {
            $line++;
            // Skip fully blank lines.
            if (count(array_filter($rawRow, static fn ($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }
            $assoc = [];
            foreach ($header as $index => $column) {
                $assoc[$column] = isset($rawRow[$index]) ? trim((string)$rawRow[$index]) : '';
            }
            $rows[] = ['line' => $line, 'data' => $assoc];
        }

        return ['header' => $header, 'rows' => $rows];
    }
}
