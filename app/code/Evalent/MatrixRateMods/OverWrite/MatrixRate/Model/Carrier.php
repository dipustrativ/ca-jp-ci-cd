<?php

declare(strict_types=1);

namespace Evalent\MatrixRateMods\OverWrite\MatrixRate\Model;

class Carrier extends \WebShopApps\MatrixRate\Model\Carrier\Matrixrate
{

    public function isShippingLabelsAvailable()
    {
        return true;
    }
}