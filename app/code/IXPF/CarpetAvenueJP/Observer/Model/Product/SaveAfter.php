<?php

namespace IXPF\CarpetAvenueJP\Observer\Model\Product;

use Evalent\AdminActivityLog\Api\ModelActivityRepositoryInterface;
use Evalent\AdminActivityLog\Model\ModelActivityFactory;
use Evalent\AdminActivityLog\Observer\Model\SaveAfter as EvalentActivityLogObserver;
use Evalent\AdminActivityLog\Model\DiffCheckers\Model as ModelDiffChecker;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class SaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly RequestInterface          $request,
        protected ModelActivityFactory             $modelActivityFactory,
        protected ModelActivityRepositoryInterface $modelActivityRepository,
        protected ModelDiffChecker $modelDiffChecker
    ) {}

    public function execute(Observer $observer)
    {
        if ($this->request->getModuleName() !== "ixpfsyncer") {
            return;
        }

        $model = $observer->getData('object');
        switch ($model->getEventPrefix()) {
            case 'catalog_product':
            case 'catalog_category':
                $this->catalogModel($model);
                break;
            case 'cms_page':
            case 'cms_block':
                $this->cmsModel($model);
                break;
            case 'core_abstract':
            case 'cataloginventory_stock':
                break;
            default:
                $this->catalogModel($model);
                break;
        }

    }

    /**
     * @param \Magento\Catalog\Model\AbstractModel $model
     */
    private function catalogModel($model)
    {
        $activity = $this->modelActivityFactory->create();
        $actionType = 'Save';
        if ($model->isObjectNew()) {
            $actionType = 'New';
        }

        $changedAttributes = $this->modelDiffChecker->checkDifferences($model);
        if ($actionType == "New") {
            $changedAttributes = [];
            foreach ($model->getData() as $key => $value) {
                if (in_array($key, EvalentActivityLogObserver::IGNORE_ATTRIBUTES)) {
                    continue;
                }
                if ($value != "" && $value != null) {
                    $changedAttributes[$key] = [
                        'old_data' => "null",
                        'new_data' => $value,
                    ];
                }
            }
        }
        if (sizeof($changedAttributes) == 0) {
            return;
        }

        $idFieldName = $model->getIdFieldName();
        $activity->setActionType($actionType);
        $activity->setUsername("ixpf_syncer");
        $activity->setFullName("IXPF Integration");
        $activity->setModelEntityId($model->getData($idFieldName));
        $activity->setDatetime(date('Y-m-d H:i:s'));
        $activity->setModelType($model->getResourceName());
        $activity->setEditedAttributes(json_encode($changedAttributes));

        try {
            $this->modelActivityRepository->save($activity);
        } catch (\Exception $e) {

        }
    }

    /**
     * @param \Magento\Catalog\Model\AbstractModel $model
     */
    private function cmsModel(
        $model
    ) {

    }
}
