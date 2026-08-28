<?php

namespace Evalent\MatrixRateMods\Api;

interface ConfigInterface{
    /**
     * @return bool
     */
    public function forceWeight(): ?bool;
}