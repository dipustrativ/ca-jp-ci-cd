<?php

namespace CarpetAvenue\Japan\Model;

use CarpetAvenue\Japan\Api\PriceStepItemInterface;

class PriceStepItem implements PriceStepItemInterface
{
    private $label;
    private $url;
    private $range;
    private $lower;
    private $upper;

    public function __construct(
        $label,
        $url,
        $range,
        $lower,
        $upper
    ) {
        $this->label = $label;
        $this->url = $url;
        $this->range = $range;
        $this->lower = $lower;
        $this->upper = $upper;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getRange(): string
    {
        return $this->range;
    }

    public function getMin(): string
    {
        return $this->lower;
    }

    public function getMax(): string
    {
        return $this->upper;
    }
}
