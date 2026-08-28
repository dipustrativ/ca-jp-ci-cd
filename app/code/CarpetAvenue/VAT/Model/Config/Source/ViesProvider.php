<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ViesProvider implements OptionSourceInterface
{
    public const EU_SOAP = 'eu_soap';
    public const VIESAPI = 'viesapi';

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::EU_SOAP, 'label' => __('EU VIES SOAP (free)')],
            ['value' => self::VIESAPI, 'label' => __('viesapi.eu (paid)')],
        ];
    }
}
