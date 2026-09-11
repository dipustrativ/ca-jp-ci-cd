<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Quote;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\Quote;

/**
 * Runs a block of quote work as if the quote's customer group were the session's group.
 *
 * The IXPF quote endpoints build and submit quotes from a REST request, where the customer
 * session carries NOT LOGGED IN (group 0) no matter who the quote belongs to. Anything that
 * resolves a group off the session rather than off the quote - tax rules keyed on the customer
 * tax class, catalog price rules, tier/group prices - then prices the quote as a guest, while
 * the same order placed in the admin prices on the customer's own group. `apply()` closes that
 * gap for the duration of the call; `release()` in the caller's `finally` puts the session back.
 *
 * The quote's own totals path needs no help: Quote::getCustomerTaxClassId() already reads the
 * class off customer_group_id.
 *
 * Nested apply() calls keep the outermost scope - QuoteBuilder applies, then calls into helpers
 * that apply again, and only the first release() should restore.
 */
class CustomerGroupTaxScope
{
    private bool $active = false;

    private ?int $previousGroupId = null;

    public function __construct(
        private readonly CustomerSession $customerSession
    ) {
    }

    /**
     * Put the quote's customer group on the session until release().
     *
     * @param Quote $quote
     * @return void
     */
    public function apply(Quote $quote): void
    {
        if ($this->active) {
            return;
        }

        $groupId = $quote->getCustomerGroupId();
        if ($groupId === null) {
            return;
        }

        $this->previousGroupId = (int)$this->customerSession->getCustomerGroupId();
        $this->customerSession->setCustomerGroupId((int)$groupId);
        $this->active = true;
    }

    /**
     * Restore the session group. Safe to call when apply() did nothing.
     *
     * @return void
     */
    public function release(): void
    {
        if (!$this->active) {
            return;
        }

        $this->customerSession->setCustomerGroupId($this->previousGroupId);
        $this->previousGroupId = null;
        $this->active = false;
    }
}
