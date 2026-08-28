<?php
declare(strict_types=1);

namespace IXPF\Syncer\Block\Adminhtml\BulkUpdateQueue;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use IXPF\Syncer\Model\IxpfProductBulkUpdateQueue;

class View extends Template
{
    public function __construct(
        Context $context,
        protected Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getQueueData(): ?IxpfProductBulkUpdateQueue
    {
        return $this->registry->registry('bulk_update_queue');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function getJsonData(string $json): string
    {
        if (empty($json)) {
            return '';
        }

        // Validate JSON
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }

        // Return as escaped JSON string for JavaScript
        return $this->escapeHtmlAttr($json);
    }
}

