<?php

namespace IXPF\Syncer\Helper\Logger;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

class ReservationLogger extends AbstractHelper
{
    const CORE_LOG_FOLDER       = BP.'/var/log/ixpf/reservation';
    const CORE_LOG_FILE         = 'reservation.log';
    const CORE_LOG_FILE_DAILY   = 'reservation';

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
        $this->mLogger->write($message, $type, $folder, $file);
        return true;
    }

    /**
     * Logger Write
     *
     * @param \Throwable $exception
     * @param string $exceptionTitle
     * @return bool
     */
    public function writeException(\Throwable $exception, string $exceptionTitle = ''): bool
    {
        $folder = BaseLogger::EXCEPTION_FOLDER;
        $file   = self::CORE_LOG_FILE;
        $this->mLogger->writeException($exception, $exceptionTitle, $folder, $file);
        return true;
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
        $this->mLogger->write($message, $type, $folder, $file);
        return true;
    }

    /**
     * Logger Write
     *
     * @param \Throwable $exception
     * @param string $exceptionTitle
     * @return bool
     */
    public function writeExceptionDaily(\Throwable $exception, string $exceptionTitle = ''): bool
    {
        $folder = BaseLogger::EXCEPTION_FOLDER;
        $file   = self::CORE_LOG_FILE_DAILY.$this->mLogger->logFileByDate();
        $this->mLogger->writeException($exception, $exceptionTitle, $folder, $file);
        return true;
    }
}
