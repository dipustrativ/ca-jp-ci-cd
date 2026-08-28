<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Service\AttributeOverride;

use CarpetAvenue\Core\Helper\Slack;
use CarpetAvenue\Core\Helper\SlackConfig;
use CarpetAvenue\Utilities\Helper\Config;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Sends detect-run reports via email and/or Slack Watcher channel.
 *
 * Slack: uses CarpetAvenue_Core watcher channel — no webhook config here.
 * Email: template carpetavenue_attribute_override_detect_report.
 */
class NotificationService
{
    public function __construct(
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly Slack $slack,
        private readonly SlackConfig $slackConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $runId
     * @param array  $violations  Rows from DetectService::detect()
     * @param int    $fixedCount  Violations auto-fixed in this run
     * @param bool   $dryRun
     */
    public function sendReport(string $runId, array $violations, int $fixedCount = 0, bool $dryRun = false): void
    {
        if ($this->config->isEmailNotificationEnabled()) {
            $this->sendEmail($runId, $violations, $fixedCount, $dryRun);
        }

        if ($this->config->isSlackNotificationEnabled() && $this->slackConfig->isEnabled()) {
            $this->sendSlack($runId, $violations, $fixedCount, $dryRun);
        }
    }

    // ── Email ─────────────────────────────────────────────────────────────────

    private function sendEmail(string $runId, array $violations, int $fixedCount, bool $dryRun): void
    {
        $recipients = $this->config->getNotificationEmailTo();
        if (empty($recipients)) {
            $this->logger->warning('[AttributeOverride] Email notification enabled but no recipients configured.');
            return;
        }

        $fromEmail = $this->config->getNotificationEmailFrom();
        $from      = $fromEmail !== ''
            ? ['email' => $fromEmail, 'name' => 'CarpetAvenue Utilities']
            : 'general';

        try {
            $this->inlineTranslation->suspend();

            $transport = $this->transportBuilder
                ->setTemplateIdentifier('carpetavenue_attribute_override_detect_report')
                ->setTemplateOptions([
                    'area'  => Area::AREA_ADMINHTML,
                    'store' => Store::DEFAULT_STORE_ID,
                ])
                ->setTemplateVars([
                    'run_id'         => $runId,
                    'violations'     => array_slice($violations, 0, 100),
                    'total'          => count($violations),
                    'rows_shown'     => min(count($violations), 100),
                    'rows_truncated' => count($violations) > 100,
                    'fixed'          => $fixedCount,
                    'pending'        => count($violations) - $fixedCount,
                    'dry_run'        => $dryRun,
                ])
                ->setFrom($from)
                ->addTo($recipients[0]);

            // addTo only takes one address per call in some Magento versions
            foreach (array_slice($recipients, 1) as $email) {
                $this->transportBuilder->addTo(trim($email));
            }

            $transport->getTransport()->sendMessage();

        } catch (\Exception $e) {
            $this->logger->error('[AttributeOverride] Email notification failed: ' . $e->getMessage());
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    // ── Slack ─────────────────────────────────────────────────────────────────

    private function sendSlack(string $runId, array $violations, int $fixedCount, bool $dryRun): void
    {
        try {
            $total   = count($violations);
            $pending = $total - $fixedCount;
            $prefix  = $dryRun ? '[DRY RUN] ' : '';

            if ($total === 0) {
                $icon    = ':white_check_mark:';
                $summary = "{$prefix}{$icon} *Attribute Override Detect* — no violations found.";
            } elseif ($pending > 0) {
                $icon    = ':warning:';
                $summary = "{$prefix}{$icon} *Attribute Override Detect* — {$total} violation(s), {$fixedCount} auto-fixed, *{$pending} still pending*.";
            } else {
                $icon    = ':wrench:';
                $summary = "{$prefix}{$icon} *Attribute Override Detect* — {$total} violation(s), all {$fixedCount} auto-fixed.";
            }

            $blocks = [
                $this->slack->headerBlock('Attribute Override Detect Report'),
                $this->slack->sectionBlock($summary),
                $this->slack->fieldsBlock([
                    'Run ID'     => $runId,
                    'Violations' => (string) $total,
                    'Auto-fixed' => (string) $fixedCount,
                    'Pending'    => (string) $pending,
                    'Dry Run'    => $dryRun ? 'Yes' : 'No',
                ]),
            ];

            if ($total > 0) {
                $lines = [];
                foreach (array_slice($violations, 0, 5) as $v) {
                    $lines[] = sprintf(
                        '`%s` · SKU %s · store %d · `%s` → `%s`',
                        $v['attribute_code'],
                        $v['sku'],
                        $v['store_id'],
                        $this->truncate((string) ($v['current_value'] ?? 'null')),
                        $this->truncate((string) ($v['intended_value'] ?? 'null'))
                    );
                }
                if ($total > 5) {
                    $lines[] = '_...and ' . ($total - 5) . ' more_';
                }
                $blocks[] = $this->slack->contextBlock($lines);
            }

            $this->slack->toWatcher()->sendBlocks($blocks, $summary);

        } catch (\Exception $e) {
            $this->logger->error('[AttributeOverride] Slack notification failed: ' . $e->getMessage());
        }
    }

    private function truncate(string $val, int $max = 40): string
    {
        return mb_strlen($val) > $max ? mb_substr($val, 0, $max - 3) . '...' : $val;
    }
}
