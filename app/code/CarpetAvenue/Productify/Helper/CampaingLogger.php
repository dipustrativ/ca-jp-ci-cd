<?php

namespace CarpetAvenue\Productify\Helper;

class CampaingLogger
{
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_INFO = 'INFO';
    const LEVEL_DEBUG = 'DEBUG';
    const DIRECTORY = BP . '/var/log/carpetavenue/productify/Campaign/';

    private function writeLog($level, $message): void
    {
        // Create directory if not exists
        //$logDir = BP . '/var/log/carpetavenue/productify';
        $logDir = get_called_class()::DIRECTORY;
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // File name based on today's date
        $date = date('Y-m-d');
        $fileName = "campaign_cache_clean_$date.log";
        $filePath = $logDir . '/' . $fileName;

        // Format log entry with timestamp, level, and message
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;

        // Append to log file
        file_put_contents($filePath, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function error($message): void
    {
        $this->writeLog(self::LEVEL_ERROR, $message);
    }

    public function warning($message): void
    {
        $this->writeLog(self::LEVEL_WARNING, $message);
    }

    public function info($message): void
    {
        $this->writeLog(self::LEVEL_INFO, $message);
    }

    public function debug($message): void
    {
        $this->writeLog(self::LEVEL_DEBUG, $message);
    }

    // Backward compatibility with original log method
    public function log($message): void
    {
        $this->info($message);
    }
}