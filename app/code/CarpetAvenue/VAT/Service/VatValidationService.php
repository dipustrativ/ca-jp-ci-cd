<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Single entry point other modules call to validate a VAT number. Selects the
 * active provider from config and no-ops (returns an error result) when VIES is
 * disabled, so callers never need to know which backend is wired.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Service;

use CarpetAvenue\VAT\Api\ViesValidatorInterface;
use CarpetAvenue\VAT\Model\Config;
use CarpetAvenue\VAT\Model\Vies\ViesResult;

class VatValidationService
{
    /**
     * @param array<string,ViesValidatorInterface> $providers keyed by provider code
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $providers = []
    ) {
    }

    public function isEnabled($store = null): bool
    {
        return $this->config->viesEnabled($store);
    }

    /**
     * Validate a VAT number using the configured provider.
     * Returns an error result when VIES is disabled or the provider is unknown.
     */
    public function validate(string $countryCode, string $vatNumber, $store = null): ViesResult
    {
        if (!$this->config->viesEnabled($store)) {
            return ViesResult::error(__('VIES validation is disabled.')->render());
        }

        $provider = $this->config->viesProvider($store);
        if (!isset($this->providers[$provider])) {
            return ViesResult::error(__('Unknown VIES provider: %1', $provider)->render());
        }

        return $this->providers[$provider]->validate($countryCode, $vatNumber);
    }
}
