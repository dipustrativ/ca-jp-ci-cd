<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Cron;

use CarpetAvenue\Productify\Model\OnSale\FlagUpdater;
use Psr\Log\LoggerInterface;

/**
 * Keeps the am_on_sale flag current.
 *
 * Runs on a timer rather than off product saves because a discount can start or
 * stop purely by date (special_from_date / special_to_date, catalog rules), with
 * nobody touching the product.
 */
class UpdateOnSaleFlag
{
    public function __construct(
        private readonly FlagUpdater $flagUpdater,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $result = $this->flagUpdater->execute();
        } catch (\Exception $e) {
            $this->logger->error('Productify: on-sale flag update failed: ' . $e->getMessage());

            return;
        }

        //  Only worth a line when something moved; a quiet hour is the norm.
        if ($result['changed'] > 0) {
            $this->logger->info(sprintf(
                'Productify: on-sale flag updated — %d products on sale, %d changed.',
                $result['on_sale'],
                $result['changed']
            ));
        }
    }
}
