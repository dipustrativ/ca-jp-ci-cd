<?php

namespace CarpetAvenue\Proforma\Controller\Adminhtml\Order;

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
    public function __construct(
        protected Action\Context $context,
        protected OrderRepository $orderRepository,
        protected ProductRepository $productRepository,
        protected Filesystem $filesystem,
        protected DirectoryList $directoryList,
        protected RawFactory $resultRawFactory,
        protected FileFactory $fileFactory,
        protected \CarpetAvenue\Proforma\Model\ProformaRepository $proformaRepository
    ) {
        $this->orderRepository = $orderRepository;
        $this->filesystem = $filesystem;
        $this->directoryList = $directoryList;
        $this->resultFactory = $resultRawFactory;
        $this->fileFactory = $fileFactory;
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
            $order = $this->orderRepository->get($params['id']);
            $certificates = $this->proformaRepository->generate($order);

            return $this->getResponse()
                ->setStatusCode(Http::STATUS_CODE_500)
                ->setContent('not implemented');
        }
        catch (InputException|NoSuchEntityException $e) {
            return $this->getResponse()
                ->setStatusCode(Http::STATUS_CODE_404)
                ->setContent('Error');
        }
    }
}
