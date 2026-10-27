<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Order
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Console;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Step messages and a progress bar for the import commands, so a long run shows
 * it is alive: which step it is on, how far through the file, how long so far.
 *
 * Usage: step() before each phase, then pass callback() to the importer as its
 * $onProgress, then finish().
 */
class ImportProgress
{
    private float $startedAt;
    private ?ProgressBar $bar = null;

    public function __construct(
        private readonly OutputInterface $output,
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * One line announcing a phase, stamped with the time spent so far.
     */
    public function step(string $message): void
    {
        $this->output->writeln(sprintf('<comment>[%s]</comment> %s', $this->elapsed(), $message));
    }

    /**
     * The importer's $onProgress: draws the bar on the first call, advances it
     * on every call after.
     *
     * @return callable(int $done, int $total, string $incrementId): void
     */
    public function callback(string $unit): callable
    {
        return function (int $done, int $total, string $incrementId) use ($unit): void {
            if ($this->bar === null) {
                $this->bar = new ProgressBar($this->output, $total);
                $this->bar->setFormat(
                    ' %current%/%max% ' . $unit . ' [%bar%] %percent:3s%% | %elapsed:6s% / ~%estimated:-6s%'
                    . ' | %memory:6s% | %message%'
                );
                // Piped to a file the bar prints a line per redraw; keep that readable.
                $this->bar->minSecondsBetweenRedraws(0.5);
                $this->bar->maxSecondsBetweenRedraws(2);
                $this->bar->setMessage('');
                $this->bar->start();
            }
            $this->bar->setMessage($incrementId);
            $this->bar->setProgress($done);
        };
    }

    /**
     * Close the bar (if one was drawn) and print a closing step line.
     */
    public function finish(string $message = 'Done.'): void
    {
        if ($this->bar !== null) {
            $this->bar->setMessage('');
            $this->bar->finish();
            $this->output->writeln('');
            $this->bar = null;
        }
        $this->step($message);
    }

    private function elapsed(): string
    {
        $seconds = (int)round(microtime(true) - $this->startedAt);
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
