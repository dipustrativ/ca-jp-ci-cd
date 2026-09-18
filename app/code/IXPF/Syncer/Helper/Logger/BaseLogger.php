<?php

namespace IXPF\Syncer\Helper\Logger;
use Monolog\Logger;
use Magento\Framework\App\Helper\AbstractHelper;
use Monolog\Handler\StreamHandler;

class BaseLogger extends AbstractHelper
{
    const FILE   = 'ixpf.log';
    const FOLDER = BP . '/var/log/ixpf';
    const EXCEPTION_FOLDER = BP . '/var/log/ixpf/exception';

    /**
     * Logger Write
     *
     * @param mixed $message
     * @param string $type
     * @param string $folder
     * @param string $file
     * @return bool
     */
    /**
     * Severity name -> Monolog level.
     *
     * Every caller in this module passes a NAME ('error', 'warning', ...), while
     * the switch this replaces compared against Monolog's integer constants.
     * Under PHP 8 'error' == 400 is false, so every branch missed and the whole
     * module logged at INFO — including the reservation and webhook failures.
     * Grepping production for an error level found nothing because nothing had
     * ever been written at one.
     */
    private const LEVELS = [
        'emergency' => Logger::EMERGENCY,
        'alert'     => Logger::ALERT,
        'critical'  => Logger::CRITICAL,
        'error'     => Logger::ERROR,
        'warning'   => Logger::WARNING,
        'notice'    => Logger::NOTICE,
        'info'      => Logger::INFO,
        'debug'     => Logger::DEBUG,
    ];

    /**
     * Resolve a caller-supplied severity to a Monolog level.
     *
     * Accepts a name or an integer constant — older callers pass both.
     */
    private function resolveLevel($type): int
    {
        if (is_int($type) || ctype_digit((string)$type)) {
            return (int)$type;
        }

        return self::LEVELS[strtolower(trim((string)$type))] ?? Logger::INFO;
    }

    public function write($message, $type = 'info', string $folder = self::FOLDER, string $file = self::FILE): bool
    {
        if (!file_exists($folder)) {
            mkdir($folder, 0777, true);
        }

        $filePath = $folder . '/' . $file;

        try {
            $logger = new Logger('ixpf_logger');
            $logger->pushHandler(new StreamHandler($filePath, Logger::DEBUG));

            $logger->log($this->resolveLevel($type), print_r($message, true));
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Logger Write Exception
     *
     * @param \Throwable $exception
     * @param string $exceptionTitle
     * @param string $folder
     * @param string $file
     * @return bool
     */
    public function writeException(\Throwable $exception, string $exceptionTitle = '', string $folder = self::EXCEPTION_FOLDER, string $file = self::FILE): bool
    {
        if (!file_exists($folder)) {
            mkdir($folder, 0777, true);
        }

        $filePath = $folder . '/' . $file;

        try {
            $logger = new Logger('ixpf_exception_logger');
            $logger->pushHandler(new StreamHandler($filePath, Logger::ERROR));

            $message = "Exception: $exceptionTitle\n";
            $message .= "Message: " . $exception->getMessage() . "\n";
            $message .= "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n";
            $message .= "Trace: " . $exception->getTraceAsString();

            $logger->error($message);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @param string $format Date Format
     * @return string
     */
    public function logFileByDate(string $format = 'Y-m-d'): string
    {
        return '-' . date($format) . '.log';
    }
}
