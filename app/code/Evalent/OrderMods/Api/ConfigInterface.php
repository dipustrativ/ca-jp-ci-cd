<?php
namespace Evalent\OrderMods\Api;

interface ConfigInterface{
    /**
     * @param string $name
     * @return bool
     */
    public function denyHoldForOrderStatus($status): ?bool;
}