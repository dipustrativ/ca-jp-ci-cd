<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Provider-agnostic VIES validation result.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Model\Vies;

class ViesResult
{
    public function __construct(
        public bool $valid = false,
        public string $countryCode = '',
        public string $vatNumber = '',
        public string $requestDate = '',
        public string $traderName = '',
        public string $traderAddress = '',
        public string $requestIdentifier = '',
        public bool $error = false,
        public string $message = ''
    ) {
    }

    public static function error(string $message): self
    {
        return new self(valid: false, error: true, message: $message);
    }
}
