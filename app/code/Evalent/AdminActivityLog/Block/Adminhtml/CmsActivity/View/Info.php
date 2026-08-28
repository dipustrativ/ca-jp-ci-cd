<?php

namespace Evalent\AdminActivityLog\Block\Adminhtml\CmsActivity\View;

use Evalent\AdminActivityLog\Api\CmsActivityRepositoryInterface;
use Magento\Framework\View\Element\Template;
use Psr\Log\LoggerInterface;

class Info extends Template
{

    private $activityId;

    /**
     * @var \Evalent\AdminActivityLog\Api\CmsActivityRepositoryInterface
     */
    private $activityRepository;

    /**
     * @var \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     */
    private $activity;

    /**
     * Info constructor.
     *
     * @param \Evalent\AdminActivityLog\Api\CmsActivityRepositoryInterface $activityRepository
     * @param \Magento\Framework\View\Element\Template\Context               $context
     * @param array                                                          $data
     */
    public function __construct(
        CmsActivityRepositoryInterface $activityRepository,
        \Magento\Framework\View\Element\Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->activityRepository = $activityRepository;
        $this->activityId = $this->_request->getParam('cmsactivity_id');
    }


    /**
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
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
}