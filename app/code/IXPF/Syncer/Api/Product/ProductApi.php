<?php

namespace IXPF\Syncer\Api\Product;

interface ProductApi
{
    /**
     * @return ProductListResponseApi
     */
    public function list(): ProductListResponseApi;
}
