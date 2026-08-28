<?php
/**
 * BelVG LLC.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://store.belvg.com/BelVG-LICENSE-COMMUNITY.txt
 *
 ********************************************************************
 * @category   BelVG
 * @package    BelVG_GuestWishlist
 * @copyright  Copyright (c) BelVG LLC. (http://www.belvg.com)
 * @license    http://store.belvg.com/BelVG-LICENSE-COMMUNITY.txt
 */
namespace BelVG\GuestWishlist\Controller\Index;

use Magento\Framework\App\Action;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\ResultFactory;

class DownloadCustomOption extends AbstractIndex
{
    /**
     * @var \Magento\Framework\App\Response\Http\FileFactory
     */
    protected $_fileResponseFactory;

    /**
     * Json Serializer Instance
     *
     * @var \BelVG\GuestWishlist\Model\SerializerBridge
     */
    private $serializer;

    /**
     * @param Action\Context $context
     * @param \Magento\Framework\App\Response\Http\FileFactory $fileResponseFactory
     * @param \BelVG\GuestWishlist\Model\SerializerBridge|null $serializer
     */
    public function __construct(
        Action\Context $context,
        \Magento\Framework\App\Response\Http\FileFactory $fileResponseFactory,
        ?\BelVG\GuestWishlist\Model\SerializerBridge $serializer = null
    ) {
        $this->_fileResponseFactory = $fileResponseFactory;
        $this->serializer = $serializer ? $serializer : ObjectManager::getInstance()->create(\BelVG\GuestWishlist\Model\SerializerBridge::class);
        parent::__construct($context);
    }

    /**
     * Custom options download action
     *
     * @return \Magento\Framework\Controller\Result\Forward
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExitExpression)
     */
    public function execute()
    {
        $option = $this->_objectManager->create(
            \BelVG\GuestWishlist\Model\Item\Option::class
        )->load(
            $this->getRequest()->getParam('id')
        );
        /** @var \Magento\Framework\Controller\Result\Forward $resultForward */
        $resultForward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);
        if (!$option->getId()) {
            $resultForward->forward('noroute');
            return $resultForward;
        }

        $optionId = null;
        if (strpos($option->getCode(), \Magento\Catalog\Model\Product\Type\AbstractType::OPTION_PREFIX) === 0) {
            $optionId = str_replace(
                \Magento\Catalog\Model\Product\Type\AbstractType::OPTION_PREFIX,
                '',
                $option->getCode()
            );
            if ((int)$optionId != $optionId) {
                $resultForward->forward('noroute');
                return $resultForward;
            }
        }
        $productOption = $this->_objectManager->create(\Magento\Catalog\Model\Product\Option::class)->load($optionId);

        if (!$productOption ||
            !$productOption->getId() ||
            $productOption->getProductId() != $option->getProductId() ||
            $productOption->getType() != 'file'
        ) {
            $resultForward->forward('noroute');
            return $resultForward;
        }

        try {
            $info = $this->serializer->unserialize($option->getValue());
            $secretKey = $this->getRequest()->getParam('key');

            if ($secretKey == $info['secret_key']) {
                $this->_fileResponseFactory->create(
                    $info['title'],
                    ['value' => $info['quote_path'], 'type' => 'filename'],
                    DirectoryList::ROOT
                );
            }
        } catch (\Exception $e) {
            $resultForward->forward('noroute');
            return $resultForward;
        }
    }
}
