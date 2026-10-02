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

namespace CarpetAvenue\Productify\Service\Core;

use CarpetAvenue\Productify\Helper\Logger;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Progress of one build phase for one store
 *
 * Callable as ($done, $total), so it can be handed straight to CollectionBuilder,
 * ProductUrlRewrite and ProductifyCollection::saveMany(). Draws a console bar when a
 * CLI output is attached, and always writes to the productify log: once when the
 * phase starts (with its total), at most every LOG_INTERVAL seconds while it runs,
 * and once when it finishes. Cron and admin runs have no console, so the log is the
 * only place their progress shows.
 */
class BuildProgress
{
    /**
     * Seconds between log lines (and non-TTY bar redraws) while a phase runs.
     */
    private const LOG_INTERVAL = 10;

    /**
     * @var ProgressBar|null
     */
    private ?ProgressBar $bar = null;

    /**
     * @var bool
     */
    private bool $started = false;

    /**
     * @var bool
     */
    private bool $finished = false;

    /**
     * @var int Total announced when the phase started
     */
    private int $total = 0;

    /**
     * @var float
     */
    private float $start;

    /**
     * @var float
     */
    private float $lastLog;

    /**
     * @param Logger $logger
     * @param OutputInterface|null $output
     * @param string $phase
     * @param string $storeLabel
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ?OutputInterface $output,
        private readonly string $phase,
        private readonly string $storeLabel
    ) {
        $this->start = $this->lastLog = microtime(true);
    }

    /**
     * Report progress; the first call starts the phase, reaching $total finishes it
     *
     * @param int $done
     * @param int $total
     * @return void
     */
    public function __invoke(int $done, int $total): void
    {
        if ($this->finished) {
            return;
        }
        if (!$this->started) {
            $this->begin($total);
        }
        if ($done >= $total) {
            $this->finish($done);
            return;
        }

        $this->bar?->setProgress($done);
        $now = microtime(true);
        if ($now - $this->lastLog >= self::LOG_INTERVAL) {
            $this->lastLog = $now;
            $this->log(sprintf(
                '%d/%d (%.0fs, %dMB).',
                $done,
                $this->total,
                $now - $this->start,
                memory_get_usage(true) / 1048576
            ));
        }
    }

    /**
     * End the phase with its real count; a no-op once it has finished
     *
     * Callers invoke this after every phase: when fewer items arrive than announced
     * (a product deleted mid-run, or an error that ended the phase early), the total
     * is never reached, and without this the bar would stay open with no "done" line.
     * The log keeps the announced total, so a short count is visible there.
     *
     * @param int $done
     * @return void
     */
    public function finish(int $done): void
    {
        if ($this->finished) {
            return;
        }
        if (!$this->started) {
            $this->begin($done);
        }
        $this->finished = true;

        if ($this->bar) {
            if ($done < $this->bar->getMaxSteps()) {
                // finish() would jump to the announced total; show the real count.
                $this->bar->setMaxSteps($done);
            }
            $this->bar->setProgress($done);
            $this->bar->finish();
            $this->output->writeln('');
        }
        $this->log(sprintf('%d/%d done in %.1fs.', $done, $this->total, microtime(true) - $this->start));
    }

    /**
     * Log the start line and open the console bar
     *
     * @param int $total
     * @return void
     */
    private function begin(int $total): void
    {
        $this->started = true;
        $this->total = $total;
        $this->log("0/{$total} started.");

        if ($this->output) {
            $this->bar = new ProgressBar($this->output, $total);
            $this->bar->setFormat(' %message:-18s% %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %memory:6s%');
            $this->bar->setMessage($this->phase);
            if (!$this->output->isDecorated()) {
                // Piped or non-TTY output prints a new line per redraw.
                $this->bar->maxSecondsBetweenRedraws(self::LOG_INTERVAL);
            }
            $this->bar->start();
        }
    }

    /**
     * Write one progress line to the productify log
     *
     * @param string $message
     * @return void
     */
    private function log(string $message): void
    {
        $this->logger->info("Productify : [Queue] {$this->storeLabel}: {$this->phase} {$message}");
    }
}
