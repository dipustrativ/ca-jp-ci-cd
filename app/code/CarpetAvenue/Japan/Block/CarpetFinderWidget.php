<?php
declare(strict_types=1);

namespace CarpetAvenue\Japan\Block;

use Magento\Framework\View\Element\AbstractBlock;
use Magento\Widget\Block\BlockInterface;

class CarpetFinderWidget extends AbstractBlock implements BlockInterface
{
    protected function _toHtml()
    {
        $block = $this->getLayout()->getBlock("carpetfinder");

        if ($block instanceof \CarpetAvenue\Japan\Block\CarpetFinder) {
            $block->setData("buttons_color", $this->getData("buttons_color"));

            return $block->toHtml();
        }

        return "";
    }
}
