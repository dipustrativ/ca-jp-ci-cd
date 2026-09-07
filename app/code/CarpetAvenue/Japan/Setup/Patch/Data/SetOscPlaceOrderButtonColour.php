<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Paints the one-step-checkout "Place Order" button in the brand green.
 *
 * Mageplaza_Osc renders that button's colour from config rather than from the
 * theme: `Mageplaza/Osc/view/frontend/templates/design.phtml` emits
 *
 *     .osc-place-order-wrapper .place-order-primary button.primary.checkout {
 *         background-color: <osc/design_configuration/place_order_button> !important;
 *     }
 *
 * inline, and `!important` beats the theme's own `.action.primary` rule. The
 * module ships #01007F (navy), so an untouched install renders a navy button
 * while live is green — no amount of theme CSS fixes it, the config has to
 * change. Sizing, radius and weight already match live (579x60, 8px, 700).
 *
 * Note the group is `design_configuration`, not `design` — Mageplaza's helper
 * resolves it via CONFIG_PATH_DESIGN = 'design_configuration'.
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

class SetOscPlaceOrderButtonColour implements DataPatchInterface
{
    private const PATH_PLACE_ORDER_BUTTON = 'osc/design_configuration/place_order_button';

    /**
     * Measured from the live storefront's rendered button (rgb(47, 143, 59)).
     */
    private const BUTTON_COLOUR = '#2F8F3B';

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
        $current = (string) $this->scopeConfig->getValue(self::PATH_PLACE_ORDER_BUTTON);

        if (strcasecmp(trim($current, '# '), trim(self::BUTTON_COLOUR, '#')) !== 0) {
            $this->configWriter->save(self::PATH_PLACE_ORDER_BUTTON, self::BUTTON_COLOUR);
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
