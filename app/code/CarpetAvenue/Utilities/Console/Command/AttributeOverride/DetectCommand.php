<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Console\Command\AttributeOverride;

use CarpetAvenue\Utilities\Helper\Config;
use CarpetAvenue\Utilities\Model\OverrideEventLogFactory;
use CarpetAvenue\Utilities\Model\ResourceModel\OverrideEventLog as OverrideEventLogResource;
use CarpetAvenue\Utilities\Service\AttributeOverride\DetectService;
use CarpetAvenue\Utilities\Service\AttributeOverride\FixService;
use CarpetAvenue\Utilities\Service\AttributeOverride\NotificationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:attribute-override:detect
 *
 * Scans DB for store-view-specific attribute overrides (store_id > 0).
 * Every such row is a violation — it overrides the global (store_id=0) value.
 * Fix = delete those rows so Magento falls back to the global value.
 * Protected stores (configured in admin) are always excluded.
 *
 * Options:
 *   --dry-run         Print violations to stdout. No DB writes, no notifications.
 *   --type=price|image|custom   Limit scan to one type. Default: all enabled.
 *   --store=1,2       Comma-separated store view IDs. Default: all non-protected.
 *
 * Jenkins cron example:
 *   0 6,18 * * * /var/www/magento/bin/magento carpetavenue:attribute-override:detect
 */
class DetectCommand extends Command
{
    public function __construct(
        private readonly DetectService $detectService,
        private readonly FixService $fixService,
        private readonly NotificationService $notificationService,
        private readonly OverrideEventLogFactory $eventLogFactory,
        private readonly OverrideEventLogResource $eventLogResource,
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:attribute-override:detect')
            ->setDescription('Scan for store-view-specific attribute overrides (store_id>0). Fix = delete those rows so Magento uses the global value.')
            ->addOption('dry-run',  null, InputOption::VALUE_NONE,     'Print violations only. No DB writes, no email/Slack.')
            ->addOption('type',     null, InputOption::VALUE_OPTIONAL,  'Limit to: price, image, or custom. Default: all.', '')
            ->addOption('store',    null, InputOption::VALUE_OPTIONAL,  'Comma-separated store view IDs. Default: all.', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun   = (bool) $input->getOption('dry-run');
        $typeOpt  = trim((string) $input->getOption('type'));
        $storeOpt = trim((string) $input->getOption('store'));

        $types    = $typeOpt  !== '' ? array_map('trim', explode(',', $typeOpt))   : [];
        $storeIds = $storeOpt !== '' ? array_map('intval', explode(',', $storeOpt)) : [];

        $runId = $this->generateRunId();

        $output->writeln("<info>Run ID:</info> {$runId}");
        if ($dryRun) {
            $output->writeln('<comment>[DRY RUN] No writes will be performed.</comment>');
        }

        $output->writeln('Detecting violations...');
        $violations = $this->detectService->detect($types, $storeIds);
        $total      = count($violations);

        if ($total === 0) {
            $output->writeln('<info>No violations found.</info>');
            if (!$dryRun) {
                $this->notify($runId, [], 0, false);
            }
            return Command::SUCCESS;
        }

        $output->writeln("<comment>{$total} violation(s) found:</comment>");
        $this->renderTable($output, $violations);

        if ($dryRun) {
            return Command::SUCCESS;
        }

        // Write violations to event log
        $this->logViolations($runId, $violations, false);

        // Auto fix
        $fixedCount = 0;
        if ($this->config->isAutoFixEnabled()) {
            $output->writeln('<info>Auto-fix enabled — applying fixes...</info>');
            $fixedCount = $this->autoFix($violations, $storeIds, $output);
            // Update event log auto_fixed flag
            $this->markAutoFixed($runId);
        } elseif ($this->config->isAutoFixLogOnlyWhenDisabled()) {
            $output->writeln('<comment>Auto-fix disabled. Violations logged for review.</comment>');
        }

        $this->notify($runId, $violations, $fixedCount, false);

        return Command::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Apply fixes for all violations. Groups by type/attribute/store for batch efficiency.
     * Returns total fixed count.
     */
    private function autoFix(array $violations, array $storeIds, OutputInterface $output): int
    {
        // Group violations by type + attribute_code + store_id
        $groups = [];
        foreach ($violations as $v) {
            $key = $v['attribute_type'] . '|' . $v['attribute_code'] . '|' . $v['store_id'];
            $groups[$key][] = $v;
        }

        $totalFixed = 0;

        foreach ($groups as $key => $group) {
            [$attrType, $attrCode, $storeIdStr] = explode('|', $key, 3);
            $storeId   = (int) $storeIdStr;
            $entityIds = array_map(fn($v) => $v['entity_id'], $group);

            // Map attribute_type → FixService type
            $fixType   = match ($attrType) {
                'price'          => 'price',
                'image_role'     => 'image',
                'image_position' => 'image',
                'custom'         => 'custom',
                default          => null,
            };

            if ($fixType === null) {
                continue;
            }

            $imageType = match ($attrType) {
                'image_role'     => 'roles',
                'image_position' => 'position',
                default          => 'all',
            };

            try {
                $result = $this->fixService->fix(
                    $fixType,
                    $fixType !== 'image' ? $attrCode : '',
                    $imageType,
                    'id',
                    array_map('strval', $entityIds),
                    $storeId,
                    false,
                    false
                );
                $totalFixed += $result['fixed'];
                $output->writeln(sprintf(
                    '  Fixed <info>%d</info> row(s) for %s/%s store=%d',
                    $result['fixed'],
                    $fixType,
                    $attrCode ?: $imageType,
                    $storeId
                ));
            } catch (\Exception $e) {
                $output->writeln('<error>Auto-fix error for ' . $key . ': ' . $e->getMessage() . '</error>');
            }
        }

        return $totalFixed;
    }

    private function markAutoFixed(string $runId): void
    {
        try {
            $conn = $this->eventLogResource->getConnection();
            $conn->update(
                $this->eventLogResource->getMainTable(),
                ['auto_fixed' => 1],
                ['run_id = ?' => $runId]
            );
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    private function logViolations(string $runId, array $violations, bool $dryRun): void
    {
        foreach ($violations as $v) {
            try {
                $log = $this->eventLogFactory->create();
                $log->setData([
                    'entity_id'      => $v['entity_id'],
                    'store_id'       => $v['store_id'],
                    'attribute_type' => $v['attribute_type'],
                    'attribute_code' => $v['attribute_code'],
                    'old_value'      => $v['current_value'],  // the store-view override value (will be deleted)
                    'new_value'      => null,                 // after fix: row deleted, Magento uses global value
                    'auto_fixed'     => 0,
                    'run_id'         => $runId,
                    'dry_run'        => $dryRun ? 1 : 0,
                ]);
                $this->eventLogResource->save($log);
            } catch (\Exception $e) {
                // Non-fatal: log write failure should not abort the detect run
            }
        }
    }

    private function notify(string $runId, array $violations, int $fixedCount, bool $dryRun): void
    {
        try {
            $this->notificationService->sendReport($runId, $violations, $fixedCount, $dryRun);
        } catch (\Exception $e) {
            // Non-fatal: notification failure should not abort the detect run
        }
    }

    private function renderTable(OutputInterface $output, array $violations): void
    {
        $table = new Table($output);
        $table->setHeaders(['entity_id', 'sku', 'store_id', 'type', 'attribute', 'current_value', 'after_fix']);

        foreach ($violations as $v) {
            $table->addRow([
                $v['entity_id'],
                $v['sku'],
                $v['store_id'],
                $v['attribute_type'],
                $v['attribute_code'],
                $this->truncate((string) ($v['current_value'] ?? '')),
                '(global)',
            ]);
        }

        $table->render();
    }

    private function truncate(string $val, int $max = 40): string
    {
        return mb_strlen($val) > $max ? mb_substr($val, 0, $max - 3) . '...' : $val;
    }

    private function generateRunId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
