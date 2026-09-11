<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Customer
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Block\Adminhtml\Customer\Import;

use CarpetAvenue\ExportImport\Controller\Adminhtml\Customer\Import\Validate;
use CarpetAvenue\ExportImport\Model\Customer\Schema;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;

/**
 * Upload / validate / import form for customers.
 */
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
        return $this->getUrl('exportimport/customer_import/validate');
    }

    public function getSubmitAction(): string
    {
        return $this->getUrl('exportimport/customer_import/submit');
    }

    /** @return string[] */
    public function getRequiredColumns(): array
    {
        return $this->schema->getRequiredColumns();
    }

    /**
     * The install-specific EAV attributes this entity's `extra_attributes`
     * column carries, so the screen can say what a file from here will contain.
     *
     * @return string[]
     */
    public function getExtraAttributeCodes(): array
    {
        return $this->schema->getExtraAttributeCodes();
    }

    /**
     * The validation / import report stored by the controllers. Read once and
     * cleared so a page refresh does not show a stale report.
     *
     * @return array<string, mixed>|null
     */
    public function getReport(): ?array
    {
        $report = $this->dataPersistor->get(Validate::SESSION_REPORT_KEY);
        if ($report) {
            $this->dataPersistor->clear(Validate::SESSION_REPORT_KEY);
        }
        return is_array($report) ? $report : null;
    }

    /** Whether a validated file is queued and ready to import. */
    public function hasPendingFile(): bool
    {
        return (string)$this->dataPersistor->get(Validate::SESSION_FILE_KEY) !== '';
    }
}
