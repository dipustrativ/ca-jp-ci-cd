<?php

namespace IXPF\Syncer\Api\Product;

interface ImageDataApi
{
    /**
     * @return int
     */
    public function getId(): int;

    /**
     * @return string
     */
    public function getUrl(): string;
}
