<?php
/**
 * Copyright © Carpient. All rights reserved.
 */
declare(strict_types=1);

namespace Carpient\Theme\ViewModel;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\Url\EncoderInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Drop-in replacement for Magento\Store\ViewModel\SwitcherUrlProvider that sends
 * the visitor to the target store view's home page instead of the current path.
 *
 * Used on the 404 handle only: the requested path does not exist, so carrying it
 * across store views would just produce another 404.
 */
class HomeSwitcherUrlProvider implements ArgumentInterface
{
    public function __construct(
        private readonly EncoderInterface $encoder,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * Returns target store redirect url pointing at that store view's home page.
     */
    public function getTargetStoreRedirectUrl(Store $store): string
    {
        return $this->urlBuilder->getUrl(
            'stores/store/redirect',
            [
                '___store' => $store->getCode(),
                '___from_store' => $this->storeManager->getStore()->getCode(),
                ActionInterface::PARAM_NAME_URL_ENCODED => $this->encoder->encode($store->getBaseUrl()),
            ]
        );
    }
}
