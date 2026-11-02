<?php
declare(strict_types=1);

namespace IXPF\Syncer\Helper\Logger;

use IXPF\Syncer\Helper\Logger\BaseLogger;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Monolog\Logger;

class ReviewLogger extends AbstractHelper
{
    const CORE_LOG_FOLDER = BP . '/var/log/ixpf/review';
    const CORE_LOG_FILE = 'review.log';
    const CORE_LOG_FILE_DAILY = 'review';

    public function __construct(
        Context                     $context,
        private readonly BaseLogger $mLogger
    )
    {
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
        $file = self::CORE_LOG_FILE;
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
        $file = self::CORE_LOG_FILE;
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
        $file = self::CORE_LOG_FILE_DAILY . $this->mLogger->logFileByDate();
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
        $file = self::CORE_LOG_FILE_DAILY . $this->mLogger->logFileByDate();
        $this->mLogger->writeException($exception, $exceptionTitle, $folder, $file);
        return true;
    }

    /**
     * A failure in both files at once: one summary line in the daily activity log, the full
     * trace in the exception log.
     *
     * writeExceptionDaily on its own writes only to the exception folder, which leaves the
     * activity log showing work that starts and never finishes - the reason sits in a second
     * file nobody has open. Use this wherever a Throwable is caught mid-flow.
     *
     * @param \Throwable $exception
     * @param string $context
     * @return bool
     */
    public function writeFailureDaily(\Throwable $exception, string $context = ''): bool
    {
        $summary = $context === ''
            ? $exception->getMessage()
            : $context . ': ' . $exception->getMessage();

        $this->writeDaily($summary, (string)Logger::ERROR);
        $this->writeExceptionDaily($exception, $context);

        return true;
    }
}
