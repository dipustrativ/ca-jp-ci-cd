<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Points the product-page "Write Us" form (Evalent_ProductContactForm) at the
 * JP inbox and the General sender identity:
 *
 *   catalog/contact_form/receiving_email      = info@carpetavenue.jp
 *   catalog/contact_form/sender_email_identity = general
 *
 * Without a receiving address Helper\Data::getReceivers() returns an empty array,
 * Mail::send() calls addTo([]) and Symfony rejects the message with
 * 'An email must have a "To", "Cc", or "Bcc" header.' — the storefront then shows
 * the generic "please try again later" error on every submit.
 *
 * The sender defaults to `custom2` in the module's own config.xml, an identity
 * this install never filled in, so it is pinned to `general` here.
 *
 * Writes the DEFAULT scope only, so an admin can still override per
 * website/store. Idempotent: skips values that already match.
 *
 * The ON switch itself is EnableProductContactForm, which this patch depends on.
 *
 * @package CarpetAvenue_Japan
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class SetProductContactFormRecipient implements DataPatchInterface
{
    private const PATH_RECEIVING_EMAIL = 'catalog/contact_form/receiving_email';
    private const PATH_SENDER_IDENTITY = 'catalog/contact_form/sender_email_identity';

    private const RECEIVING_EMAIL = 'info@carpetavenue.jp';
    private const SENDER_IDENTITY = 'general';

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
        $values = [
            self::PATH_RECEIVING_EMAIL => self::RECEIVING_EMAIL,
            self::PATH_SENDER_IDENTITY => self::SENDER_IDENTITY,
        ];

        foreach ($values as $path => $value) {
            if ((string) $this->scopeConfig->getValue($path) !== $value) {
                $this->configWriter->save($path, $value);
            }
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [
            EnableProductContactForm::class,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
