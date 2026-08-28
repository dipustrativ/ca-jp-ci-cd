<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\ViewModel\Product\View;

use Evalent\DynamicSimilarProducts\Helper\Data as SimilarData;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class GetButtonSettings implements ArgumentInterface
{
    /**
     * @var SimilarData
     */
    private $helperData;

    public function __construct(
        SimilarData $helperData
    ) {
        $this->helperData = $helperData;
    }


    /**
     * Returns similar button font size.
     *
     * @return mixed
     */
    public function getFontSize()
    {
        return $this->helperData->getButtonFontSize();
    }

    /**
     * Returns similar button font weight.
     *
     * @return mixed
     */
    public function getFontWeight()
    {
        return $this->helperData->getButtonFontWeight();
    }

    /**
     * Returns similar button text.
     *
     * @return mixed
     */
    public function getButtonText()
    {
        return $this->helperData->getButtonText();
    }
}
