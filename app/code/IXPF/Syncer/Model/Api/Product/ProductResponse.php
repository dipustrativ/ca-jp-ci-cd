<?php

namespace IXPF\Syncer\Model\Api\Product;

use IXPF\Syncer\Api\Product\ImageDataApi;
use IXPF\Syncer\Api\Product\ProductResponseApi;
use Magento\Framework\DataObject;

class ProductResponse extends DataObject implements ProductResponseApi
{

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->_getData('id');
    }

    /**
     * @return string
     */
    public function getSku(): string
    {
        return $this->_getData('sku');
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return (string)$this->_getData('type');
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        switch ($this->_getData('status')) {
            case 1:
                return "enable";
            case 2:
                return "disable";
            default:
                return "unknown";
        }
    }

    /**
     * @return array|\Magento\Catalog\Api\Data\CategoryInterface[]
     */
    public function getCategories(): array
    {
       return $this->_getData('categories');
    }

    /**
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->_getData('created_at');
    }

    /**
     * @return string
     */
    public function getUpdateAt(): string
    {
        return $this->_getData('updated_at');
    }


    public function getTranslations(): array
    {
        return $this->_getData('translations') ?? [];
    }
//
//    public function getMainImage(): ?ImageDataApi
//    {
//        // TODO: Implement getMainImage() method.
//    }
//
//    public function getGalleryImages(): array
//    {
//        // TODO: Implement getGalleryImages() method.
//    }
//



}
