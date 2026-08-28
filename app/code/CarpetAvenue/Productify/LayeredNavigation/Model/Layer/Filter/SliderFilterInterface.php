<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\LayeredNavigation\Model\Layer\Filter;

/**
 * Implemented by layer filters rendered as a from-to range slider instead of a
 * list of option links. The shared renderer template detects the synthetic
 * slider item by SLIDER_ITEM_LABEL and reads getSliderConfig() for the widget.
 */
interface SliderFilterInterface
{
    public const SLIDER_ITEM_LABEL = '__slider__';

    /**
     * @return array{min: float, max: float, from: float, to: float, step: float, requestVar: string, units: string}
     */
    public function getSliderConfig(): array;
}
