<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model\PaymentIcon;

/**
 * Lightweight SVG sanitizer for admin-uploaded payment icons.
 *
 * Strips scripts, event handlers and javascript: URIs so an uploaded SVG cannot
 * carry active content. Self-contained — no external composer dependency. Icons
 * are uploaded by trusted admins (ACL-gated), so this is defence in depth, not
 * the only control.
 */
class SvgSanitizer
{
    /**
     * Sanitize an SVG file in place. No-op (returns true) for non-SVG paths.
     *
     * @param string $absolutePath
     * @return bool true if the file was readable/writable and handled
     */
    public function sanitizeFile(string $absolutePath): bool
    {
        if (strtolower((string)pathinfo($absolutePath, PATHINFO_EXTENSION)) !== 'svg') {
            return true;
        }
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return false;
        }

        $content = (string)file_get_contents($absolutePath);
        $clean = $this->sanitizeString($content);

        if ($clean === $content) {
            return true;
        }
        return file_put_contents($absolutePath, $clean) !== false;
    }

    /**
     * Sanitize raw SVG markup.
     *
     * @param string $svg
     * @return string
     */
    public function sanitizeString(string $svg): string
    {
        // Remove <script>...</script> blocks.
        $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg);
        // Remove dangerous elements that can execute or embed markup.
        $svg = preg_replace('#<\s*(foreignObject|iframe|embed|object)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $svg);
        $svg = preg_replace('#<\s*(foreignObject|iframe|embed|object)\b[^>]*/?>#is', '', $svg);
        // Remove inline event handlers: on*="..." / on*=\'...\' / on*=bare.
        $svg = preg_replace('#\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $svg);
        // Neutralise javascript: in href / xlink:href.
        $svg = preg_replace('#(href|xlink:href)\s*=\s*("|\')\s*javascript:[^"\']*\2#is', '$1=$2#$2', $svg);

        return (string)$svg;
    }
}
