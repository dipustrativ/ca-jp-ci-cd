<?php
namespace CarpetAvenue\Currency\Cron;

class UpdateDaily{
    protected \CarpetAvenue\Currency\Api\DailyManagerInterface $dailyManager;
    public function __construct(
        \CarpetAvenue\Currency\Api\DailyManagerInterface $dailyManager  
    ){
        $this->dailyManager = $dailyManager;
    }
    public function execute(){
        $this->dailyManager->setDailyIncremental();
    }
}