<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reviews;

use DateTimeImmutable;
use Exception;
use IXPF\Syncer\Model\Reviews\TextEncoding;

/**
 * Validates an IXPF review payload before anything is written.
 *
 * Pure: no DB, no config, no logging. Returns null when the payload is acceptable, or
 * ['message' => ..., 'field' => ...] naming the first field that failed.
 *
 * Locale keys are accepted as sent and NOT validated against Magento store locales — IXPF
 * pushes locales for markets that are not live yet. An unknown locale is stored and simply
 * never selected at read time.
 */
class PayloadValidator
{
    private const MAX_GROUP_KEY_LENGTH = 64;

    /**
     * Charset-safe review id. It is the upsert's unique key and a directory name under
     * ixpf/reviews/, so nothing that could escape that directory or be cut short gets in.
     */
    private const REVIEW_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

    /**
     * Column widths from etc/db_schema.xml, in characters. Magento runs with an empty SQL_MODE,
     * so an overlong value is not an error at write time - it is truncated silently. On a key
     * that is worse than lost text: a cut locale drops or collides text rows, a cut path never
     * equals the payload again and is re-fetched on every push.
     */
    private const MAX_LOCALE_LENGTH = 12;
    private const MAX_SKU_LENGTH = 64;
    private const MAX_SOURCE_LENGTH = 16;
    private const MAX_STORE_CODE_LENGTH = 32;
    private const MAX_TEXT_LENGTH = 255;
    private const MAX_PATH_LENGTH = 500;

    private const SUB_KEYS = ['quality', 'delivery', 'customer_service'];

    /**
     * @return array{message: string, field: string}|null
     */
    public function validate(mixed $payload): ?array
    {
        if (!is_array($payload) || $payload === []) {
            return $this->fail('Payload must be a non-empty JSON object', 'payload');
        }

        $error = $this->validateGroup($payload);
        if ($error !== null) {
            return $error;
        }

        if (!isset($payload['reviews']) || !is_array($payload['reviews'])) {
            return $this->fail('reviews is required and must be an array', 'reviews');
        }

        $seenIds = [];
        foreach (array_values($payload['reviews']) as $i => $review) {
            $error = $this->validateReview($review, $i, $seenIds);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Accepts an ISO 8601 timestamp and returns it, or null when unparseable.
     *
     * Anchored on a leading calendar date so DateTimeImmutable's relative formats ("now",
     * "tomorrow") cannot slip through as valid timestamps.
     */
    public static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]/', $value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return array{message: string, field: string}|null
     */
    private function validateGroup(array $payload): ?array
    {
        $group = $payload['review_group'] ?? null;
        if (!is_string($group) || trim($group) === '') {
            return $this->fail('review_group is required and must be a non-empty string', 'review_group');
        }
        if ($this->isTooLong($group, self::MAX_GROUP_KEY_LENGTH)) {
            return $this->fail(
                'review_group must be at most ' . self::MAX_GROUP_KEY_LENGTH . ' characters',
                'review_group'
            );
        }

        if (self::parseDate($payload['generated_at'] ?? null) === null) {
            return $this->fail('generated_at is required and must be a parseable ISO 8601 timestamp', 'generated_at');
        }

        if (!$this->isInt($payload['count'] ?? null) || (int)$payload['count'] < 0) {
            return $this->fail('count is required and must be an integer >= 0', 'count');
        }

        // Optional, unlike count: it arrived with the 2026-09-07 spec and a push predating that
        // must not start failing. Absent means 0, which reads as "no written reviews yet".
        if (isset($payload['with_text_count'])
            && (!$this->isInt($payload['with_text_count']) || (int)$payload['with_text_count'] < 0)
        ) {
            return $this->fail('with_text_count must be an integer >= 0', 'with_text_count');
        }

        if (!$this->isRating($payload['average'] ?? null, 0.0)) {
            return $this->fail('average is required and must be numeric between 0 and 5', 'average');
        }

        $distribution = $payload['distribution'] ?? null;
        if (!is_array($distribution) || count($distribution) !== 5) {
            return $this->fail('distribution is required and must be an array of exactly 5 values', 'distribution');
        }
        foreach (array_values($distribution) as $i => $value) {
            if (!$this->isInt($value) || (int)$value < 0) {
                return $this->fail('distribution values must be non-negative integers', "distribution[$i]");
            }
        }

        if (isset($payload['sub_averages'])) {
            if (!is_array($payload['sub_averages'])) {
                return $this->fail('sub_averages must be an object', 'sub_averages');
            }
            foreach ($payload['sub_averages'] as $key => $value) {
                if (!$this->isRating($value, 0.0)) {
                    return $this->fail('sub_averages values must be numeric between 0 and 5', "sub_averages.$key");
                }
            }
        }

        if (isset($payload['group_label'])) {
            $error = $this->validateLocaleMap($payload['group_label'], 'group_label');
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $seenIds
     * @return array{message: string, field: string}|null
     */
    private function validateReview(mixed $review, int $i, array &$seenIds): ?array
    {
        if (!is_array($review)) {
            return $this->fail('Review must be an object', "reviews[$i]");
        }

        $id = $review['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            return $this->fail('id is required and must be a non-empty string', "reviews[$i].id");
        }
        if (!preg_match(self::REVIEW_ID_PATTERN, $id)) {
            return $this->fail(
                'id must be 1-32 characters of A-Z, a-z, 0-9, underscore or hyphen',
                "reviews[$i].id"
            );
        }
        if (isset($seenIds[$id])) {
            return $this->fail("Duplicate review id '$id' within the payload", "reviews[$i].id");
        }
        $seenIds[$id] = true;

        if (!$this->isRating($review['rating'] ?? null, 1.0) || !$this->isInt($review['rating'])) {
            return $this->fail('rating is required and must be an integer between 1 and 5', "reviews[$i].rating");
        }

        if (self::parseDate($review['created_at'] ?? null) === null) {
            return $this->fail('created_at is required and must be ISO 8601', "reviews[$i].created_at");
        }

        $locale = $review['source_locale'] ?? null;
        if (!is_string($locale) || trim($locale) === '') {
            return $this->fail('source_locale is required', "reviews[$i].source_locale");
        }

        // Optional fields: absent is fine, present-and-overlong is not.
        $lengths = [
            'source_locale'         => [$locale, self::MAX_LOCALE_LENGTH],
            'sku'                   => [$review['sku'] ?? null, self::MAX_SKU_LENGTH],
            'source'                => [$review['source'] ?? null, self::MAX_SOURCE_LENGTH],
            'source_store_code'     => [$review['source_store_code'] ?? null, self::MAX_STORE_CODE_LENGTH],
            // Measured as stored: an emoji counts as its &#x...; reference, not as one character.
            'reviewer'              => [$this->encoded($review['reviewer'] ?? null), self::MAX_TEXT_LENGTH],
            'bought.name'           => [$this->encoded($review['bought']['name'] ?? null), self::MAX_TEXT_LENGTH],
            'bought.thumbnail_path' => [$review['bought']['thumbnail_path'] ?? null, self::MAX_PATH_LENGTH],
        ];
        foreach ($lengths as $field => [$value, $max]) {
            if ($this->isTooLong($value, $max)) {
                return $this->fail("$field must be at most $max characters", "reviews[$i].$field");
            }
        }

        // body only. IXPF dropped `title` from the payload in the 2026-09-07 spec; a stray one
        // is simply ignored rather than rejected, since they may not strip it on every path.
        $error = $this->validateLocaleMap($review['body'] ?? null, "reviews[$i].body", self::MAX_LOCALE_LENGTH);
        if ($error !== null) {
            return $error;
        }
        if (!isset($review['body'][$locale])) {
            return $this->fail(
                "source_locale '$locale' must appear as a key in body",
                "reviews[$i].body.$locale"
            );
        }

        if (isset($review['sub_ratings'])) {
            if (!is_array($review['sub_ratings'])) {
                return $this->fail('sub_ratings must be an object', "reviews[$i].sub_ratings");
            }
            foreach (self::SUB_KEYS as $key) {
                if (!isset($review['sub_ratings'][$key])) {
                    continue;
                }
                $value = $review['sub_ratings'][$key];
                if (!$this->isInt($value) || !$this->isRating($value, 1.0)) {
                    return $this->fail(
                        'sub_ratings values must be integers between 1 and 5',
                        "reviews[$i].sub_ratings.$key"
                    );
                }
            }
        }

        if (isset($review['photos'])) {
            if (!is_array($review['photos'])) {
                return $this->fail('photos must be an array', "reviews[$i].photos");
            }
            foreach (array_values($review['photos']) as $p => $photo) {
                if (!is_array($photo)) {
                    return $this->fail('Photo must be an object', "reviews[$i].photos[$p]");
                }
                if (!is_string($photo['bucket_path'] ?? null) || trim($photo['bucket_path']) === '') {
                    return $this->fail('bucket_path is required', "reviews[$i].photos[$p].bucket_path");
                }
                if ($this->isTooLong($photo['bucket_path'], self::MAX_PATH_LENGTH)) {
                    return $this->fail(
                        'bucket_path must be at most ' . self::MAX_PATH_LENGTH . ' characters',
                        "reviews[$i].photos[$p].bucket_path"
                    );
                }
            }
        }

        if (isset($review['reply']) && $review['reply'] !== null) {
            if (!is_array($review['reply'])) {
                return $this->fail('reply must be an object or null', "reviews[$i].reply");
            }
            $error = $this->validateLocaleMap(
                $review['reply']['body'] ?? null,
                "reviews[$i].reply.body",
                self::MAX_LOCALE_LENGTH
            );
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @return array{message: string, field: string}|null
     */
    private function validateLocaleMap(mixed $map, string $field, ?int $maxLocaleLength = null): ?array
    {
        if (!is_array($map) || $map === []) {
            return $this->fail("$field is required and must be an object of locale to string", $field);
        }
        foreach ($map as $locale => $value) {
            if (!is_string($locale) || $locale === '' || !is_string($value)) {
                return $this->fail("$field must map locale strings to strings", "$field.$locale");
            }
            // Body and reply keys become ixpf_review_text.locale, part of its primary key.
            if ($maxLocaleLength !== null && $this->isTooLong($locale, $maxLocaleLength)) {
                return $this->fail("$field locale keys must be at most $maxLocaleLength characters", "$field.$locale");
            }
        }

        return null;
    }

    /**
     * Characters, not bytes: varchar(N) holds N characters, and a Japanese name must not be
     * held to a third of that.
     */
    private function isTooLong(mixed $value, int $max): bool
    {
        return is_scalar($value) && mb_strlen((string)$value, 'UTF-8') > $max;
    }

    private function encoded(mixed $value): mixed
    {
        return is_string($value) ? TextEncoding::encode($value) : $value;
    }

    private function isInt(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    private function isRating(mixed $value, float $min): bool
    {
        return is_numeric($value) && (float)$value >= $min && (float)$value <= 5.0;
    }

    /**
     * @return array{message: string, field: string}
     */
    private function fail(string $message, string $field): array
    {
        return ['message' => $message, 'field' => $field];
    }
}
