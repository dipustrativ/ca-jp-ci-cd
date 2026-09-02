<?php
declare(strict_types=1);

namespace CarpetAvenue\Japan\Api;

interface PriceStepItemInterface
{
    public function getLabel(): string;
    public function getUrl(): string;
}
