<?php
namespace CarpetAvenue\Currency\Cron;

class InsertAverageCurrencyRows{
    protected \CarpetAvenue\Currency\Api\AverageManagerInterface $averageManager;
    public function __construct(
        \CarpetAvenue\Currency\Api\AverageManagerInterface $averageManager  
    ){
        $this->averageManager = $averageManager;
    }
    public function execute(){
        $this->averageManager->insertAverageCurrencyRows();
    }
}