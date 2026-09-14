<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Enables Persistent Shopping Cart at the default scope so the JP storefront shows
 * the "Remember Me" checkbox (入力情報をブラウザに記憶する) on the login and
 * registration forms, matching production.
 *
 * Nothing was ever missing from the codebase. Magento_Persistent is enabled, and
 * vendor/magento/module-persistent/view/frontend/layout/customer_account_login.xml
 * already injects the block into the `form.additional.info` container that
 * Magento_Customer's login.phtml renders. The block simply gates itself:
 *
 *     Magento\Persistent\Block\Form\Remember::_toHtml()
 *         return $this->_persistentData->isEnabled() &&
 *             $this->_persistentData->isRememberMeEnabled() ? parent::_toHtml() : '';
 *
 * persistent/options/enabled had no core_config_data row at any scope, so it fell
 * back to the module default of 0 (module-persistent/etc/config.xml) and the block
 * rendered an empty string. This flag is the only thing that was required.
 *
 * Only `enabled` is written. remember_enabled and remember_default already default
 * to 1, which is why the box renders pre-checked — extra rows would be redundant.
 *
 * Enabling persistence is a real feature switch, not a cosmetic one: it also adds
 * the checkbox to the registration form and authentication popup, and activates the
 * module's quote/session observers so carts survive session expiry for customers who
 * tick it. Production has run with this on for years. Mageplaza OSC is unaffected —
 * Magento_Persistent targets checkout_index_index, not onestepcheckout_index_index.
 *
 * Writes the DEFAULT scope only, so an admin can still override per website.
 * Idempotent: skips when persistence is already enabled at the default scope.
 *
 * NOTE: the tooltip link renders これは何ですか？ from the magentoj/language-ja_jp
 * pack, NOT the "ヘルプ" entry in the theme's i18n/ja_JP.csv. That entry's key uses a
 * U+2019 curly apostrophe ("What’s this?") while the template emits __('What\'s
 * this?') with an ASCII quote, so it never matches. Production carries the identical
 * dead line and renders これは何ですか？ for the same reason — repairing the
 * apostrophe would make this store diverge from production. Leave it broken.
 *
 * @package CarpetAvenue_Japan
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class EnablePersistentRememberMe implements DataPatchInterface
{
    private const PATH_PERSISTENT_ENABLED = 'persistent/options/enabled';

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
        if (!$this->scopeConfig->isSetFlag(self::PATH_PERSISTENT_ENABLED)) {
            $this->configWriter->save(self::PATH_PERSISTENT_ENABLED, '1');
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
