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

namespace CarpetAvenue\ExportImport\Block\Adminhtml\Import;

use CarpetAvenue\ExportImport\Controller\Adminhtml\Import\Validate;
use CarpetAvenue\ExportImport\Model\Order\Schema;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;

class Form extends Template
{
    public function __construct(
        Context $context,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly Schema $schema,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getValidateAction(): string
    {
        return $this->getUrl('exportimport/import/validate');
    }

    public function getSubmitAction(): string
    {
        return $this->getUrl('exportimport/import/submit');
    }

    /** Expected CSV header, for the on-page hint. */
    public function getExpectedHeader(): string
    {
        return implode(',', $this->schema->getHeader());
    }

    public function getRequiredColumns(): array
    {
        return $this->schema->getRequiredColumns();
    }

    /**
     * The validation / import report stored by the controllers. Read once and
     * cleared so a page refresh does not show a stale report.
     *
     * @return array<string, mixed>|null
     */
    public function getReport(): ?array
    {
        $report = $this->dataPersistor->get(Validate::SESSION_REPORT);
        if ($report) {
            $this->dataPersistor->clear(Validate::SESSION_REPORT);
        }
        return is_array($report) ? $report : null;
    }

    /** Whether a validated file is queued and ready to import. */
    public function hasPendingFile(): bool
    {
        return (string)$this->dataPersistor->get(Validate::SESSION_FILE) !== '';
    }
}
