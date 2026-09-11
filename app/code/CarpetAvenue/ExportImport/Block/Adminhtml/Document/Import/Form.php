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

namespace CarpetAvenue\ExportImport\Block\Adminhtml\Document\Import;

use CarpetAvenue\ExportImport\Controller\Adminhtml\Document\Import\Validate;
use CarpetAvenue\ExportImport\Model\Document\Pool;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;

/**
 * Upload / validate / import form for invoices and credit memos.
 */
class Form extends Template
{
    public function __construct(
        Context $context,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly Pool $pool,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getDocumentType(): string
    {
        return (string)$this->getRequest()->getParam('type');
    }

    public function getDocumentLabel(): string
    {
        $type = $this->getDocumentType();
        return $this->pool->has($type) ? $this->pool->getType($type)->getLabel() : $type;
    }

    public function getValidateAction(): string
    {
        return $this->getUrl('exportimport/document_import/validate', ['type' => $this->getDocumentType()]);
    }

    public function getSubmitAction(): string
    {
        return $this->getUrl('exportimport/document_import/submit', ['type' => $this->getDocumentType()]);
    }

    /** @return string[] */
    public function getRequiredColumns(): array
    {
        $type = $this->getDocumentType();
        return $this->pool->has($type) ? $this->pool->getType($type)->getRequiredColumns() : [];
    }

    /**
     * The validation / import report stored by the controllers. Read once and
     * cleared so a page refresh does not show a stale report.
     *
     * @return array<string, mixed>|null
     */
    public function getReport(): ?array
    {
        $key = Validate::sessionReportKey($this->getDocumentType());
        $report = $this->dataPersistor->get($key);
        if ($report) {
            $this->dataPersistor->clear($key);
        }
        return is_array($report) ? $report : null;
    }

    /** Whether a validated file is queued and ready to import. */
    public function hasPendingFile(): bool
    {
        return (string)$this->dataPersistor->get(Validate::sessionFileKey($this->getDocumentType())) !== '';
    }
}
