<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Block\Adminhtml\Media;

use CarpetAvenue\Utilities\Helper\Config;
use CarpetAvenue\Utilities\Service\Media\ManualBackupService;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Filesystem\DirectoryList;

class ManualBackup extends Template
{
    public function __construct(
        Context $context,
        private readonly Config        $config,
        private readonly DirectoryList $directoryList,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getProcessUrl(): string
    {
        return $this->getUrl('utilities/media_manualbackup/process');
    }

    public function getDownloadResultUrl(): string
    {
        return $this->getUrl('utilities/media_manualbackup/downloadresult');
    }

    public function getDefaultBucket(): string
    {
        return $this->config->getGcsBucket();
    }

    public function getDefaultCredentials(): string
    {
        return $this->config->getGcsCredentials();
    }

    public function hasLastResult(): bool
    {
        return file_exists($this->getLatestResultPointer())
            && file_exists(trim((string) file_get_contents($this->getLatestResultPointer())));
    }

    public function getLastResultName(): string
    {
        if (!$this->hasLastResult()) {
            return '';
        }
        return basename(trim((string) file_get_contents($this->getLatestResultPointer())));
    }

    private function getLatestResultPointer(): string
    {
        $varDir = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        return $varDir . '/../var/log/carpetavenue/' . ManualBackupService::LOG_DIR . '/latest_result.txt';
    }
}
