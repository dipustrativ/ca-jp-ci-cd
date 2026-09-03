<?php

declare(strict_types=1);

namespace Evalent\CheckoutModsJP\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Enables the Adyen payment surface used by the "Proceed with Other Payment Methods"
 * cart button.
 *
 * On some environments (notably fresh local DBs) Adyen ships installed but disabled:
 *   payment/adyen_abstract/payment_methods_active = 0   (master switch)
 *   payment/adyen_cc/active                       = 0   (card method)
 * With both off the one-step checkout returns zero payment methods and the card
 * component never renders. This patch turns them on at the default scope.
 *
 * Notes:
 *  - Only runs when Adyen_Payment is installed; a no-op otherwise.
 *  - Runs once (recorded in patch_list), so it sets the flags at first deploy of
 *    this module and never fights a later admin change.
 *  - Values are written as normal config (not app/etc/config.php), so the admin
 *    toggles stay editable.
 */
class EnableAdyenPaymentMethods implements DataPatchInterface
{
    /**
     * path => value to set at the default scope.
     */
    private const CONFIG = [
        'payment/adyen_abstract/payment_methods_active' => '1',
        'payment/adyen_cc/active' => '1',
    ];

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ModuleManager $moduleManager
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        if (!$this->moduleManager->isEnabled('Adyen_Payment')) {
            return $this;
        }

        foreach (self::CONFIG as $path => $value) {
            $this->configWriter->save($path, $value);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
