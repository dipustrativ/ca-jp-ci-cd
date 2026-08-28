<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * PDP view model: tells the Add to Cart template whether the current store
 * runs in B2B catalog mode and, if so, where the "Become a Reseller" button
 * should link. Injected onto product.info.addtocart via layout so the theme
 * template stays decoupled from the module.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\ViewModel;

use CarpetAvenue\B2B\Model\Config;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class CatalogMode implements ArgumentInterface
{
    /** Front name of the reseller application route (see etc/frontend/routes.xml). */
    private const RESELLER_FRONT_NAME = 'become-a-carpient-reseller';

    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * True when the current store view is configured for B2B — Add to Cart is
     * replaced by the reseller call-to-action.
     */
    public function isResellerMode($store = null): bool
    {
        return $this->config->isB2bCatalogMode($store);
    }

    /**
     * URL of the "Become a Carpient Reseller" application page.
     */
    public function getResellerUrl(): string
    {
        return $this->url->getUrl(self::RESELLER_FRONT_NAME);
    }

    /**
     * Button label — translated per store view via the module i18n CSVs.
     */
    public function getButtonLabel(): Phrase
    {
        return __('Become a Reseller');
    }
}
