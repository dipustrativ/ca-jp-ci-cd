<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\File as FileBackend;
use Magento\Config\Model\Config\Backend\File\RequestData\RequestDataInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Filesystem;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\MediaStorage\Model\File\UploaderFactory;

/**
 * Backend model for GCS service-account JSON key upload.
 * Stores the file under var/carpetavenue/gcs/ (not pub/media).
 * Saved config value is the filename only; Helper/Config resolves the full path.
 */
class GcsCredentials extends FileBackend
{
    private DirectoryList $directoryList;

    public function __construct(
        Context              $context,
        Registry             $registry,
        ScopeConfigInterface $config,
        TypeListInterface    $cacheTypeList,
        UploaderFactory      $uploaderFactory,
        RequestDataInterface $requestData,
        Filesystem           $filesystem,
        DirectoryList        $directoryList,
        ?AbstractResource    $resource = null,
        ?AbstractDb          $resourceCollection = null,
        array                $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $uploaderFactory,
            $requestData,
            $filesystem,
            $resource,
            $resourceCollection,
            $data
        );
        $this->directoryList = $directoryList;
    }

    /**
     * Upload destination: var/carpetavenue/gcs/
     * Directory is created on first upload if it does not exist.
     */
    protected function _getUploadDir(): string
    {
        $dir = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/carpetavenue/gcs';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * No scope suffix in the stored filename.
     */
    protected function _addWhetherScopeInfo(): bool
    {
        return false;
    }

    /**
     * Only .json files are accepted.
     */
    protected function _getAllowedExtensions(): array
    {
        return ['json'];
    }
}
