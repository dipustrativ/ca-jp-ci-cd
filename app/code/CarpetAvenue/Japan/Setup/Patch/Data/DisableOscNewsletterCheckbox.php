<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Removes the "Register for newsletter" checkbox from the one-step checkout.
 *
 * Mageplaza_Osc renders it as `.osc-newsletter.field.choice.col-mp.mp-12` in the
 * order-review addition column. The block is wired in
 * `Mageplaza/Osc/view/frontend/layout/onestepcheckout_index_index.xml` with
 *
 *     componentDisabled = Mageplaza\Osc\Helper\Data::isDisabledNewsletter
 *
 * and that helper returns true — i.e. drops the component — as soon as
 * `osc/display_configuration/is_enabled_newsletter` is 0. The module ships 1 in
 * its own `etc/config.xml`, so the checkbox shows on an untouched install; only
 * the config turns it off. Hiding it in CSS would still submit the subscription
 * flag, so the switch is the right lever.
 *
 * Note the group is `display_configuration`, not `display` — Mageplaza's helper
 * resolves it via CONFIG_PATH_DISPLAY = 'display_configuration'.
 *
 * Writes the DEFAULT scope only, so an admin can still override per
 * website/store. Idempotent: skips when the value already matches.
 *
 * @package CarpetAvenue_Japan
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class DisableOscNewsletterCheckbox implements DataPatchInterface
{
    private const PATH_NEWSLETTER_ENABLED = 'osc/display_configuration/is_enabled_newsletter';

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
        if ($this->scopeConfig->isSetFlag(self::PATH_NEWSLETTER_ENABLED)) {
            $this->configWriter->save(self::PATH_NEWSLETTER_ENABLED, '0');
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
