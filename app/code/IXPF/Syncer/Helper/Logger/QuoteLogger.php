<?php

namespace IXPF\Syncer\Helper\Logger;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use IXPF\Syncer\Helper\Logger\BaseLogger;

class QuoteLogger extends AbstractHelper
{
    const CORE_LOG_FOLDER     = BP . '/var/log/ixpf/quote';
    const CORE_LOG_FILE       = 'quote.log';
    const CORE_LOG_FILE_DAILY = 'quote';

    public function __construct(
        Context $context,
        private readonly BaseLogger $mLogger
    ) {
        parent::__construct($context);
    }

    /**
     * @param mixed $message
     * @param string $type
     * @return bool
     */
    public function write($message, string $type = "info"): bool
    {
        $this->mLogger->write($message, $type, self::CORE_LOG_FOLDER, self::CORE_LOG_FILE);
        return true;
    }

    /**
     * @param mixed $message
     * @param string $type
     * @return bool
     */
    public function writeDaily($message, string $type = "info"): bool
    {
        $file = self::CORE_LOG_FILE_DAILY . $this->mLogger->logFileByDate();
        $this->mLogger->write($message, $type, self::CORE_LOG_FOLDER, $file);
        return true;
    }

    /**
     * @param \Throwable $exception
     * @param string $exceptionTitle
     * @return bool
     */
    public function writeExceptionDaily($exception, string $exceptionTitle = ''): bool
    {
        $file = self::CORE_LOG_FILE_DAILY . $this->mLogger->logFileByDate();
        $this->mLogger->writeException($exception, $exceptionTitle, BaseLogger::EXCEPTION_FOLDER, $file);
        return true;
    }
}
