<?php
namespace CarpetAvenue\Productify\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use CarpetAvenue\Productify\Helper\CollectorTag;

class CollectorTagData implements ArgumentInterface
{
    /**
     * @var CollectorTag
     */
    protected CollectorTag $helper;

    public function __construct(CollectorTag $helper)
    {
        $this->helper = $helper;
    }

    public function isCollectorProduct(int $productId): bool
    {
        return $this->helper->isCollectorProduct($productId);
    }
}