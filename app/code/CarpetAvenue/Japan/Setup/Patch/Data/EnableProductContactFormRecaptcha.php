<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Enables Google reCAPTCHA v3 on the product-page "Write Us" contact form
 * (Evalent_ProductContactForm) by setting
 * recaptcha_frontend/type_for/productcontactform = recaptcha_v3.
 *
 * The block only wires its reCAPTCHA child when
 * Evalent\ProductContactForm\Helper\Data::isGRecaptchaEnabled() is true, which
 * reads this exact flag (isSetFlag). Without it the {{getChildHtml('recaptcha')}}
 * slot in productcontactform.phtml renders nothing.
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

class EnableProductContactFormRecaptcha implements DataPatchInterface
{
    private const PATH_PRODUCT_CONTACT_RECAPTCHA = 'recaptcha_frontend/type_for/productcontactform';
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
        if ($this->scopeConfig->getValue(self::PATH_PRODUCT_CONTACT_RECAPTCHA) !== self::RECAPTCHA_TYPE) {
            $this->configWriter->save(self::PATH_PRODUCT_CONTACT_RECAPTCHA, self::RECAPTCHA_TYPE);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [EnableContactFormRecaptcha::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
