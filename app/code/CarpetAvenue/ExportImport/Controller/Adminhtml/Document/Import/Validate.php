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

namespace CarpetAvenue\ExportImport\Controller\Adminhtml\Document\Import;

use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Document\Pool;
use CarpetAvenue\ExportImport\Model\Order\CsvReader;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\UploaderFactory;

/**
 * Step 1 of a document import: upload the CSV and run the manual-check
 * validation. No data is written here — only the file is stored and a report
 * produced.
 *
 * The session keys are suffixed with the document type, so a validated invoice
 * file can never be submitted on the credit-memo screen (or the other way
 * round) — the two CSVs share several column names, so a mix-up would otherwise
 * partly "work".
 */
class Validate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_ExportImport::import';

    /** Sub-directory under var/ for uploaded document import files. */
    private const UPLOAD_DIR = 'import/carpetavenue_documents';

    private const SESSION_FILE_PREFIX = 'carpetavenue_exportimport_document_file_';
    private const SESSION_REPORT_PREFIX = 'carpetavenue_exportimport_document_report_';

    public function __construct(
        Action\Context $context,
        private readonly Config $config,
        private readonly Pool $pool,
        private readonly UploaderFactory $uploaderFactory,
        private readonly Filesystem $filesystem,
        private readonly CsvReader $csvReader,
        private readonly DataPersistorInterface $dataPersistor,
    ) {
        parent::__construct($context);
    }

    public static function sessionFileKey(string $type): string
    {
        return self::SESSION_FILE_PREFIX . $type;
    }

    public static function sessionReportKey(string $type): string
    {
        return self::SESSION_REPORT_PREFIX . $type;
    }

    public function execute(): ResultInterface
    {
        $type = (string)$this->getRequest()->getParam('type');
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('exportimport/document_import/index', ['type' => $type]);

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Order Export / Import is disabled in configuration.'));
            return $redirect;
        }
        if (!$this->pool->has($type)) {
            $this->messageManager->addErrorMessage(__('Unknown document type "%1".', $type));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('admin/dashboard');
        }

        $this->dataPersistor->clear(self::sessionFileKey($type));
        $this->dataPersistor->clear(self::sessionReportKey($type));

        try {
            $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $directory->create(self::UPLOAD_DIR);
            $targetDir = $directory->getAbsolutePath(self::UPLOAD_DIR);

            $uploader = $this->uploaderFactory->create(['fileId' => 'import_file']);
            $uploader->setAllowedExtensions(['csv']);
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);
            $saved = $uploader->save($targetDir);

            $relativePath = self::UPLOAD_DIR . '/' . $saved['file'];
            $parsed = $this->csvReader->read($directory->getAbsolutePath($relativePath));
            $result = $this->pool->getValidator($type)->validate($parsed);

            $this->dataPersistor->set(self::sessionFileKey($type), $relativePath);
            $this->dataPersistor->set(self::sessionReportKey($type), [
                'phase' => 'validated',
                'summary' => $result->getSummary(),
                'errors' => $result->getErrors(),
                'warnings' => $result->getWarnings(),
            ]);

            if ($result->isValid()) {
                $this->messageManager->addSuccessMessage(
                    __('Validation passed. Review the report below, then run the import.')
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __(
                        'Validation found %1 error(s). Fix the CSV and re-upload.',
                        $result->getSummary()['errors']
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not validate the CSV: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
