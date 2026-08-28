<?php

namespace Evalent\AdminActivityLog\Block\Adminhtml\ConfigActivity\View;

use Evalent\AdminActivityLog\Api\ConfigActivityRepositoryInterface;
use Magento\Framework\View\Element\Template;
use Psr\Log\LoggerInterface;

class Info extends Template
{

    private $activityId;

    /**
     * @var \Evalent\AdminActivityLog\Api\ConfigActivityRepositoryInterface
     */
    private $activityRepository;

    /**
     * @var \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
     */
    private $activity;

    private $returnChanges;

    private $section;

    private $baseSection;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Info constructor.
     *
     * @param \Psr\Log\LoggerInterface                                        $logger
     * @param \Evalent\AdminActivityLog\Api\ConfigActivityRepositoryInterface $activityRepository
     * @param \Magento\Framework\View\Element\Template\Context                $context
     * @param array                                                           $data
     */
    public function __construct(
        LoggerInterface $logger,
        ConfigActivityRepositoryInterface $activityRepository,
        \Magento\Framework\View\Element\Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->activityRepository = $activityRepository;
        $this->activityId = $this->_request->getParam('configactivity_id');
        $this->logger = $logger;
    }


    /**
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
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

    /**
     * Create HTML formatted string with all new and old values
     *
     * @return string
     */
    public function getEditedValues()
    {
        $changes = json_decode($this->activity->getEditedAttributes(), true);
        $this->returnChanges = [];
        $this->baseSection = $this->activity->getSection();
        $this->section = $this->baseSection;
        if ($changes) {
            $this->formatEditValues($changes);
        }
        $returnString = '';
        foreach ($this->returnChanges as $key => $returnChange) {
            if ($returnChange[0] == "" && $returnChange[1] == "") continue;
            $returnString .= '<b>' . $key . '</b><br/>' .  __('Old: ') . $returnChange[0] . '<br/>'
                . __('New: ') . $returnChange[1] . '<br/>';
        }
        return $returnString;
    }

    /**
     * Creates an array formated like:
     *
     * [ config/section/location1 => [ [0]  => (old value), [1] => (new value) ],
     * [ config/section/location2 => [ [0]  => (old value), [1] => (new value) ]
     * e.t.c
     *
     * @param $values
     */
    private function formatEditValues($values)
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $this->section .= "/" . $key;
                $this->formatEditValues($value);
            } else {
                $this->returnChanges[$this->section][$key] =  $value ;
            }
        }
        $this->section = $this->baseSection;
    }
}