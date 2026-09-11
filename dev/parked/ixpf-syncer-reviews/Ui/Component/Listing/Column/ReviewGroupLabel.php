<?php

declare(strict_types=1);

namespace IXPF\Syncer\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the group_label column, which is stored as a locale-keyed JSON object.
 *
 * Raw JSON in a grid cell is unreadable, and the label is the only thing that makes a pool key
 * like "8518C" recognisable. Falls back to the first locale IXPF sent, because a pool for a
 * market that is not live yet may carry no en_US label at all.
 *
 * Rendered as plain text, not through ui/grid/cells/html: the value is third-party content and
 * the default text binding escapes it.
 */
class ReviewGroupLabel extends Column
{
    private const PREFERRED_LOCALE = 'en_US';

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $item[$name] = $this->resolve($item['group_label'] ?? null);
        }

        return $dataSource;
    }

    private function resolve(?string $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }

        $labels = json_decode($json, true);
        if (!is_array($labels) || $labels === []) {
            return '';
        }

        $label = $labels[self::PREFERRED_LOCALE] ?? reset($labels);

        return is_string($label) ? $label : '';
    }
}
