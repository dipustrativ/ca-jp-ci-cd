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

namespace CarpetAvenue\Productify\Controller\Adminhtml\GroupProducts;

use CarpetAvenue\Productify\Helper\Data;
use CarpetAvenue\Productify\Service\ProductSync;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Controller\ResultInterface;

class BuildVarient extends Action
{
    public function __construct(
        Context $context,
        protected JsonFactory $resultJsonFactory,
        protected TypeListInterface $cacheTypeList,
        protected ProductSync $productSync
    ) {
        parent::__construct($context);
    }

    /**
     * Execute the controller action
     *
     * @return Json|ResultInterface|ResponseInterface
     */
    public function execute(): Json|ResultInterface|ResponseInterface
    {
        $result = $this->resultJsonFactory->create();

        try {
            $startTime = microtime(true);
            $logFile = BP . '/var/log/' . Data::GROUP_PRODUCT_BUILD_LOG_FILE;

            // ProductSync is the all-store orchestrator; VariantBuilder only
            // builds a single store and has no buildAllStores().
            $this->productSync->buildAllStores();

            $executionTime = round(microtime(true) - $startTime, 2);
            $timestamp = date('Y-m-d H:i:s');
            $logEntry = "[$timestamp] ⏱️ Execution time: $executionTime seconds \n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            $result->setData([
                'success' => true,
                'message' => 'Variant cache build completed successfully!',
            ]);
        } catch (\Throwable $exception) {
            // Throwable, not Exception: a fatal Error here would otherwise leave
            // the admin AJAX call with an empty 500 instead of a JSON message.
            $result->setData([
                'success' => false,
                'message' => 'Error: ' . $exception->getMessage(),
            ]);
        }
        return $result;
    }

    /**
     * Check admin permissions for this controller
     *
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('CarpetAvenue_Productify::productify_groupproducts');
    }
}