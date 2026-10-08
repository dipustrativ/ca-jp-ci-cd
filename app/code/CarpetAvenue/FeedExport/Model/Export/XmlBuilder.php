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

use XMLWriter;

/**
 * Streams exported rows into the intermediate XML document the XSL template runs against:
 *
 * <objects><object><sku/>...<images><image><url/></image></images>...</object></objects>
 *
 * List children are named after their parent minus the trailing "s"
 * (images/image, cats/cat, msi_stocks/msi_stock), same shape as the Xtento feeds expect.
 */
class XmlBuilder
{
    private const ROOT = 'objects';
    private const ITEM = 'object';

    private XMLWriter $writer;

    private int $count = 0;

    public function __construct()
    {
        $this->writer = new XMLWriter();
        $this->writer->openMemory();
        $this->writer->setIndent(false);
        $this->writer->startDocument('1.0', 'UTF-8');
        $this->writer->startElement(self::ROOT);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function addObject(array $row): void
    {
        $this->writer->startElement(self::ITEM);
        $this->writeArray($row, self::ITEM);
        $this->writer->endElement();
        $this->count++;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getDocument(): string
    {
        $this->writer->endElement();
        $this->writer->endDocument();
        return $this->writer->outputMemory();
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function writeArray(array $data, string $parentName): void
    {
        foreach ($data as $key => $value) {
            $name = is_int($key) ? $this->childName($parentName) : $this->elementName((string)$key);
            if (is_array($value)) {
                $this->writer->startElement($name);
                $this->writeArray($value, $name);
                $this->writer->endElement();
                continue;
            }
            if ($value === null || is_object($value)) {
                continue;
            }
            $this->writer->startElement($name);
            $this->writer->text($this->stripInvalidXml((string)$value));
            $this->writer->endElement();
        }
    }

    private function childName(string $parentName): string
    {
        if (strlen($parentName) > 1 && str_ends_with($parentName, 's')) {
            return substr($parentName, 0, -1);
        }
        return self::ITEM;
    }

    private function elementName(string $key): string
    {
        $name = preg_replace('/[^A-Za-z0-9_\-.]/', '_', trim($key)) ?: '_';
        if (!preg_match('/^[A-Za-z_]/', $name)) {
            $name = '_' . $name;
        }
        return $name;
    }

    private function stripInvalidXml(string $value): string
    {
        return (string)preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $value);
    }
}
