<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Controller\SimilarTo;

use CarpetAvenue\Productify\Helper\SimilarTo;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class Index implements HttpGetActionInterface
{
    public function __construct(
        protected RequestInterface $request,
        protected SimilarTo        $similarTo,
        protected ResultFactory    $resultFactory,
    ) {
    }

    public function execute(): false|ResultInterface|ResponseInterface|Redirect
    {
        $similarToSku = $this->request->getParam('sku');
        $similarToProduct = $this->similarTo->getProductBySku($similarToSku);
        if ($similarToProduct) {
            $similarToUrl = $this->similarTo->getSimilarToUrl($similarToProduct, true);
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setUrl($similarToUrl);
            return $resultRedirect;
        }
        return false;
    }
}