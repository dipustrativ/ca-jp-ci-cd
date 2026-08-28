<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Block\Adminhtml\System\Config;

use CarpetAvenue\Utilities\Service\Media\ManualBackupService;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders "Download Last Result CSV" link in the Manual Media Backup config group.
 * Shows filename and links to the DownloadResult controller.
 * Works for results from both UI and CLI runs (both update latest_result.txt).
 */
class ManualBackupDownloadLink extends Field
{
    public function __construct(
        Context $context,
        private readonly DirectoryList $directoryList,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $bp          = $this->directoryList->getRoot();
        $pointerFile = $bp . '/var/log/carpetavenue/' . ManualBackupService::LOG_DIR . '/latest_result.txt';

        if (!file_exists($pointerFile)) {
            return '<span style="color:#999;font-style:italic;">'
                . $this->escapeHtml(__('No result yet — run a backup first (UI or CLI).'))
                . '</span>';
        }

        $resultPath = trim((string) file_get_contents($pointerFile));

        if ($resultPath === '' || !file_exists($resultPath)) {
            return '<span style="color:#c7254e;font-style:italic;">'
                . $this->escapeHtml(__('Result file no longer exists: '))
                . $this->escapeHtml(basename($resultPath))
                . '</span>';
        }

        $url  = $this->getUrl('utilities/media_manualbackup/downloadresult');
        $name = basename($resultPath);

        return sprintf(
            '<a href="%s" class="action-default scalable" style="display:inline-block;margin-top:4px;">%s</a>'
            . '<span style="color:#888;font-size:12px;margin-left:10px;">%s</span>',
            $this->escapeUrl($url),
            $this->escapeHtml(__('Download Last Result CSV')),
            $this->escapeHtml($name)
        );
    }
}
