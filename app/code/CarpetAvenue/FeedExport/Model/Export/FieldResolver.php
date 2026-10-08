<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model\Export;

/**
 * Decides which fields are worth computing by scanning the XSL template.
 *
 * Every select="..." / test="..." expression is collected; a field is required when any
 * expression contains its name (case-insensitive substring), so "parent_item/description"
 * pulls in "description" and "base_price_unit_raw" pulls in "base_price_unit".
 * An empty template means "export everything".
 */
class FieldResolver
{
    /** @var string[] */
    private array $expressions = [];

    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(string $xslTemplate = '')
    {
        if (preg_match_all('/(?:select|test)="([^"]+)"/', $xslTemplate, $matches)) {
            $this->expressions = array_values(array_unique($matches[1]));
        }
        if ($this->expressions) {
            $this->expressions[] = 'entity_id';
            $this->expressions[] = 'created_at';
        }
    }

    public function isRequired(string $field): bool
    {
        if (!$this->expressions) {
            return true;
        }
        if (isset($this->cache[$field])) {
            return $this->cache[$field];
        }
        $required = false;
        foreach ($this->expressions as $expression) {
            if (stripos($expression, $field) !== false) {
                $required = true;
                break;
            }
        }
        return $this->cache[$field] = $required;
    }

    public function isUnrestricted(): bool
    {
        return !$this->expressions;
    }
}
