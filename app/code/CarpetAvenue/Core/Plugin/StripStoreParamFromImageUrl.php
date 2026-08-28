<?php

declare(strict_types=1);

namespace CarpetAvenue\Core\Plugin;

use Magento\Catalog\Block\Product\Image as ImageBlock;
use Magento\Catalog\Block\Product\ImageFactory;

/**
 * Drop the `?___store=<code>` that ImageFactory::create() appends to every product
 * image URL when web/url/use_store is on. It does nothing for a file on disk (the
 * resized image is already keyed by a hash of its resize params) but forks the CDN
 * cache into one entry per store view.
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
}
