<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\ViewModel;

use CarpetAvenue\Productify\Model\Search\SuggestConfig;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * data-mage-init payload for the search suggestion widget, shared by the header
 * mini search and the search-results page band.
 */
class SuggestOptions implements ArgumentInterface
{
    public function __construct(
        private readonly SuggestConfig $config,
        private readonly UrlInterface $url,
        private readonly Json $json
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Phrases the placeholder types out, in order.
     *
     * @return string[]
     */
    public function getPlaceholders(): array
    {
        return [
            (string) __('Find your rug here...'),
            (string) __('Search by name, color, style, etc.'),
            (string) __('Search among thousands of rugs here'),
        ];
    }

    /**
     * @param string $destination CSS selector of the dropdown host
     * @param string $formSelector CSS selector of the search form
     */
    public function getJsonConfig(string $destination, string $formSelector): string
    {
        return $this->json->serialize([
            'productifySearchSuggest' => [
                'url' => $this->url->getUrl('productify/search/suggest'),
                'destination' => $destination,
                'formSelector' => $formSelector,
                'minChars' => $this->config->getMinChars(),
                'placeholders' => $this->getPlaceholders(),
            ],
        ]);
    }
}
