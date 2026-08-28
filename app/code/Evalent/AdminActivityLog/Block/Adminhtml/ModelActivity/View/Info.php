<?php

namespace Evalent\AdminActivityLog\Block\Adminhtml\ModelActivity\View;

use Evalent\AdminActivityLog\Api\ModelActivityRepositoryInterface;
use Magento\Framework\View\Element\Template;

class Info extends Template
{

    const RESOURCE_TO_MODEL_NAME = [
        'Magento\Catalog\Model\ResourceModel\Product\Interceptor' => "Product",
        'Magento\Sales\Model\ResourceModel\Order' => "Order",
        'Magento\Sales\Model\ResourceModel\Order\Invoice' => "Invoice",
        'Magento\Sales\Model\ResourceModel\Order\Invoice\Item' => "Invoice Item",
        'Magento\Sales\Model\ResourceModel\Order\Item' => "Order Item",
        'Magento\Quote\Model\ResourceModel\Quote\Item' => "Quote Item",
        'Magento\Sales\Model\ResourceModel\Order\Creditmemo\Item' => "Creditmemo Item",
        'Magento\Catalog\Model\ResourceModel\Attribute' => "Attribute",
        'Magento\User\Model\ResourceModel\User' => "User",
        'Magento\Authorization\Model\ResourceModel\Role' => "User Role",
        'Magento\SalesRule\Model\ResourceModel\Rule' => "Sales Rule",
        'Magento\Tax\Model\ResourceModel\Calculation\Rule' => "Tax Rule",
        'Magento\CatalogInventory\Model\ResourceModel\Stock\Item' => "Stock Item",
        'Magento\Catalog\Model\ResourceModel\Category' => "Category",
        'Magento\Customer\Model\ResourceModel\Address' => "Customer Address",
        'Magento\Quote\Model\ResourceModel\Quote\Address' => "Quote Address",
        'Magento\Customer\Model\ResourceModel\Group' => "Customer Group",
        'Magento\Customer\Model\ResourceModel\Customer\Interceptor' => "Customer",
        'Magento\Eav\Model\ResourceModel\Entity\Attribute\Set' => "Product Attribute Set",
        'Magento\Indexer\Model\ResourceModel\Mview\View\State' => "Indexer State",
        'Magento\Newsletter\Model\ResourceModel\Subscriber' => "Newsletter Subscriber",
        'Magento\Sales\Model\ResourceModel\Order\Shipment' => "Order Shipment",
        'Magento\Store\Model\ResourceModel\Website' => "Website",
        'Magento\Quote\Model\ResourceModel\Quote\Payment' => "Quote Payment",
        'Magento\Sales\Model\ResourceModel\Order\Payment' => "Order Payment"
    ];

    private $activityId;

    /**
     * @var \Evalent\AdminActivityLog\Api\ModelActivityRepositoryInterface
     */
    private $activityRepository;

    /**
     * @var \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    private $activity;

    /**
     * Info constructor.
     *
     * @param \Evalent\AdminActivityLog\Api\ModelActivityRepositoryInterface $activityRepository
     * @param \Magento\Framework\View\Element\Template\Context               $context
     * @param array                                                          $data
     */
    public function __construct(
        ModelActivityRepositoryInterface $activityRepository,
        \Magento\Framework\View\Element\Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->activityRepository = $activityRepository;
        $this->activityId = $this->_request->getParam('modelactivity_id');
    }

    /**
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     */
    public function getActivity()
    {
        if (!$this->activity) {
            try {
                $this->activity
                    = $this->activityRepository->getById($this->activityId);
            } catch (\Exception $e) {
                $this->_logger->error("Couldn't find activity by id. "
                    . $this->activityId);
            }
        }
        return $this->activity;
    }

    public function getOldValues()
    {
        $changes = json_decode($this->activity->getEditedAttributes(), true);
        $oldValues = [];
        if (sizeof($changes)) {
            foreach ($changes as $key => $change) {
                $data = is_array($change['old_data']) ? json_encode($change['old_data']) : $change['old_data'];
                $oldValues[] = '<b>' . $key . "</b><br/>" . $data;
            }
        }
        return implode('<br/>', $oldValues);
    }

    public function getNewValues()
    {
        $changes = json_decode($this->activity->getEditedAttributes(), true);
        $oldValues = [];
        if (sizeof($changes)) {
            foreach ($changes as $key => $change) {
                $data = is_array($change['new_data']) ? json_encode($change['new_data']) : $change['new_data'];
                $oldValues[] = '<b>' . $key . "</b><br/>" . $data;
            }
        }
        return implode('<br/>', $oldValues);
    }

    /**
     * @return mixed|string
     */
    public function getModelName()
    {
        if (isset(self::RESOURCE_TO_MODEL_NAME[$this->getActivity()->getModelType()])) {
            return self::RESOURCE_TO_MODEL_NAME[$this->getActivity()->getModelType()];
        }
        return "";
    }
}
