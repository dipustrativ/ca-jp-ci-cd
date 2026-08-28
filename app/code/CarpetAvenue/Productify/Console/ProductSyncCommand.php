<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Console;

use CarpetAvenue\Productify\Model\GroupProductCacheRepository;
use Exception;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Helper\Image;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Locale\Currency as LocaleCurrency;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use CarpetAvenue\Productify\Service\ProductSync;

/**
 * Build grouped (by design_id_group) variant cache with storefront-accurate prices & currency
 */
class ProductSyncCommand extends Command
{
    public function __construct(
        protected CollectionFactory $collectionFactory,
        protected GroupProductCacheRepository $repository,
        protected State $state,
        protected StoreManagerInterface $storeManager,
        protected Image $imageHelper,
        protected CacheInterface $cache,
        protected Emulation $emulation,
        protected PriceCurrencyInterface $priceCurrency,
        protected LocaleCurrency $localeCurrency,
        protected ProductSync $productSync
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('productify:product:sync')
            ->setDescription('Sync All Products.');
    }

    /**
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('frontend');
        } catch (Exception $exception) {
            // Area already set
        }
        try {
            $startTime = microtime(true);
            // First argument is the product-id filter; the output goes second.
            $results = $this->productSync->buildAllStores([], $output);
            $products = array_sum($results);
            $output->writeln("✅ Successfully Sync All Stores for $products Products.");
            $executionTime = round(microtime(true) - $startTime, 2);
            $output->writeln("⏱️ Execution time: {$executionTime} seconds");
        } catch (Exception $exception) {
            $output->writeln("<error>{$exception->getMessage()}</error>");
            return Cli::RETURN_FAILURE;
        }
        return Cli::RETURN_SUCCESS;
    }
}
