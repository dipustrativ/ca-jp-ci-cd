<?php

namespace Evalent\MatrixRateMods\Controller\Adminhtml\Update;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Json\Helper\Data;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\OrderRepository;
use Psr\Log\LoggerInterface;

class Index extends Action
{
    protected $_resultPageFactory;
    protected $_json;

    /**
     * @var LoggerInterface
     */
    private $_logger;

    /**
     * @var OrderRepository
     */
    private $_orderRepository;

    /**
     * @var \Evalent\MatrixRateMods\Helper\Data
     */
    private $_data;

    /**
     * Constructor
     *
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param Data $json
     * @param OrderRepository $orderRepository
     * @param \Evalent\MatrixRateMods\Helper\Data $data
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        Data $json,
        OrderRepository $orderRepository,
        \Evalent\MatrixRateMods\Helper\Data $data,
        LoggerInterface $logger
    ) {
        $this->_resultPageFactory = $resultPageFactory;
        $this->_json = $json;
        $this->_logger = $logger;
        $this->_orderRepository = $orderRepository;
        $this->_data = $data;
        parent::__construct($context);
    }

    /**
     * Execute view action
     *
     * @return ResultInterface
     */
    public function execute()
    {
        try {
            $orderId = $this->_request->getParam('order');
            $method = $this->_request->getParam('method');
            $this->updateShippingMethod($orderId, $method);
            return $this->json(['status' => 'success', 'message' => 'OK']);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            return $this->json(['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->_logger->critical($e);
            return $this->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * @param array $response
     * @return ResultInterface
     */
    public function json(array $response)
    {
        return $this->getResponse()->representJson(
            $this->_json->jsonEncode($response)
        );
    }

    /**
     * @return bool
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function updateShippingMethod($orderId, $method)
    {
        if (isset($this->_data->getMethods()[$method]) && $description = $this->_data->getMethods()[$method]) {
            $order = $this->_orderRepository->get($orderId);
            $order->setShippingMethod($method);
            $order->setShippingDescription($description);
            $extensionAttributes = $order->getExtensionAttributes();
            if ($order->getIsNotVirtual() && $extensionAttributes && $extensionAttributes->getShippingAssignments()) {
                $shippingAssignments = $extensionAttributes->getShippingAssignments();
                if (!empty($shippingAssignments)) {
                    $shippingAssignment = array_shift($shippingAssignments);
                    $shipping = $shippingAssignment->getShipping();
                    if ($shipping->getMethod() === $method) {
                        return false;
                    }
                    $shipping->setMethod($method);
                    $shippingAssignment->setShipping($shipping);
                    array_unshift($shippingAssignments, $shippingAssignment);
                    $extensionAttributes->setShippingAssignments($shippingAssignments);
                    $order->setExtensionAttributes($extensionAttributes);
                }
            }
            $this->_orderRepository->save($order);
            return true;
        }

        return false;
    }
}
