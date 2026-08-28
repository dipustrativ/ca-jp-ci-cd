<?php

namespace IXPF\Syncer\Controller\Adminhtml\Webhook;

use IXPF\Syncer\Helper\Webhook;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Result\PageFactory;
use IXPF\Syncer\Model\ResourceModel\IxpfWebhookJobs\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;

class MassRetry extends Action
{
    const ADMIN_RESOURCE = 'IXPF_Syncer::webhook_list';

    public function __construct(
        Context $context,
        protected ResourceConnection $resourceConnection,
        protected CollectionFactory $collectionFactory,
        protected Filter $filter,
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
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $selectedItems = $collection->getItems();

            if (!empty($selectedItems)) {
                foreach ($selectedItems as $webhookJob) {

                    $connection->update(
                        $tableName,
                        ['trigger_status' => 'processing'],
                        ['entity_id = ?' => $webhookJob->getEntityId()]
                    );

                    $webhookResponse = $this->webhookHelper->callWebHook($webhookJob->getData());
                    if ($webhookResponse) {
                        $this->webhookHelper->updateJob($webhookJob->getData(), $webhookResponse);
                    }
                }

                $this->messageManager->addSuccessMessage(__('Webhook job has been set to retry.'));
            } else {
                $this->messageManager->addErrorMessage(__('Invalid webhook IDs.'));
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