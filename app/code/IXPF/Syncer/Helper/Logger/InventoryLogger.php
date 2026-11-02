<?php

namespace IXPF\Syncer\Helper\Logger;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use IXPF\Syncer\Helper\Logger\BaseLogger;
use Throwable;

/**
 * Log for the MSI inventory endpoints (transfer, assign, unassign, source item status).
 *
 * Unlike the other IXPF loggers, every write here is best effort: these endpoints move
 * stock for IXPF, and a log that cannot be written (folder not creatable, disk full, two
 * first calls racing to create the folder) must never turn a stock change into a 500.
 * BaseLogger::write() creates the folder outside its own try block, and Magento turns
 * the mkdir() warning into an exception, so it is caught here instead.
 */
class InventoryLogger extends AbstractHelper
{
    public const CORE_LOG_FOLDER       = BP.'/var/log/ixpf/inventory';
    public const CORE_LOG_FILE         = 'inventory.log';
    public const CORE_LOG_FILE_DAILY   = 'inventory';

    public function __construct(
        Context $context,
        private readonly BaseLogger $mLogger
    ) {
        parent::__construct($context);
    }

    /**
     * Logger Write
     *
     * @param mixed $message
     * @param string $type
     * @return bool
     */
    public function write($message, string $type = "info"): bool
    {
        $folder = self::CORE_LOG_FOLDER;
        $file   = self::CORE_LOG_FILE;
        return $this->safely(fn() => $this->mLogger->write($message, $type, $folder, $file));
    }

    /**
     * Logger Write
     *
     * @param mixed $exception
     * @param string $exceptionTitle
     * @return bool
     */
    public function writeException($exception, string $exceptionTitle = ''): bool
    {
        $folder = BaseLogger::EXCEPTION_FOLDER;
        $file   = self::CORE_LOG_FILE;
        return $this->safely(fn() => $this->mLogger->writeException($exception, $exceptionTitle, $folder, $file));
    }

    /**
     * Logger Write
     *
     * @param mixed $message
     * @param string $type
     * @return bool
     */
    public function writeDaily($message, string $type = "info"): bool
    {
        $folder = self::CORE_LOG_FOLDER;
        $file   = self::CORE_LOG_FILE_DAILY.$this->mLogger->logFileByDate();
        return $this->safely(fn() => $this->mLogger->write($message, $type, $folder, $file));
    }

    /**
     * Logger Write
     *
     * @param mixed $exception
     * @param string $exceptionTitle
     * @return bool
     */
    public function writeExceptionDaily($exception, string $exceptionTitle = ''): bool
    {
        $folder = BaseLogger::EXCEPTION_FOLDER;
        $file   = self::CORE_LOG_FILE_DAILY.$this->mLogger->logFileByDate();
        return $this->safely(fn() => $this->mLogger->writeException($exception, $exceptionTitle, $folder, $file));
    }

    /**
     * Runs a log write and reports failure as false instead of throwing.
     *
     * @param callable $write
     * @return bool
     */
    private function safely(callable $write): bool
    {
        try {
            return (bool)$write();
        } catch (Throwable $e) {
            return false;
        }
    }
}
