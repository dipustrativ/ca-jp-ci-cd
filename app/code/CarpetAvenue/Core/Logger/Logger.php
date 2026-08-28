<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Core
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Core\Logger;

class Logger
{
    const LEVEL_ERROR   = 'ERROR';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_INFO    = 'INFO';
    const LEVEL_DEBUG   = 'DEBUG';

    /** Root directory for all CarpetAvenue logs (absolute). */
    const BASE_LOG_DIR = '/var/log/carpetavenue';

    private string $resolvedDir;
    private string $logFile;
    private bool $dayWise;

    public function __construct(
        string $logDir  = '',
        string $logFile = 'carpetavenue',
        bool   $dayWise = true
    ) {
        $base               = BP . self::BASE_LOG_DIR;
        $sub                = $logDir !== '' ? '/' . ltrim($logDir, '/') : '';
        $this->resolvedDir  = rtrim($base . $sub, '/');
        $this->logFile      = $logFile;
        $this->dayWise      = $dayWise;
    }

    // ── Public API ──────────────────────────────────────────────────────────

    public function error(mixed $message): void
    {
        $this->writeLog(self::LEVEL_ERROR, $message);
    }

    public function warning(mixed $message): void
    {
        $this->writeLog(self::LEVEL_WARNING, $message);
    }

    public function info(mixed $message): void
    {
        $this->writeLog(self::LEVEL_INFO, $message);
    }

    public function debug(mixed $message): void
    {
        $this->writeLog(self::LEVEL_DEBUG, $message);
    }

    /** Backward-compatibility alias for info(). */
    public function log(mixed $message): void
    {
        $this->info($message);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function writeLog(string $level, mixed $message): void
    {
        $this->ensureDirectory();

        if (!is_string($message)) {
            $message = print_r($message, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $logEntry  = "[$timestamp] [$level] $message" . PHP_EOL;

        file_put_contents($this->resolveFilePath(), $logEntry, FILE_APPEND | LOCK_EX);
    }

    private function resolveFilePath(): string
    {
        $suffix = $this->dayWise ? '_' . date('Y-m-d') : '';
        return $this->resolvedDir . '/' . $this->logFile . $suffix . '.log';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->resolvedDir)) {
            mkdir($this->resolvedDir, 0755, true);
        }
    }
}
