<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Enables Google reCAPTCHA v3 on the storefront "Contact Us" form by setting
 * recaptcha_frontend/type_for/contact = recaptcha_v3.
 *
 * This is the per-form ON switch consumed by Magento_ReCaptchaContact — without
 * it the contact form renders with no reCAPTCHA regardless of whether the v3
 * site/secret keys are present.
 *
 * Only the toggle is written here. The v3 keys
 * (recaptcha_frontend/type_recaptcha_v3/public_key + private_key) are secrets,
 * stored encrypted and per-environment, so they are configured by hand /
 * deployment env and intentionally NOT committed in this patch.
 *
 * Writes the DEFAULT scope only, so an admin can still override per
 * website/store. Idempotent: skips when the toggle already matches.
 *
 * @package CarpetAvenue_Japan
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class EnableContactFormRecaptcha implements DataPatchInterface
{
    private const PATH_CONTACT_RECAPTCHA = 'recaptcha_frontend/type_for/contact';
    private const RECAPTCHA_TYPE = 'recaptcha_v3';

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        if ($this->scopeConfig->getValue(self::PATH_CONTACT_RECAPTCHA) !== self::RECAPTCHA_TYPE) {
            $this->configWriter->save(self::PATH_CONTACT_RECAPTCHA, self::RECAPTCHA_TYPE);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
