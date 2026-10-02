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

namespace CarpetAvenue\Productify\Test\Unit\Service\Core;

use CarpetAvenue\Productify\Helper\Logger;
use CarpetAvenue\Productify\Service\Core\BuildProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class BuildProgressTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $logged = [];

    public static function setUpBeforeClass(): void
    {
        // Logger declares its log directory from Magento's bootstrap constant.
        // Focused PHPUnit runs do not load app/bootstrap.php.
        if (!defined('BP')) {
            define('BP', sys_get_temp_dir());
        }
    }

    public function testLogsStartAndFinishAndDrawsTheBar(): void
    {
        $output = new BufferedOutput();
        $progress = $this->progress($output);

        $progress(0, 3);
        $progress(1, 3);
        $progress(2, 3);
        $progress(3, 3);
        $progress->finish(3); // already finished: no second line

        self::assertCount(2, $this->logged);
        self::assertSame('Productify : [Queue] store ID 1 (jp): Saving 0/3 started.', $this->logged[0]);
        self::assertMatchesRegularExpression('#: Saving 3/3 done in [0-9.]+s\.$#', $this->logged[1]);
        self::assertStringContainsString('3/3', $output->fetch());
    }

    public function testFinishClosesAPhaseThatFellShortOfItsTotal(): void
    {
        $output = new BufferedOutput();
        $progress = $this->progress($output);

        $progress(0, 5);
        $progress(3, 5); // two items never arrived
        $progress->finish(3);

        self::assertMatchesRegularExpression('#: Saving 3/5 done in #', $this->logged[1]);
        $screen = $output->fetch();
        self::assertStringContainsString('3/3', $screen, 'The bar ends on the real count');
        self::assertStringNotContainsString('5/5', $screen);
    }

    public function testEmptyPhaseStartsAndFinishesAtOnce(): void
    {
        $progress = $this->progress(null);

        $progress(0, 0);

        self::assertCount(2, $this->logged);
        self::assertStringEndsWith('Saving 0/0 started.', $this->logged[0]);
        self::assertMatchesRegularExpression('#Saving 0/0 done in #', $this->logged[1]);
    }

    public function testFinishWithoutAnyProgressStillLogsBothLines(): void
    {
        $progress = $this->progress(null);

        $progress->finish(4);

        self::assertStringEndsWith('Saving 0/4 started.', $this->logged[0]);
        self::assertMatchesRegularExpression('#Saving 4/4 done in #', $this->logged[1]);
    }

    private function progress(?BufferedOutput $output): BuildProgress
    {
        $logger = $this->createStub(Logger::class);
        $logger->method('info')->willReturnCallback(function ($message): void {
            $this->logged[] = (string) $message;
        });
        return new BuildProgress($logger, $output, 'Saving', 'store ID 1 (jp)');
    }
}
