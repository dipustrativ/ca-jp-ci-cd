<?php

declare(strict_types=1);

namespace Evalent\Banner\Controller\Adminhtml\Banner\Image;

use Evalent\Banner\Model\Banner\ImageUploader;
use Evalent\Banner\Model\UploaderPool;
use Magento\Backend\App\Action;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;

class UploadMobile extends Action
{
    public const ADMIN_RESOURCE = 'Evalent_Banner::save';

    /**
     * @var \Evalent\Banner\Model\UploaderPool
     */
    private $uploaderPool;

    /**
     * UploadMobile constructor.
     *
     * @param \Evalent\Banner\Model\UploaderPool $uploaderPool
     * @param \Magento\Backend\App\Action\Context $context
     */
    public function __construct(
        UploaderPool $uploaderPool,
        Action\Context $context
    ) {
        parent::__construct($context);
        $this->uploaderPool = $uploaderPool;
    }

    /**
     * Execute action based on request and return result
     *
     * Note: Request will be added as operation argument in future
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     * @throws \Magento\Framework\Exception\NotFoundException
     */
    public function execute()
    {
        try {
            $result = $this->uploaderPool->getUploader('image')->saveFileToTmpDir('mobile_image');

            $result['cookie'] = [
                'name' => $this->_getSession()->getName(),
                'value' => $this->_getSession()->getSessionId(),
                'lifetime' => $this->_getSession()->getCookieLifetime(),
                'path' => $this->_getSession()->getCookiePath(),
                'domain' => $this->_getSession()->getCookieDomain(),
            ];
        } catch (\Exception $e) {
            $result = ['error' => $e->getMessage(), 'errorcode' => $e->getCode()];
        }
        return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setData($result);
    }
}