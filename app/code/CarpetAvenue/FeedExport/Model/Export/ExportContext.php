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

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;

/**
 * Per-run state shared by all data providers: profile, field filter, value formatting and caches.
 */
class ExportContext
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(
        private readonly ProfileInterface $profile,
        private readonly FieldResolver $fieldResolver
    ) {
    }

    public function getProfile(): ProfileInterface
    {
        return $this->profile;
    }

    public function getStoreId(): int
    {
        return $this->profile->getStoreId();
    }

    public function isRequired(string $field): bool
    {
        return $this->fieldResolver->isRequired($field);
    }

    /**
     * Write a scalar (or flat array as nested node) when the template needs it.
     * Empty values are dropped so string(field) tests in XSL behave as before.
     *
     * @param array<string, mixed> $target
     */
    public function write(array &$target, string $field, mixed $value): void
    {
        if ($value === null || $value === '' || is_object($value) || !$this->isRequired($field)) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_scalar($item) && $item !== '') {
                    $target[$field][(string)$key] = $this->format($item);
                }
            }
            return;
        }
        $target[$field] = $this->format($value);
    }

    /**
     * @param array<string, mixed> $target
     */
    public function writeIfRequired(array &$target, string $field, callable $valueProvider): void
    {
        if ($this->isRequired($field)) {
            $this->write($target, $field, $valueProvider());
        }
    }

    public function getCache(string $key, callable $loader): mixed
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $loader();
        }
        return $this->cache[$key];
    }

    public function setCache(string $key, mixed $value): void
    {
        $this->cache[$key] = $value;
    }

    private function format(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        $value = (string)$value;
        $value = match ($this->profile->getReplaceNewlines()) {
            ProfileInterface::NEWLINES_BR => str_replace(["\r\n", "\r", "\n"], '<br />', $value),
            ProfileInterface::NEWLINES_SPACE => str_replace(["\r\n", "\r", "\n"], ' ', $value),
            ProfileInterface::NEWLINES_REMOVE => str_replace(["\r\n", "\r", "\n"], '', $value),
            default => $value,
        };
        if ($this->profile->isStripTags()) {
            $value = strip_tags($value);
        }
        return $value;
    }
}
