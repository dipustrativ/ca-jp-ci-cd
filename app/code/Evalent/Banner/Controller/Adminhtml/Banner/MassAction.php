<?php

declare(strict_types=1);

namespace Evalent\Banner\Controller\Adminhtml\Banner;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Evalent\Banner\Controller\Adminhtml\Banner;
use Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory;
use Exception;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Ui\Component\MassAction\Filter;

class MassAction extends Banner
{

    /**
     * @var \Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var \Magento\Ui\Component\MassAction\Filter
     */
    private $filter;

    private $successMessage;

    private $errorMessage;

    /**
     * @var \Evalent\Banner\Api\BannerRepositoryInterface
     */
    protected $bannerRepository;


    public function __construct(
        CollectionFactory $collectionFactory,
        Filter $filter,
        Context $context,
        BannerRepositoryInterface $bannerRepository,
        Registry $coreRegistry,
        $successMessage,
        $errorMessage
    ) {
        parent::__construct($context, $coreRegistry);
        $this->collectionFactory = $collectionFactory;
        $this->filter = $filter;
        $this->successMessage = $successMessage;
        $this->errorMessage = $errorMessage;
        $this->bannerRepository = $bannerRepository;
    }

    /**
     * Dispatch request
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     */
    public function execute()
    {
        try {
            $bannerCollection = $this->collectionFactory->create();
            /** @var \Evalent\Banner\Model\ResourceModel\Banner\Collection $collection */
            $collection = $this->filter->getCollection($bannerCollection);
            $collection->addFieldToSelect('*');
            $collectionSize = $collection->getSize();

            foreach ($collection as $banner) {
                $this->massAction($banner);
            }
            $this->messageManager->addSuccessMessage(__($this->successMessage, $collectionSize));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (Exception $e) {
            $this->messageManager->addExceptionMessage($e, __($this->errorMessage));
        }
        $redirectResult = $this->resultRedirectFactory->create();
        $redirectResult->setPath('*/*/index');
        return $redirectResult;
    }
}