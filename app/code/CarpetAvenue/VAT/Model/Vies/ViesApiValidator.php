<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * VIES validation via viesapi.eu (paid). Ported from the old CarpetAvenue\Vies
 * validator. Requires `composer require viesapi/viesapi-php`; guarded so a
 * missing library degrades gracefully instead of fataling.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Model\Vies;

use CarpetAvenue\VAT\Api\ViesValidatorInterface;
use CarpetAvenue\VAT\Model\Config;
use Psr\Log\LoggerInterface;

class ViesApiValidator implements ViesValidatorInterface
{
    private const CLIENT_CLASS = '\VIESAPI\VIESAPIClient';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function validate(string $countryCode, string $vatNumber): ViesResult
    {
        $countryCode = strtoupper(trim($countryCode));
        $vatNumber = preg_replace('/\s+/', '', trim($vatNumber)) ?? '';

        if (!class_exists(self::CLIENT_CLASS)) {
            $msg = 'viesapi/viesapi-php library is not installed.';
            $this->logger->error('[VIES][viesapi] ' . $msg);
            return ViesResult::error($msg);
        }

        try {
            $clientClass = self::CLIENT_CLASS;
            if ($this->config->viesTestMode()) {
                $client = new $clientClass();
            } else {
                $client = new $clientClass($this->config->viesApiId(), $this->config->viesApiKey());
            }

            $vies = $client->get_vies_data($countryCode . $vatNumber);
            if ($vies) {
                return new ViesResult(
                    valid: (bool)$vies->valid,
                    countryCode: (string)$vies->country_code,
                    vatNumber: (string)$vies->vat_number,
                    requestDate: (string)$vies->date,
                    traderName: (string)$vies->trader_name,
                    traderAddress: (string)$vies->trader_address,
                    requestIdentifier: (string)$vies->id,
                    error: !$vies->valid
                );
            }

            $error = $client->get_last_error();
            $this->logger->error('[VIES][viesapi] ' . $error);
            return ViesResult::error((string)$error);
        } catch (\Throwable $e) {
            $this->logger->error('[VIES][viesapi] ' . $e->getMessage());
            return ViesResult::error($e->getMessage());
        }
    }
}
