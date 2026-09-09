<?php
namespace CarpetAvenue\Currency\Block\Adminhtml\Average;

class Unsynced extends \Magento\Backend\Block\Template{
    protected \CarpetAvenue\Currency\Api\AverageManagerInterface $averageManager;
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \CarpetAvenue\Currency\Api\AverageManagerInterface $averageManager,
        $data = [],
        $jsonHelper = null,
        $directoryHelper = null
    ){
        $this->averageManager = $averageManager;
        parent::__construct($context,$data,$jsonHelper,$directoryHelper);
    }

    public function getUnsyncedData(){
        return $this->averageManager->getUnsyncedData();
    }
}