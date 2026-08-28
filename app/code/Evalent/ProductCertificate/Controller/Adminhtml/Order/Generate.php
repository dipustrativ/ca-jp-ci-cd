<?php

namespace Evalent\ProductCertificate\Controller\Adminhtml\Order;

use Evalent\ProductCertificate\Helper\Certificate;
use Exception;
use Magento\Backend\App\Action;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\OrderRepository;
use Mpdf\Mpdf;

class Generate extends Action
{
    /** @var OrderRepository $_orderRepository */
    private $_orderRepository;

    /** @var Certificate $_certificate */
    private $_certificate;

    /** @var Filesystem $_filesystem */
    private $_filesystem;

    /** @var DirectoryList $_directoryList */
    private $_directoryList;

    /** @var RawFactory $_resultFactory */
    private $_resultFactory;

    /** @var FileFactory $_fileFactory */
    private $_fileFactory;

    /**
     * Generate constructor.
     * @param Action\Context $context
     * @param OrderRepository $orderRepository
     * @param ProductRepository $productRepository
     * @param Filesystem $filesystem
     * @param DirectoryList $directoryList
     * @param Certificate $certificate
     * @param RawFactory $resultRawFactory
     * @param FileFactory $fileFactory
     */
    public function __construct(
        Action\Context $context,
        OrderRepository $orderRepository,
        ProductRepository $productRepository,
        Filesystem $filesystem,
        DirectoryList $directoryList,
        Certificate $certificate,
        RawFactory $resultRawFactory,
        FileFactory $fileFactory
    ) {
        $this->_orderRepository = $orderRepository;
        $this->_certificate = $certificate;
        $this->_filesystem = $filesystem;
        $this->_directoryList = $directoryList;
        $this->_resultFactory = $resultRawFactory;
        $this->_fileFactory = $fileFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $params = $this->getRequest()->getParams();
        if (!isset($params['id'])) {
            return $this->getResponse()
                 ->setStatusCode(Http::STATUS_CODE_400)
                 ->setContent('Error');
        }

        try {
            $order = $this->_orderRepository->get($params['id']);
            $certificates = $this->_generateCertificates($order);
            try {
                $this->_serve($certificates, $order->getIncrementId());
            } catch (Exception $e) {
                return $this->getResponse()
                     ->setStatusCode(Http::STATUS_CODE_500)
                     ->setContent($e->getMessage());
            }
        } catch (InputException|NoSuchEntityException $e) {
            return $this->getResponse()
                 ->setStatusCode(Http::STATUS_CODE_404)
                 ->setContent('Error');
        }
    }

    /**
     * @param \Mpdf\Mpdf $certificates
     * @param string     $name
     *
     * @return void
     * @throws \Mpdf\MpdfException
     */
    private function _serve(Mpdf $certificates, string $name="certificates")
    {
        $certificates->output($name . '.pdf', "D");
    }

    /**
     * @param OrderInterface $order
     * @return Mpdf
     * @throws NoSuchEntityException
     */
    private function _generateCertificates(OrderInterface $order)
    {
        if (!isset($order)) {
            throw new NoSuchEntityException();
        }

        return $this->_certificate->create($order, true)['pdf'];
    }
}
