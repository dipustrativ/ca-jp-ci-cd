<?php

declare(strict_types=1);

namespace Evalent\Banner\Controller\Adminhtml\Banner;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class InlineEdit extends Action
{

    protected $jsonFactory;

    /**
     * @var \Evalent\Banner\Api\BannerRepositoryInterface
     */
    private $bannerRepository;

    /**
     * @param \Evalent\Banner\Api\BannerRepositoryInterface    $bannerRepository
     * @param \Magento\Backend\App\Action\Context              $context
     * @param \Magento\Framework\Controller\Result\JsonFactory $jsonFactory
     */
    public function __construct(
        BannerRepositoryInterface $bannerRepository,
        Context $context,
        JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->bannerRepository = $bannerRepository;
    }

    /**
     * Inline edit action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Json $resultJson */
        $resultJson = $this->jsonFactory->create();
        $error = false;
        $messages = [];
        
        if ($this->getRequest()->getParam('isAjax')) {
            $postItems = $this->getRequest()->getParam('items', []);
            if (!count($postItems)) {
                $messages[] = __('Please correct the data sent.');
                $error = true;
            } else {
                foreach (array_keys($postItems) as $modelid) {
                    /** @var \Evalent\Banner\Model\Banner $model */
                    try {
                        $model = $this->bannerRepository->get($modelid);
                        $model->setData(array_merge($model->getData(), $postItems[$modelid]));
                        $this->bannerRepository->save($model);
                    } catch (Exception $e) {
                        $messages[] = "[Banner ID: {$modelid}]  {$e->getMessage()}";
                        $error = true;
                    }
                }
            }
        }
        
        return $resultJson->setData([
            'messages' => $messages,
            'error' => $error
        ]);
    }
}
