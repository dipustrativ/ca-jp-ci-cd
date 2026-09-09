<?php
namespace CarpetAvenue\Currency\Api;

interface DailyManagerInterface{
    public function setDailyFromGenesis();
    public function setDailyIncremental();
}