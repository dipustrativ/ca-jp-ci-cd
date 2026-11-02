<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Reviews;

/**
 * Keeps emoji in review text alive through utf8mb3.
 *
 * The review tables and Magento's default connection (SET NAMES utf8) are utf8mb3, which has no
 * room for 4-byte characters. MySQL 8 with Magento's empty SQL_MODE silently cuts the value at the
 * first one - a review with an emoji in its first sentence lost everything after it - and MariaDB
 * writes "?" instead. So every character above U+FFFF is stored as an ASCII numeric reference
 * (🤩 -> &#x1F929;) and turned back on read. Anything in the BMP is left alone.
 */
final class TextEncoding
{
    public static function encode(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $encoded = preg_replace_callback(
            '/[\x{10000}-\x{10FFFF}]/u',
            static fn (array $m): string => sprintf('&#x%X;', mb_ord($m[0], 'UTF-8')),
            $text
        );

        // null means the input was not valid UTF-8; store it as received rather than lose it.
        return $encoded ?? $text;
    }

    /**
     * Only references above U+FFFF are decoded - exactly what encode() writes - so a customer who
     * literally typed "&amp;" or "&#x41;" still sees what they typed.
     */
    public static function decode(?string $text): ?string
    {
        if ($text === null || !str_contains($text, '&#x')) {
            return $text;
        }

        return preg_replace_callback(
            '/&#x(10[0-9A-F]{4}|[1-9A-F][0-9A-F]{4});/i',
            static fn (array $m): string => (string)mb_chr((int)hexdec($m[1]), 'UTF-8'),
            $text
        ) ?? $text;
    }
}
