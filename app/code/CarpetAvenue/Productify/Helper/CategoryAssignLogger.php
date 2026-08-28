<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Helper;

/**
 * Dedicated daily logger for the color/shape category assignment command.
 *
 * Mirrors Helper\Logger but writes to its own daily file
 * (category_assign_<Y-m-d>.log) so this command's output is kept separate
 * from the general productify sync log.
 */
class CategoryAssignLogger
{
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_INFO = 'INFO';
    const LEVEL_DEBUG = 'DEBUG';
    const DIRECTORY = BP . '/var/log/carpetavenue/productify/';

    private function writeLog($level, $message): void
    {
        $logDir = self::DIRECTORY;
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // File name based on today's date
        $date = date('Y-m-d');
        $fileName = "category_assign_$date.log";
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

    public function log($message): void
    {
        $this->info($message);
    }
}
