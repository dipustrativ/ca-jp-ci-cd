<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Typed reader for CarpetAvenue_B2B config. Every gate checks requireApproval()
 * first and no-ops when it is off.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    /** Business customer group id (see CarpetAvenue\VAT SetupCustomerGroups). */
    public const BUSINESS_GROUP_ID = 2;

    /** Storefront catalog sales modes (see Model\Source\CatalogSalesMode). */
    public const MODE_B2C = 'b2c';
    public const MODE_B2B = 'b2b';

    public const XML_CATALOG_SALES_MODE = 'carpetavenue_b2b/catalog/sales_mode';

    public const XML_REQUIRE_APPROVAL   = 'carpetavenue_b2b/general/require_approval';
    public const XML_DEFAULT_TO_BUSINESS = 'carpetavenue_b2b/general/default_to_business';
    public const XML_SEND_APPROVAL_EMAIL = 'carpetavenue_b2b/general/send_approval_email';
    public const XML_PENDING_MESSAGE     = 'carpetavenue_b2b/general/pending_message';
    public const XML_EMAIL_IDENTITY      = 'carpetavenue_b2b/general/email_identity';
    public const XML_EMAIL_TEMPLATE      = 'carpetavenue_b2b/general/email_template';

    public const XML_RESELLER_EMAIL_ENABLED  = 'carpetavenue_b2b/reseller/notify_enabled';
    public const XML_RESELLER_RECIPIENT      = 'carpetavenue_b2b/reseller/recipient';
    public const XML_RESELLER_EMAIL_IDENTITY = 'carpetavenue_b2b/reseller/email_identity';
    public const XML_RESELLER_EMAIL_TEMPLATE = 'carpetavenue_b2b/reseller/email_template';
    public const XML_RESELLER_INTRO_BLOCK    = 'carpetavenue_b2b/reseller/intro_block';
    public const XML_RESELLER_RECAPTCHA      = 'carpetavenue_b2b/reseller/recaptcha_enabled';

    public const RESELLER_INTRO_BLOCK_ID = 'become_a_carpient_reseller_intro';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Storefront catalog sales mode for the given store. Defaults to B2C so
     * nothing changes on the PDP unless an admin opts a store into B2B.
     */
    public function catalogSalesMode($store = null): string
    {
        $mode = (string)($this->scopeConfig->getValue(self::XML_CATALOG_SALES_MODE, ScopeInterface::SCOPE_STORE, $store) ?? '');

        return $mode !== '' ? $mode : self::MODE_B2C;
    }

    /**
     * True when the store hides Add to Cart in favour of the reseller CTA.
     */
    public function isB2bCatalogMode($store = null): bool
    {
        return $this->catalogSalesMode($store) === self::MODE_B2B;
    }

    public function requireApproval($store = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_REQUIRE_APPROVAL, ScopeInterface::SCOPE_STORE, $store);
    }

    public function defaultToBusiness($store = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_DEFAULT_TO_BUSINESS, ScopeInterface::SCOPE_STORE, $store);
    }

    public function sendApprovalEmail($store = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_SEND_APPROVAL_EMAIL, ScopeInterface::SCOPE_STORE, $store);
    }

    public function pendingMessage($store = null): string
    {
        return (string)($this->scopeConfig->getValue(self::XML_PENDING_MESSAGE, ScopeInterface::SCOPE_STORE, $store) ?? '');
    }

    public function emailIdentity($store = null): string
    {
        return (string)($this->scopeConfig->getValue(self::XML_EMAIL_IDENTITY, ScopeInterface::SCOPE_STORE, $store) ?? 'general');
    }

    public function emailTemplate($store = null): string
    {
        return (string)($this->scopeConfig->getValue(self::XML_EMAIL_TEMPLATE, ScopeInterface::SCOPE_STORE, $store)
            ?? 'carpetavenue_b2b_general_email_template');
    }

    public function resellerEmailEnabled($store = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_RESELLER_EMAIL_ENABLED, ScopeInterface::SCOPE_STORE, $store);
    }

    public function resellerRecipient($store = null): string
    {
        $recipient = (string)($this->scopeConfig->getValue(self::XML_RESELLER_RECIPIENT, ScopeInterface::SCOPE_STORE, $store) ?? '');
        if ($recipient === '') {
            // Fall back to the store's general contact address.
            $recipient = (string)($this->scopeConfig->getValue('trans_email/ident_general/email', ScopeInterface::SCOPE_STORE, $store) ?? '');
        }

        return $recipient;
    }

    public function resellerEmailIdentity($store = null): string
    {
        return (string)($this->scopeConfig->getValue(self::XML_RESELLER_EMAIL_IDENTITY, ScopeInterface::SCOPE_STORE, $store) ?? 'general');
    }

    public function resellerEmailTemplate($store = null): string
    {
        return (string)($this->scopeConfig->getValue(self::XML_RESELLER_EMAIL_TEMPLATE, ScopeInterface::SCOPE_STORE, $store)
            ?? 'carpetavenue_b2b_reseller_email_template');
    }

    /**
     * Master switch for reCAPTCHA verification on the reseller form. Default Yes.
     */
    public function resellerRecaptchaEnabled($store = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_RESELLER_RECAPTCHA, ScopeInterface::SCOPE_STORE, $store);
    }

    /**
     * CMS block identifier whose content is rendered as the reseller page intro.
     */
    public function resellerIntroBlock($store = null): string
    {
        $identifier = (string)($this->scopeConfig->getValue(self::XML_RESELLER_INTRO_BLOCK, ScopeInterface::SCOPE_STORE, $store) ?? '');

        return $identifier !== '' ? $identifier : self::RESELLER_INTRO_BLOCK_ID;
    }
}
