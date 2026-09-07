<?php
namespace Webkul\AjaxContactForm\Logger;

use Magento\Framework\Logger\Handler\Base as BaseHandler;
use Monolog\Logger;

class Handler extends BaseHandler
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/contact_form.log';

    /**
     * @var int
     */
    protected $loggerType = Logger::DEBUG;
}
