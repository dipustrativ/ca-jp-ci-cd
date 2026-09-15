<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * On-demand special-price guard run.
 *
 *   bin/magento carpetavenue:japan:special-price-guard --dry-run
 *   bin/magento carpetavenue:japan:special-price-guard --sku=VAN1003,VAN1004
 *   bin/magento carpetavenue:japan:special-price-guard --limit=50 --dry-run
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Console\Command;

use CarpetAvenue\Japan\Model\PriceAction\Entry;
use CarpetAvenue\Japan\Model\SpecialPriceGuard\RunOptions;
use CarpetAvenue\Japan\Model\SpecialPriceGuard\Runner;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SpecialPriceGuardCommand extends Command
{
    private const OPT_DRY_RUN = 'dry-run';
    private const OPT_SKU = 'sku';
    private const OPT_LIMIT = 'limit';
    private const OPT_FORCE = 'force';

    /** Rows printed before the listing is truncated. */
    private const PREVIEW_ROWS = 50;

    public function __construct(
        private readonly Runner $runner,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:japan:special-price-guard')
            ->setDescription('Clamp special_price to the JP minimum (cost * multiplier + shipping incl. tax).')
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Decide and log, but write nothing')
            ->addOption(self::OPT_SKU, null, InputOption::VALUE_REQUIRED, 'Comma-separated SKUs to restrict the run to')
            ->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED, 'Stop after scanning N products', '0')
            ->addOption(self::OPT_FORCE, null, InputOption::VALUE_NONE, 'Ignore the blast-radius cap and the master switch');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area already set by another command in the same process.
        }

        $skus = array_values(array_filter(
            array_map('trim', explode(',', (string)$input->getOption(self::OPT_SKU))),
            static fn (string $sku): bool => $sku !== ''
        ));

        $options = new RunOptions(
            dryRun: (bool)$input->getOption(self::OPT_DRY_RUN),
            skus: $skus,
            limit: (int)$input->getOption(self::OPT_LIMIT),
            force: (bool)$input->getOption(self::OPT_FORCE)
        );

        $result = $this->runner->run($options);

        if ($result->aborted) {
            $output->writeln('<error>Aborted: ' . $result->abortReason . '</error>');

            return Command::FAILURE;
        }

        $this->renderChanges($result->changes, $output);

        $output->writeln('');
        $output->writeln('<info>' . $result->summary() . '</info>');
        if ($result->dryRun) {
            $output->writeln('<comment>Dry run — nothing was written to the catalog.</comment>');
        }

        return $result->errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param Entry[] $changes
     */
    private function renderChanges(array $changes, OutputInterface $output): void
    {
        if ($changes === []) {
            $output->writeln('<info>Nothing to change.</info>');

            return;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'SKU', 'What Happened', 'Why', 'Cost Used', 'Minimum', 'Before', 'After']);

        foreach (array_slice($changes, 0, self::PREVIEW_ROWS) as $change) {
            $table->addRow([
                $change->productId,
                (string)$change->sku,
                $change->action,
                $change->reason,
                $this->money($change->basisValue),
                $this->money($change->thresholdValue),
                $this->money($change->oldValue),
                $this->money($change->newValue),
            ]);
        }

        $table->render();

        $hidden = count($changes) - self::PREVIEW_ROWS;
        if ($hidden > 0) {
            $output->writeln(sprintf('<comment>… and %d more (see the admin grid).</comment>', $hidden));
        }
    }

    private function money(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2, '.', '');
    }
}
