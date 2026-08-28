<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Osc
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\Osc\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Validator\Exception;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Address;
use Mageplaza\Osc\Helper\Data as OscHelper;

/**
 * Class CreditMemoEmail
 * @package Mageplaza\Osc\Observer
 */
class CreditMemoEmail extends OrderEmail {}
