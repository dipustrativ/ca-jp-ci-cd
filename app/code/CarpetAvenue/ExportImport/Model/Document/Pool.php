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

namespace CarpetAvenue\ExportImport\Model\Document;

use Magento\Framework\Exception\LocalizedException;

/**
 * Resolves a document type code (`invoice`, `creditmemo`, `shipment`) to the
 * exporter, validator and importer configured for it.
 *
 * Controllers and CLI commands take the code from a request parameter or a
 * `--type` option, so it is untrusted input: everything here goes through
 * {@see assertKnown()} and an unknown code raises rather than silently falling
 * back to a default. Adding a further document type means adding one `Type`
 * class and four di.xml array entries — no controller or command changes.
 */
class Pool
{
    /**
     * @param array<string, TypeInterface> $types keyed by document code
     * @param array<string, Exporter> $exporters
     * @param array<string, Validator> $validators
     * @param array<string, Importer> $importers
     */
    public function __construct(
        private readonly array $types = [],
        private readonly array $exporters = [],
        private readonly array $validators = [],
        private readonly array $importers = [],
    ) {
    }

    /** @return string[] */
    public function getCodes(): array
    {
        return array_keys($this->types);
    }

    /**
     * Code => human label, for admin selects and CLI help.
     *
     * @return array<string, string>
     */
    public function getLabels(): array
    {
        $labels = [];
        foreach ($this->types as $code => $type) {
            $labels[$code] = $type->getLabel();
        }
        return $labels;
    }

    public function has(string $code): bool
    {
        return isset($this->types[$code]);
    }

    /**
     * @throws LocalizedException
     */
    public function getType(string $code): TypeInterface
    {
        $this->assertKnown($code);
        return $this->types[$code];
    }

    /**
     * @throws LocalizedException
     */
    public function getExporter(string $code): Exporter
    {
        $this->assertKnown($code);
        return $this->exporters[$code];
    }

    /**
     * @throws LocalizedException
     */
    public function getValidator(string $code): Validator
    {
        $this->assertKnown($code);
        return $this->validators[$code];
    }

    /**
     * @throws LocalizedException
     */
    public function getImporter(string $code): Importer
    {
        $this->assertKnown($code);
        return $this->importers[$code];
    }

    /**
     * @throws LocalizedException
     */
    private function assertKnown(string $code): void
    {
        if (!isset($this->types[$code], $this->exporters[$code], $this->validators[$code], $this->importers[$code])) {
            throw new LocalizedException(__(
                'Unknown document type "%1". Known types: %2.',
                $code,
                implode(', ', $this->getCodes())
            ));
        }
    }
}
