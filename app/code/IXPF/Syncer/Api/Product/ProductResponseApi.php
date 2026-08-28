<?php

namespace IXPF\Syncer\Api\Product;

interface ProductResponseApi
{
    /**
     * @return int
     */
    public function getId(): int;

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @return string
     */
    public function getType(): string;

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @return string
     */
    public function getCreatedAt(): string;

    /**
     * @return string
     */
    public function getUpdateAt(): string;

    /**
     * @return \Magento\Catalog\Api\Data\CategoryInterface[]
     */
    public function getCategories(): array;

    /**
     * @return array
     */
    public function getTranslations(): array;
//
//    /**
//     * @return ImageDataApi|null
//     */
//    public function getMainImage(): ?ImageDataApi;
//
//    /**
//     * @return ImageDataApi[]
//     */
//    public function getGalleryImages(): array;
//

//


}
