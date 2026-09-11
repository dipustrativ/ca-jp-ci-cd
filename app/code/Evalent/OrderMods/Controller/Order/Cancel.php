<?php

namespace Evalent\OrderMods\Controller\Order;

use Magento\Framework\App\Action\Action;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Controller\OrderInterface;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Controller\AbstractController\OrderLoaderInterface;

class Cancel extends Action implements OrderInterface
{
    protected $resultPageFactory;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var \Magento\Sales\Controller\AbstractController\OrderLoaderInterface
     */
    private $orderLoader;

    /**
     * @param Context                                                           $context
     * @param \Magento\Framework\View\Result\PageFactory                        $resultPageFactory
     * @param \Magento\Sales\Api\OrderRepositoryInterface                       $orderRepository
     * @param \Magento\Sales\Controller\AbstractController\OrderLoaderInterface $orderLoader
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        OrderRepositoryInterface $orderRepository,
        OrderLoaderInterface $orderLoader
    )
    {
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->orderLoader = $orderLoader;
    }

    /**
     * Action for cancel
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $orderId = $this->getRequest()->getParam('order_id');
        $result = $this->orderLoader->load($this->getRequest());
        if ($result instanceof ResultInterface) {
            return $result;
        }
        $resultRedirect = $this->resultRedirectFactory->create();

        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->orderRepository->get($orderId);
        if($order->canCancel()){
            $order->cancel();
            $order->addCommentToStatusHistory(__("This order was canceled by customer"));
            $this->orderRepository->save($order);
            $this->messageManager->addSuccess(__('Order has been canceled successfully.'));
        }else{
            $this->messageManager->addError(__('Order cannot be canceled.'));
        }
        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}