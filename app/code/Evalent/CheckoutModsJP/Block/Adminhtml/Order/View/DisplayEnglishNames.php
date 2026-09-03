<?php

namespace Evalent\CheckoutModsJP\Block\Adminhtml\Order\View;

use Magento\Sales\Model\OrderRepository;
use Magento\Framework\App\Request\Http;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * class DisplayEnglishNames
 */
class DisplayEnglishNames extends Template
{
    /**
     * @var \Magento\Sales\Model\OrderRepository
     */
    protected $orderRepository;

    /**
     * @var \Magento\Framework\App\Request\Http;
     */
    private $request;

    /**
     * @var  \Magento\Backend\Block\Template\Context
     */
    private $context;
    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    private  $order;

    /**
     *  DisplayEnglishNames constructor
     *
     * @param \Magento\Sales\Model\OrderRepository $orderRepository
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Magento\Backend\Block\Template\Context $context
     * @param array $data
     */
    public function __construct(
        OrderRepository $orderRepository,
        Http $request,
        Context $context,
        $data = []
    )
    {
        $this->orderRepository = $orderRepository;
        $this->request = $request;
        parent::__construct($context, $data);

    }

    /**
     * @return bool
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
   protected function getEnglishNames()
    {
        $order_id = $this->request->getParam('order_id');
        $order = $this->orderRepository->get($order_id);
        $this->order = $order;
        return     $order->getShippingFirstnameJp()
                || $order->getShippingLastnameJp()
                || $order->getBillingLastnameJp()
                || $order->getBillingFirstnameJp();
    }

    /**
     * @return string
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getHtml(): string
    {
        if (!$this->getEnglishNames()) {
            return '';
        }

        $html = "
                <section class=\"admin__page-section\">
                    <div class=\"admin__page-section-title\">
                        <span class=\"title\">" .
                        __('English Names for Jp Store'). "</span>
                    </div>
                    <div class=\"admin__page-section-content\">
                        <div class=\"admin__page-section-item order-billing-address\">
                            <div class=\"admin__page-section-item-title\">
                                <span class=\"title\">" . __('English Billing Address Names'). "</span>
                            </div>
                                <address class=\"admin__page-section-item-content\">
                                    <b>FirstName: </b>  ". $this->order->getBillingFirstnameJp(). "<br>
                                    <b>LastName: </b>  ". $this->order->getBillingLastnameJp(). "<br>
                                </address>
                        </div>
                        <div class=\"admin__page-section-item order-shipping-address\">
                            <div class=\"admin__page-section-item-title\">
                                <span class=\"title\">" . __('English Shipping Address Names'). "</span>
                            </div>
                                <address class=\"admin__page-section-item-content\">
                                    <b>FirstName: </b>  ". $this->order->getShippingFirstnameJp(). "<br>
                                    <b>LastName: </b>  ". $this->order->getShippingLastnameJp(). "<br>
                                </address>
                            </div>
                        </div>
                </section>";

        return $html;
    }
}
