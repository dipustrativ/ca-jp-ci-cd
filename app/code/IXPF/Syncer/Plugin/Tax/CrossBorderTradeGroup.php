<?php
declare(strict_types=1);

namespace IXPF\Syncer\Plugin\Tax;

use IXPF\Syncer\Services\Quote\CustomerGroupTaxScope;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Tax\Model\Config as TaxConfig;

/**
 * Disable cross-border trade for the customer group selected on a Syncer quote.
 *
 * The EU-VAT (Geissweb / Evalent) CBT plugin decides "disable CBT for group"
 * from the customer session only. A Syncer REST request has no logged-in
 * session, so a tax-exempt group (euvat/vat_settings/disable_cbt_groups) got
 * tax 0 but CBT stayed on — the gross catalog price was kept and the grand
 * total still included VAT. While CustomerGroupTaxScope is active, apply the
 * same rule for the scoped group. Runs after the EU-VAT plugin (sortOrder).
 */
class CrossBorderTradeGroup
{
    private const XML_PATH_DISABLE_CBT_GROUPS = 'euvat/vat_settings/disable_cbt_groups';

    public function __construct(
        private readonly CustomerGroupTaxScope $customerGroupTaxScope,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function afterCrossBorderTradeEnabled(TaxConfig $subject, bool $result, $store = null): bool
    {
        if (!$result) {
            return false;
        }

        $groupId = $this->customerGroupTaxScope->getGroupId();
        if ($groupId === null) {
            return $result; // not a Syncer quote request — leave EU-VAT logic untouched
        }

        $groups = (string)$this->scopeConfig->getValue(
            self::XML_PATH_DISABLE_CBT_GROUPS,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        $disabled = array_map('intval', array_filter(explode(',', $groups), 'strlen'));

        return !in_array($groupId, $disabled, true);
    }
}
