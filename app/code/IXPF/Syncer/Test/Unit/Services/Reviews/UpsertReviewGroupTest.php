<?php

declare(strict_types=1);

namespace IXPF\Syncer\Test\Unit\Services\Reviews;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Model\Queue\ReviewMediaMessageFactory;
use IXPF\Syncer\Services\Reviews\PayloadValidator;
use IXPF\Syncer\Services\Reviews\ReviewCacheCleaner;
use IXPF\Syncer\Services\Reviews\UpsertReviewGroup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use IXPF\Syncer\Helper\Logger\ReviewLogger;

class UpsertReviewGroupTest extends TestCase
{
    private UpsertReviewGroup $service;

    protected function setUp(): void
    {
        $this->service = new UpsertReviewGroup(
            $this->createStub(ResourceConnection::class),
            new PayloadValidator(),
            $this->createStub(Config::class),
            $this->createStub(ReviewCacheCleaner::class),
            $this->createStub(PublisherInterface::class),
            $this->createStub(ReviewMediaMessageFactory::class),
            $this->createStub(ReviewLogger::class)
        );
    }

    #[DataProvider('staleness')]
    public function testIsStale(string $incoming, string $stored, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->service->isStale(PayloadValidator::parseDate($incoming), $stored)
        );
    }

    public static function staleness(): array
    {
        return [
            'older is stale'            => ['2026-08-01T00:00:00Z', '2026-09-01 00:00:00', true],
            'equal is stale'            => ['2026-09-01T08:12:00Z', '2026-09-01 08:12:00', true],
            'newer is not stale'        => ['2026-09-02T00:00:00Z', '2026-09-01 00:00:00', false],
            'one second newer'          => ['2026-09-01T08:12:01Z', '2026-09-01 08:12:00', false],
            'one second older'          => ['2026-09-01T08:11:59Z', '2026-09-01 08:12:00', true],
            'offset normalised to UTC'  => ['2026-09-01T10:12:00+02:00', '2026-09-01 08:12:00', true],
            'offset newer than stored'  => ['2026-09-01T10:12:00+00:00', '2026-09-01 08:12:00', false],
        ];
    }

    public function testInvalidPayloadIsRejectedBeforeAnyDatabaseWork(): void
    {
        // ResourceConnection is a bare mock: any call to it would fail this test.
        $result = $this->service->upsert(['review_group' => '']);

        $this->assertSame('invalid', $result['status']);
        $this->assertSame('review_group', $result['field']);
    }
}
