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
    public function write($message, string $type = Logger::INFO, string $folder = self::FOLDER, string $file = self::FILE): bool
    {
        if (!file_exists($folder)) {
            mkdir($folder, 0777, true);
        }

        $filePath = $folder . '/' . $file;

        try {
            $logger = new Logger('ixpf_logger');
            $logger->pushHandler(new StreamHandler($filePath, Logger::DEBUG));

            switch ($type) {
                case Logger::EMERGENCY:
                    $logger->emergency(print_r($message, true));
                    break;
                case Logger::ALERT:
                    $logger->alert(print_r($message, true));
                    break;
                case Logger::CRITICAL:
                    $logger->critical(print_r($message, true));
                    break;
                case Logger::ERROR:
                    $logger->error(print_r($message, true));
                    break;
                case Logger::WARNING:
                    $logger->warning(print_r($message, true));
                    break;
                case Logger::NOTICE:
                    $logger->notice(print_r($message, true));
                    break;
                case Logger::DEBUG:
                    $logger->debug(print_r($message, true));
                    break;
                default:
                    $logger->info(print_r($message, true));
            }
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
