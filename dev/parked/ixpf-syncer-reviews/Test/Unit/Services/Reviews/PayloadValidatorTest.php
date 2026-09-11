<?php

declare(strict_types=1);

namespace IXPF\Syncer\Test\Unit\Services\Reviews;

use IXPF\Syncer\Services\Reviews\PayloadValidator;
use PHPUnit\Framework\TestCase;

class PayloadValidatorTest extends TestCase
{
    private PayloadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PayloadValidator();
    }

    public function testRealFixturePasses(): void
    {
        $this->assertNull($this->validator->validate($this->fixture()));
    }

    public function testEmptyReviewsArrayIsValid(): void
    {
        $payload = $this->fixture();
        $payload['reviews'] = [];

        $this->assertNull($this->validator->validate($payload));
    }

    /**
     * @dataProvider groupLevelFailures
     */
    public function testGroupLevelRule(string $key, mixed $value, string $expectedField): void
    {
        $payload = $this->fixture();
        if ($value === '__unset__') {
            unset($payload[$key]);
        } else {
            $payload[$key] = $value;
        }

        $error = $this->validator->validate($payload);

        $this->assertNotNull($error, "Expected $key to be rejected");
        $this->assertSame($expectedField, $error['field']);
    }

    public static function groupLevelFailures(): array
    {
        return [
            'review_group missing'      => ['review_group', '__unset__', 'review_group'],
            'review_group empty'        => ['review_group', '  ', 'review_group'],
            'review_group not string'   => ['review_group', 123, 'review_group'],
            'review_group too long'     => ['review_group', str_repeat('a', 65), 'review_group'],
            'generated_at missing'      => ['generated_at', '__unset__', 'generated_at'],
            'generated_at unparseable'  => ['generated_at', 'not-a-date', 'generated_at'],
            'generated_at relative'     => ['generated_at', 'now', 'generated_at'],
            'count missing'             => ['count', '__unset__', 'count'],
            'count negative'            => ['count', -1, 'count'],
            'count not int'             => ['count', 1.5, 'count'],
            'average missing'           => ['average', '__unset__', 'average'],
            'average above range'       => ['average', 5.1, 'average'],
            'average below range'       => ['average', -0.1, 'average'],
            'average not numeric'       => ['average', 'four', 'average'],
            'distribution missing'      => ['distribution', '__unset__', 'distribution'],
            'distribution wrong size'   => ['distribution', [1, 2, 3, 4], 'distribution'],
            'distribution negative'     => ['distribution', [0, 0, -1, 0, 2], 'distribution[2]'],
            'distribution not int'      => ['distribution', [0, 0, 'x', 0, 2], 'distribution[2]'],
            'sub_averages out of range' => ['sub_averages', ['quality' => 9], 'sub_averages.quality'],
            'group_label not a map'     => ['group_label', 'Hamadan', 'group_label'],
            'group_label nested value'  => ['group_label', ['en_US' => ['x']], 'group_label.en_US'],
            'reviews missing'           => ['reviews', '__unset__', 'reviews'],
            'reviews not array'         => ['reviews', 'many', 'reviews'],
        ];
    }

    /**
     * @dataProvider reviewLevelFailures
     */
    public function testReviewLevelRule(callable $mutate, string $expectedField): void
    {
        $payload = $this->fixture();
        $mutate($payload);

        $error = $this->validator->validate($payload);

        $this->assertNotNull($error, "Expected $expectedField to be rejected");
        $this->assertSame($expectedField, $error['field']);
    }

    public static function reviewLevelFailures(): array
    {
        return [
            'id missing' => [
                static function (array &$p): void {
                    unset($p['reviews'][0]['id']);
                },
                'reviews[0].id',
            ],
            'id empty' => [
                static function (array &$p): void {
                    $p['reviews'][0]['id'] = '';
                },
                'reviews[0].id',
            ],
            'id duplicated within payload' => [
                static function (array &$p): void {
                    $p['reviews'][1]['id'] = $p['reviews'][0]['id'];
                },
                'reviews[1].id',
            ],
            'rating missing' => [
                static function (array &$p): void {
                    unset($p['reviews'][1]['rating']);
                },
                'reviews[1].rating',
            ],
            'rating zero' => [
                static function (array &$p): void {
                    $p['reviews'][1]['rating'] = 0;
                },
                'reviews[1].rating',
            ],
            'rating above five' => [
                static function (array &$p): void {
                    $p['reviews'][1]['rating'] = 6;
                },
                'reviews[1].rating',
            ],
            'rating not an integer' => [
                static function (array &$p): void {
                    $p['reviews'][1]['rating'] = 4.5;
                },
                'reviews[1].rating',
            ],
            'created_at unparseable' => [
                static function (array &$p): void {
                    $p['reviews'][0]['created_at'] = 'yesterday';
                },
                'reviews[0].created_at',
            ],
            'source_locale missing' => [
                static function (array &$p): void {
                    unset($p['reviews'][0]['source_locale']);
                },
                'reviews[0].source_locale',
            ],
            'source_locale absent from body' => [
                static function (array &$p): void {
                    unset($p['reviews'][0]['body']['da_DK']);
                },
                'reviews[0].body.da_DK',
            ],
            'body missing entirely' => [
                static function (array &$p): void {
                    unset($p['reviews'][0]['body']);
                },
                'reviews[0].body',
            ],
            'sub_ratings out of range' => [
                static function (array &$p): void {
                    $p['reviews'][0]['sub_ratings']['delivery'] = 7;
                },
                'reviews[0].sub_ratings.delivery',
            ],
            'photo without bucket_path' => [
                static function (array &$p): void {
                    unset($p['reviews'][0]['photos'][0]['bucket_path']);
                },
                'reviews[0].photos[0].bucket_path',
            ],
            'photo bucket_path empty' => [
                static function (array &$p): void {
                    $p['reviews'][0]['photos'][0]['bucket_path'] = '';
                },
                'reviews[0].photos[0].bucket_path',
            ],
            'reply body not a locale map' => [
                static function (array &$p): void {
                    $p['reviews'][0]['reply']['body'] = 'thanks';
                },
                'reviews[0].reply.body',
            ],
        ];
    }

    public function testNullReplyIsAccepted(): void
    {
        $payload = $this->fixture();
        $payload['reviews'][0]['reply'] = null;

        $this->assertNull($this->validator->validate($payload));
    }

    public function testUnknownLocaleIsAcceptedNotRejected(): void
    {
        // IXPF pushes locales for markets that are not live yet.
        $payload = $this->fixture();
        $payload['reviews'][0]['body']['xx_XX'] = 'Stored, never selected at read time';

        $this->assertNull($this->validator->validate($payload));
    }

    public function testStrayTitleIsIgnoredNotRejected(): void
    {
        // IXPF removed `title` from the 2026-09-07 spec. Nothing reads or stores it any more, and
        // an old caller that still sends one must not get a 400 for it.
        $payload = $this->fixture();
        $payload['reviews'][0]['title'] = ['da_DK' => 'God kvalitet'];

        $this->assertNull($this->validator->validate($payload));
    }

    public function testNonArrayPayloadIsRejected(): void
    {
        $error = $this->validator->validate('not json');

        $this->assertNotNull($error);
        $this->assertSame('payload', $error['field']);
    }

    public function testParseDateRejectsRelativeFormats(): void
    {
        $this->assertNull(PayloadValidator::parseDate('now'));
        $this->assertNull(PayloadValidator::parseDate('tomorrow'));
        $this->assertNull(PayloadValidator::parseDate(''));
        $this->assertNull(PayloadValidator::parseDate(null));
        $this->assertNull(PayloadValidator::parseDate('2026-13-45T00:00:00Z'));
    }

    public function testParseDateAcceptsIso8601(): void
    {
        $date = PayloadValidator::parseDate('2026-08-27T08:12:00Z');

        $this->assertNotNull($date);
        $this->assertSame('2026-08-27 08:12:00', $date->format('Y-m-d H:i:s'));
    }

    private function fixture(): array
    {
        $json = file_get_contents(__DIR__ . '/../../../fixture/review_group_8518C.json');

        return json_decode((string)$json, true);
    }
}
