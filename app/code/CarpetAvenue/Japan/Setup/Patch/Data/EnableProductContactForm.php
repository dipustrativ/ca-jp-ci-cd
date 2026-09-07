<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Turns on the product-page "Write Us" contact form (Evalent_ProductContactForm)
 * by setting catalog/contact_form/enabled = 1.
 *
 * The block is declared in the module layout with
 * ifconfig="catalog/contact_form/enabled" and the module ships that flag OFF by
 * default (Evalent/ProductContactForm/etc/config.xml), so without this toggle
 * the #product-contact-collapsible section never renders on the product page.
 *
 * Writes the DEFAULT scope only, so an admin can still override per
 * website/store. Idempotent: skips when the toggle already matches.
 *
 * The receiving/cc e-mail addresses used when the form is submitted are
 * environment-specific and are configured by hand / deployment env, so they are
 * intentionally NOT written here.
 *
 * @package CarpetAvenue_Japan
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class EnableProductContactForm implements DataPatchInterface
{
    private const PATH_PRODUCT_CONTACT_FORM_ENABLED = 'catalog/contact_form/enabled';
    private const ENABLED = '1';

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
        if ((string) $this->scopeConfig->getValue(self::PATH_PRODUCT_CONTACT_FORM_ENABLED) !== self::ENABLED) {
            $this->configWriter->save(self::PATH_PRODUCT_CONTACT_FORM_ENABLED, self::ENABLED);
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
