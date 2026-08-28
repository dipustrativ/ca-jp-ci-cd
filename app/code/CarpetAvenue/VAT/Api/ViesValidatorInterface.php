<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Api;

use CarpetAvenue\VAT\Model\Vies\ViesResult;

interface ViesValidatorInterface
{
    /**
     * Validate an EU VAT number.
     *
     * @param string $countryCode 2-letter country code (e.g. "DE")
     * @param string $vatNumber   VAT number without the country prefix
     */
    public function validate(string $countryCode, string $vatNumber): ViesResult;
}
