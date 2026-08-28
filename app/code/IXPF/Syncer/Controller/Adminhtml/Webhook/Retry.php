<?php

namespace IXPF\Syncer\Controller\Adminhtml\Webhook;

use IXPF\Syncer\Helper\Webhook;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Result\PageFactory;

class Retry extends Action
{
    const ADMIN_RESOURCE = 'IXPF_Syncer::webhook_list';

    public function __construct(
        Context $context,
        protected ResourceConnection $resourceConnection,
        protected Webhook $webhookHelper
    )
    {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('ixpf_webhook_jobs');
            $webhookId = (int)$this->getRequest()->getParam('entity_id');
            if ($webhookId) {
                $webhookJob = $connection->fetchRow(
                    $connection->select()
                        ->from($tableName)
                        ->where('entity_id = ?', $webhookId)
                );

                if (!$webhookJob) {
                    $this->messageManager->addErrorMessage(__('Webhook job not found.'));
                    $this->_redirect('ixpfsyncer/webhook/index');
                    return;
                }

                $connection->update(
                    $tableName,
                    ['trigger_status' => 'processing'],
                    ['entity_id = ?' => $webhookId]
                );

                $webhookResponse = $this->webhookHelper->callWebHook($webhookJob);
                if ($webhookResponse) {
                    $this->webhookHelper->updateJob($webhookJob, $webhookResponse);
                }

                $this->messageManager->addSuccessMessage(__('Webhook job has been set to retry.'));
            } else {
                $this->messageManager->addErrorMessage(__('Invalid webhook ID.'));
            }

            // For now, just redirect back to the webhook list
            $this->_redirect('ixpfsyncer/webhook/index');
            return;
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('An error occurred while retrying the webhook job: %1', $e->getMessage()));
            $this->_redirect('ixpfsyncer/webhook/index');
            return;
        }
    }
}