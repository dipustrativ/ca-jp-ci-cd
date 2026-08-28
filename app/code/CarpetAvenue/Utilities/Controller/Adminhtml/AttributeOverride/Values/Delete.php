<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Controller\Adminhtml\AttributeOverride\Values;

use CarpetAvenue\Utilities\Model\AttributeOverrideValueFactory;
use CarpetAvenue\Utilities\Model\ResourceModel\AttributeOverrideValue as ResourceModel;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Utilities::attribute_override';

    public function __construct(
        Context $context,
        private readonly AttributeOverrideValueFactory $modelFactory,
        private readonly ResourceModel $resourceModel,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id       = (int) $this->getRequest()->getParam('id');
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('utilities/attributeoverride_values/index');

        if ($id <= 0) {
            $this->messageManager->addErrorMessage(__('Invalid record ID.'));
            return $redirect;
        }

        try {
            $model = $this->modelFactory->create();
            $this->resourceModel->load($model, $id);

            if (!$model->getId()) {
                $this->messageManager->addErrorMessage(__('Record not found.'));
                return $redirect;
            }

            $this->resourceModel->delete($model);
            $this->messageManager->addSuccessMessage(__('Intended value record deleted.'));

        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Delete failed: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
