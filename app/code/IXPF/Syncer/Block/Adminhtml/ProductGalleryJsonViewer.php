<?php

namespace IXPF\Syncer\Block\Adminhtml;

use IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;
use Magento\Backend\Block\Template;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Json\Helper\Data as JsonHelper;

class ProductGalleryJsonViewer extends Template
{
    public function __construct(
        Template\Context $context,
        protected ResourceConnection $resourceConnection,
        array            $data = [],
        ?JsonHelper      $jsonHelper = null,
        ?DirectoryHelper $directoryHelper = null
    )
    {
        parent::__construct($context, $data, $jsonHelper, $directoryHelper);
    }

    public function getJsonData(): array
    {
        $webhookId = (int)$this->getRequest()->getParam('entity_id');
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(IxpfPhotoStudioQueue::TABLE);
        $select = $connection->select()
            ->from($tableName)
            ->where('entity_id = ?', $webhookId);
        $result = $connection->fetchRow($select);

        if (isset($result['payload']) && is_string($result['payload'])) {
            if ($this->isValidJson($result['payload'])) {
                $result['ixpf_payload'] = json_decode($result['payload'], true);
            }
            unset($result['payload']);
        }

        if (isset($result['response_result']) && is_string($result['response_result'])) {
            if ($this->isValidJson($result['response_result'])) {
                $result['webhook_details'] = json_decode($result['response_result'], true);
            }
            unset($result['response_result']);
        }
        if (isset($result['ixpf_response']) && is_string($result['ixpf_response'])) {
            if ($this->isValidJson($result['ixpf_response'])) {
                $result['ixpf_response'] = json_decode($result['ixpf_response'], true);
            }
        }

        return $result ?: [];
    }

    private function isValidJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}