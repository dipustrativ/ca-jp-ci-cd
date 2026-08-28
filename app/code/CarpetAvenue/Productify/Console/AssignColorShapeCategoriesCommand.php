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

use CarpetAvenue\Productify\Service\ColorShapeCategoryAssigner;
use Exception;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper around ColorShapeCategoryAssigner: creates colour/shape categories
 * under "All Carpets" and assigns enabled products to them.
 */
class AssignColorShapeCategoriesCommand extends Command
{
    public function __construct(
        private readonly ColorShapeCategoryAssigner $assigner,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('productify:category:assign-color-shape')
            ->setDescription(
                'Create colour/shape categories under "All Carpets" and assign enabled products to them.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Log and print the intended actions without creating categories or assigning products.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('adminhtml');
        } catch (Exception $e) {
            // Area code already set.
        }

        $dryRun = (bool)$input->getOption('dry-run');

        $stats = $this->assigner->execute(
            $dryRun,
            static function (string $message) use ($output): void {
                $output->writeln('<info>' . $message . '</info>');
            }
        );

        return !empty($stats['aborted']) ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }
}
