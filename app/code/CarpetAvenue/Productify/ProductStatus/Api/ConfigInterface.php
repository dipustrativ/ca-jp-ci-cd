<?php


namespace CarpetAvenue\Productify\ProductStatus\Api;


interface ConfigInterface
{
    /**
     * @return bool
     */
    public function onlineEnabled(): bool;
    /**
     * @return bool
     */
    public function approveEnabled(): bool;
    /**
     * @return bool
     */
    public function weightEnabled(): bool;

}