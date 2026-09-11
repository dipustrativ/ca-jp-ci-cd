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

namespace CarpetAvenue\ExportImport\Controller\Adminhtml\Customer\Address\Import;

use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Order\CsvReader;
use CarpetAvenue\ExportImport\Model\CustomerAddress\Validator;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\UploaderFactory;

/**
 * Step 1 of a Customer Address import: upload the CSV and run the manual-check
 * validation. No data is written here — only the file is stored and a report
 * produced.
 *
 * The session keys are per entity, so a validated customer file can never be
 * submitted on the address screen or the other way round.
 */
class Validate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_ExportImport::import';

    /** Sub-directory under var/ for uploaded Customer Address import files. */
    private const UPLOAD_DIR = 'import/carpetavenue_customer_addresses';

    public const SESSION_FILE_KEY = 'carpetavenue_exportimport_customer_address_file';
    public const SESSION_REPORT_KEY = 'carpetavenue_exportimport_customer_address_report';

    public function __construct(
        Action\Context $context,
        private readonly Config $config,
        private readonly Validator $validator,
        private readonly UploaderFactory $uploaderFactory,
        private readonly Filesystem $filesystem,
        private readonly CsvReader $csvReader,
        private readonly DataPersistorInterface $dataPersistor,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('exportimport/customer_address_import/index');

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Order Export / Import is disabled in configuration.'));
            return $redirect;
        }

        $this->dataPersistor->clear(self::SESSION_FILE_KEY);
        $this->dataPersistor->clear(self::SESSION_REPORT_KEY);

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
            $result = $this->validator->validate($parsed);

            $this->dataPersistor->set(self::SESSION_FILE_KEY, $relativePath);
            $this->dataPersistor->set(self::SESSION_REPORT_KEY, [
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
