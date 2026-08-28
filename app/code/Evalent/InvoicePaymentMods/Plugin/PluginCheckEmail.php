<?php
/**
 * Created by PhpStorm.
 * User: bassam
 * Date: 2018-10-26
 * Time: 13:22
 */

namespace Evalent\InvoicePaymentMods\Plugin;

use Magento\Checkout\Model\Session;

/**
 * Class PluginCheckEmail
 * @package Evalent\InvoicePaymentMods\Plugin
 */
class PluginCheckEmail //Bound to Evalent/InvoicePaymentMethod/etc/di.xml
{
    /**
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * PluginCheckEmail constructor.
     * @param Session $checkoutSession
     */
    public function __construct(Session $checkoutSession)
    {
        $this->_checkoutSession = $checkoutSession;
        //Get access to the Session.
    }

    /**
     * @param $subject
     * @param $customerEmail
     * @param null $websiteId
     */
    public function beforeIsEmailAvailable($subject, $customerEmail, $websiteId = null)
    {
        if ($customerEmail) {
            $this->_checkoutSession->setCustomerEmail($customerEmail);
        }
    }
}