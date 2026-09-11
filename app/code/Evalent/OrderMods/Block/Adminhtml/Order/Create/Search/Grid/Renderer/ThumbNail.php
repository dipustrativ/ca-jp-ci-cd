<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Block\Adminhtml\Order\Create\Search\Grid\Renderer;

use Magento\Backend\Block\Context;
use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\DataObject;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class ThumbNail extends AbstractRenderer
{

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Catalog\Helper\Image
     */
    private $imageHelper;

    public function __construct(
        \Magento\Catalog\Helper\Image $imageHelper,
        StoreManagerInterface $storeManager,
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->storeManager = $storeManager;
        $this->imageHelper = $imageHelper;
    }

    /**
     * Render product qty field
     *
     * @param \Magento\Framework\DataObject $row
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function render(DataObject $row)
    {
        $img = "";
        $mediaDirectory = $this->storeManager->getStore()->getBaseUrl(
            UrlInterface::URL_TYPE_MEDIA
        );
        if($this->_getValue($row)!=''):

            $img='<img class="admin__control-thumbnail" src="'.$mediaDirectory.'catalog/product'.$this->_getValue($row).'"/>';
        else:
            $img='<img class="admin__control-thumbnail" src="'.$this->imageHelper->getDefaultPlaceholderUrl('image').'"/>';
        endif;
        return $img;
    }

}