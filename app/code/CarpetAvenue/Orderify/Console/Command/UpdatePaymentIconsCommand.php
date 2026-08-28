<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Console\Command;

use CarpetAvenue\Orderify\Model\PaymentIcon\SheetImporter;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: rebuild the per-store payment-icon strips from the configured Google Sheet.
 *
 *   bin/magento orderify:payment-icons:update
 *   bin/magento orderify:payment-icons:update --dry-run
 */
class UpdatePaymentIconsCommand extends Command
{
    private const OPT_DRY_RUN = 'dry-run';

    /** @var SheetImporter */
    private $importer;

    /** @var State */
    private $state;

    public function __construct(SheetImporter $importer, State $state)
    {
        $this->importer = $importer;
        $this->state = $state;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('orderify:payment-icons:update')
            ->setDescription('Rebuild per-store payment-icon strips from the configured Google Sheet.')
            ->addOption(
                self::OPT_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Resolve and report changes without writing any configuration.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('adminhtml');
        } catch (\Exception $e) {
            // Area code already set — fine.
        }

        $dryRun = (bool)$input->getOption(self::OPT_DRY_RUN);
        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — no configuration will be written.</comment>');
        }

        try {
            $output->writeln('<info>Fetching payment-icons sheet…</info>');
            $result = $this->importer->import($dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>Payment-icons update failed: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Read %d data row(s): %d store(s) %s, %d unchanged, %d disabled, %d FAILED. %d icon(s) written.</info>',
            $result['data_rows'],
            $result['updated_stores'],
            $dryRun ? 'to update' : 'updated',
            $result['unchanged_stores'],
            count($result['disabled']),
            count($result['failures']),
            $result['written_icons']
        ));

        // Updated + unchanged per store.
        if (!empty($result['updated']) || !empty($result['unchanged'])) {
            $table = new Table($output);
            $table->setHeaders(['store_view_code', 'icons', 'state']);
            foreach ($result['updated'] as $code => $n) {
                $table->addRow([$code, $n, $dryRun ? 'would update' : 'updated']);
            }
            foreach ($result['unchanged'] as $code => $n) {
                $table->addRow([$code, $n, 'unchanged (skipped)']);
            }
            $table->render();
        }

        $failures = $result['failures'] ?? [];
        if (!empty($failures)) {
            $note = $dryRun ? '' : ' — admin notification raised';
            $output->writeln('<error>FAILED ' . count($failures) . ' row(s)' . $note . ':</error>');
            $failTable = new Table($output);
            $failTable->setHeaders(['row', 'store', 'method', 'reason']);
            foreach ($failures as $f) {
                $failTable->addRow([$f['row'], $f['store'], $f['method'], $f['reason']]);
            }
            $failTable->render();
        }

        // Intentional enabled=0 rows — logic, not an error.
        if (!empty($result['disabled'])) {
            $output->writeln(sprintf(
                '<comment>%d row(s) disabled (enabled=0) — intentionally excluded, not an error.</comment>',
                count($result['disabled'])
            ));
        }

        if ($dryRun) {
            $output->writeln('<comment>Dry run complete — nothing was saved.</comment>');
        } elseif (empty($result['updated'])) {
            $output->writeln('<info>Done — nothing changed, no store updated.</info>');
        } else {
            $output->writeln('<info>Done.</info>');
        }

        $output->writeln('<comment>Log: var/log/carpetavenue/orderify/payment_icons_' . date('Y-m-d') . '.log</comment>');

        return Cli::RETURN_SUCCESS;
    }
}
