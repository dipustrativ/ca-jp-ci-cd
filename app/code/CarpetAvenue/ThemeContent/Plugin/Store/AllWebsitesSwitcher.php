<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\ThemeContent\Plugin\Store;

use Magento\Store\Block\Switcher;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * List every active frontend store view in the language switcher, regardless of
 * which website it belongs to.
 *
 * Stock {@see Switcher::getStores()} only returns store views of the *current*
 * website and group ({@see Switcher::getRawStores()} calls
 * getWebsite()->getStores()). The country sites (Carpient SE / DK) live on their
 * own websites (ca_se / ca_dk) for currency scoping, so their store views never
 * appeared while browsing the base site. Cross-website switching itself is
 * handled by the ViewModel's redirect URL (stores/store/redirect), so only the
 * store list needs widening.
 */
class AllWebsitesSwitcher
{
    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    /**
     * @param Switcher $subject
     * @param Store[] $result
     * @return Store[]
     */
    public function afterGetStores(Switcher $subject, array $result): array
    {
        $stores = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($store->isActive()) {
                $stores[$store->getId()] = $store;
            }
        }

        uasort(
            $stores,
            static fn(Store $a, Store $b): int => (int)$a->getSortOrder() <=> (int)$b->getSortOrder()
        );

        return $stores;
    }
}
