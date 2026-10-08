<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Products;

/**
 * Shared validation for price / special_price values coming from syncer payloads.
 *
 * special_price: 0 or null → cleared (stored as NULL), number > 0 → kept, anything else → invalid.
 * price:         number > 0 → kept, 0 or null → skipped (bad feed guard), anything else → invalid.
 *
 * Callers must not write anything for a product when 'invalid' is not empty.
 */
class PriceAttributeGuard
{
    /**
     * @param array $attributes attribute code => raw payload value
     * @return array{
     *     attributes: array,
     *     invalid: array<string, string>,
     *     cleared: string[],
     *     skipped: string[]
     * }
     */
    public function apply(array $attributes): array
    {
        $result = [
            'attributes' => $attributes,
            'invalid'    => [],
            'cleared'    => [],
            'skipped'    => [],
        ];

        if (array_key_exists('special_price', $attributes)) {
            $value = $attributes['special_price'];
            if ($value === null || ($this->isNumber($value) && (float)$value == 0)) {
                $result['attributes']['special_price'] = null;
                $result['cleared'][] = 'special_price';
            } elseif ($this->isNumber($value) && (float)$value > 0) {
                $result['attributes']['special_price'] = (float)$value;
            } else {
                unset($result['attributes']['special_price']);
                $result['invalid']['special_price'] = sprintf(
                    'Invalid special_price %s: must be a number greater than 0, or 0 to clear',
                    $this->describe($value)
                );
            }
        }

        if (array_key_exists('price', $attributes)) {
            $value = $attributes['price'];
            if ($value === null || ($this->isNumber($value) && (float)$value == 0)) {
                unset($result['attributes']['price']);
                $result['skipped'][] = 'price';
            } elseif ($this->isNumber($value) && (float)$value > 0) {
                $result['attributes']['price'] = (float)$value;
            } else {
                unset($result['attributes']['price']);
                $result['invalid']['price'] = sprintf(
                    'Invalid price %s: must be a number greater than 0',
                    $this->describe($value)
                );
            }
        }

        return $result;
    }

    /**
     * Real number or numeric string only — rejects bool, array, "", "abc", "5abc".
     */
    private function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }

    private function describe(mixed $value): string
    {
        return (string)json_encode($value);
    }
}
