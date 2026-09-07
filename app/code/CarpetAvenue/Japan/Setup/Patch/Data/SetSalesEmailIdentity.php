<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Sets the "Sales Representative" store email identity
 * (trans_email/ident_sales/email) to the store's real sales address.
 *
 * The contact page's right-top CMS block renders this identity via
 * {{config path="trans_email/ident_sales/email"}}, so without this the page
 * showed Magento's framework default `sales@example.com`. Setting the identity
 * here fixes the contact page AND makes sales transactional emails send from
 * the correct address — one source of truth.
 *
 * Writes the DEFAULT scope only, so an admin can still override per
 * website/store. Idempotent: skips when the identity already matches.
 *
 * @package CarpetAvenue_Japan
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class SetSalesEmailIdentity implements DataPatchInterface
{
    private const PATH_SALES_EMAIL = 'trans_email/ident_sales/email';
    private const SALES_EMAIL = 'info@carpetavenue.jp';

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
        if ($this->scopeConfig->getValue(self::PATH_SALES_EMAIL) !== self::SALES_EMAIL) {
            $this->configWriter->save(self::PATH_SALES_EMAIL, self::SALES_EMAIL);
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
