<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Handles the "Upload CLI CSV" field in Manual Media Backup config.
 * Saves uploaded file as cli_input_{timestamp}.csv under var/carpetavenue/manual_backup/.
 * Stores the absolute path in core_config_data so the admin can reference it for CLI use.
 */
class ManualBackupCsv extends Value
{
    private const UPLOAD_DIR = 'carpetavenue/manual_backup';

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly DirectoryList $directoryList,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave(): static
    {
        $value = $this->getValue();

        // No new upload — preserve existing stored path
        if (!is_array($value) || empty($value['tmp_name'])) {
            if (is_array($value) && isset($value['value'])) {
                $this->setValue($value['value']);
            }
            return $this;
        }

        $tmpFile  = (string) $value['tmp_name'];
        $origName = (string) ($value['name'] ?? '');
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($ext !== 'csv') {
            throw new LocalizedException(__('Only CSV files are allowed for CLI input upload.'));
        }

        if (!is_uploaded_file($tmpFile)) {
            throw new LocalizedException(__('Invalid file upload.'));
        }

        $destDir = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/' . self::UPLOAD_DIR;

        if (!is_dir($destDir) && !mkdir($destDir, 0750, true)) {
            throw new LocalizedException(__('Cannot create upload directory: %1', $destDir));
        }

        $timestamp = date('Ymd_His');
        $destFile  = $destDir . '/cli_input_' . $timestamp . '.csv';

        if (!move_uploaded_file($tmpFile, $destFile)) {
            throw new LocalizedException(__('Failed to save CLI CSV file.'));
        }

        // Store absolute path — admin copies it for --csv= option
        $this->setValue($destFile);

        return $this;
    }
}
