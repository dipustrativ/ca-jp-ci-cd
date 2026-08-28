<?php

namespace IXPF\Syncer\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Json\Helper\Data as JsonHelper;

class JsonViewer extends Template
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
        $tableName = $this->resourceConnection->getTableName('ixpf_webhook_jobs');
        $select = $connection->select()
            ->from($tableName)
            ->where('entity_id = ?', $webhookId);
        $result = $connection->fetchRow($select);
        if (isset($result['payload']) && is_string($result['payload'])) {
            if ($this->isValidJson($result['payload'])) {
                $result['payload'] = json_decode($result['payload'], true);
            }
        }
        if (isset($result['last_response']) && is_string($result['last_response'])) {
            if ($this->isValidJson($result['last_response'])) {
                $result['last_response'] = json_decode($result['last_response'], true);
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