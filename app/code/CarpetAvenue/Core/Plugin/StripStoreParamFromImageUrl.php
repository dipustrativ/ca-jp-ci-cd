<?php

declare(strict_types=1);

namespace CarpetAvenue\Core\Plugin;

use Magento\Catalog\Block\Product\Image as ImageBlock;
use Magento\Catalog\Block\Product\ImageFactory;
use Magento\Catalog\Block\Product\View\Gallery as GalleryBlock;
use Magento\Catalog\ViewModel\Product\Gallery as GalleryViewModel;

/**
 * Drop the `?___store=<code>` that core appends to every product image URL when
 * web/url/use_store is on. It does nothing for a file on disk (the resized image
 * is already keyed by a hash of its resize params) but forks the CDN cache into
 * one entry per store view.
 *
 * Three core call sites add it, all gated on Store::XML_PATH_STORE_IN_URL:
 * ImageFactory::create() (listings), Gallery::getGalleryImagesJson() (PDP gallery,
 * incl. Evalent_MagicGallery which reuses it) and the Gallery view model's
 * getMainProductImage() (PDP preload image).
 */
class StripStoreParamFromImageUrl
{
    /**
     * Remove the store param from the rendered image URL.
     *
     * @param ImageFactory $subject
     * @param ImageBlock $result
     * @return ImageBlock
     */
    public function afterCreate(ImageFactory $subject, ImageBlock $result): ImageBlock
    {
        $url = $result->getData('image_url');

        // Core builds exactly `$imageAsset->getUrl() . '?___store=' . $code`, and
        // getUrl() never carries a query, so the param is always the whole query.
        if (is_string($url) && str_contains($url, '?___store=')) {
            $result->setData('image_url', strtok($url, '?'));
        }

        return $result;
    }

    /**
     * Remove the store param from every URL in the PDP gallery JSON.
     *
     * @param GalleryBlock $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetGalleryImagesJson(GalleryBlock $subject, $result)
    {
        // Guard first: core only reaches getProduct()->getStore() when it appended
        // the param, so a JSON without it must not make us call getProduct() either
        // (a gallery with no images never resolves a product).
        if (!is_string($result) || !str_contains($result, '?___store=')) {
            return $result;
        }

        // Core appends this exact literal to each thumb/img/full URL, so a plain
        // string replace is enough and can't touch anything else in the JSON.
        return str_replace('?___store=' . $subject->getProduct()->getStore()->getCode(), '', $result);
    }

    /**
     * Remove the store param from the PDP main (preloaded) image URL.
     *
     * @param GalleryViewModel $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetMainProductImage(GalleryViewModel $subject, $result)
    {
        return is_string($result) && str_contains($result, '?___store=') ? strtok($result, '?') : $result;
    }
}
