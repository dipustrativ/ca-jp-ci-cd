<?php

namespace Evalent\MegaMenu\Observer;

use Evalent\MegaMenu\Model\Cache\TopMenuCache;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Menu is global per store and carries no per-entity tags, so nothing in core ever
 * cleaned it — it survived every category edit. See etc/events.xml for the triggers.
 */
class CleanTopMenuCache implements ObserverInterface
{
    public function __construct(private readonly TopMenuCache $topMenuCache)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->topMenuCache->clean();
    }
}
