<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Quote;

use Magento\Framework\App\RequestInterface;

/**
 * Admin-parity customer-group tax scope.
 *
 * The admin "Create New Order" screen changes VAT when the customer group is
 * switched because it posts order[account][group_id]; the EU-VAT (Geissweb /
 * Evalent) tax plugin reads that request param to decide the tax class for the
 * selected group. A frontend REST request has no such param, so the plugin
 * falls back to the customer's stored group and any group set on the quote is
 * ignored for tax.
 *
 * This helper mirrors the admin behaviour: before collectTotals it publishes the
 * quote's current customer group into that param so tax follows the selected
 * group; release() clears it afterwards. The active group is also exposed via
 * getGroupId() so Plugin\Tax\CrossBorderTradeGroup can disable cross-border
 * trade for it (the EU-VAT CBT plugin only reads the customer session).
 */
class CustomerGroupTaxScope
{
    private const PARAM = 'order';

    private ?int $groupId = null;

    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Publish the quote's customer group so the EU-VAT plugin taxes for it.
     */
    public function apply($quote): void
    {
        $groupId = (int)$quote->getCustomerGroupId();
        $this->groupId = $groupId;
        $this->request->setParams([
            self::PARAM => ['account' => ['group_id' => $groupId]],
        ]);
    }

    public function release(): void
    {
        $this->request->setParam(self::PARAM, null);
        $this->groupId = null;
    }

    /**
     * Customer group currently scoped for tax, or null outside apply()/release().
     */
    public function getGroupId(): ?int
    {
        return $this->groupId;
    }
}
