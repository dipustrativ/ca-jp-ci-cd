<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Docs;

/**
 * Minimal, dependency-free Markdown -> HTML renderer for the admin docs viewer.
 * Supports: fenced code blocks, ATX headings, GFM pipe tables, ordered/unordered
 * lists, blockquotes, horizontal rules, paragraphs, and inline code/bold/italic/links.
 *
 * All text is HTML-escaped before transformation, so raw doc content cannot inject
 * markup. Only the tags this renderer emits are allowed through.
 */
class MarkdownRenderer
{
    public function render(string $markdown): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $html  = [];
        $i     = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Fenced code block
            if (preg_match('/^\s*```/', $line)) {
                $buf = [];
                $i++;
                while ($i < $count && !preg_match('/^\s*```/', $lines[$i])) {
                    $buf[] = $this->esc($lines[$i]);
                    $i++;
                }
                $i++; // skip closing fence
                $html[] = '<pre class="ixpf-md-pre"><code>' . implode("\n", $buf) . '</code></pre>';
                continue;
            }

            // Blank line
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Horizontal rule
            if (preg_match('/^\s*([-*_])\1{2,}\s*$/', $line)) {
                $html[] = '<hr/>';
                $i++;
                continue;
            }

            // Heading
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $level  = strlen($m[1]);
                $html[] = "<h{$level} class=\"ixpf-md-h\">" . $this->inline($m[2]) . "</h{$level}>";
                $i++;
                continue;
            }

            // GFM table: header row + separator row of ---|---
            if (str_contains($line, '|') && isset($lines[$i + 1])
                && preg_match('/^\s*\|?[\s:\-\|]+\|?\s*$/', $lines[$i + 1])
                && str_contains($lines[$i + 1], '-')) {
                [$tableHtml, $consumed] = $this->table($lines, $i);
                $html[] = $tableHtml;
                $i += $consumed;
                continue;
            }

            // Blockquote
            if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
                $buf = [];
                while ($i < $count && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $mm)) {
                    $buf[] = $this->inline($mm[1]);
                    $i++;
                }
                $html[] = '<blockquote class="ixpf-md-quote">' . implode('<br/>', $buf) . '</blockquote>';
                continue;
            }

            // Unordered list
            if (preg_match('/^\s*[-*+]\s+/', $line)) {
                $buf = [];
                while ($i < $count && preg_match('/^\s*[-*+]\s+(.*)$/', $lines[$i], $mm)) {
                    $buf[] = '<li>' . $this->inline($mm[1]) . '</li>';
                    $i++;
                }
                $html[] = '<ul class="ixpf-md-ul">' . implode('', $buf) . '</ul>';
                continue;
            }

            // Ordered list
            if (preg_match('/^\s*\d+\.\s+/', $line)) {
                $buf = [];
                while ($i < $count && preg_match('/^\s*\d+\.\s+(.*)$/', $lines[$i], $mm)) {
                    $buf[] = '<li>' . $this->inline($mm[1]) . '</li>';
                    $i++;
                }
                $html[] = '<ol class="ixpf-md-ol">' . implode('', $buf) . '</ol>';
                continue;
            }

            // Paragraph (collect consecutive plain lines)
            $buf = [];
            while ($i < $count && trim($lines[$i]) !== ''
                && !preg_match('/^(#{1,6}\s|\s*```|\s*[-*+]\s|\s*\d+\.\s|\s*>)/', $lines[$i])
                && !preg_match('/^\s*([-*_])\1{2,}\s*$/', $lines[$i])) {
                $buf[] = $this->inline($lines[$i]);
                $i++;
            }
            if ($buf) {
                $html[] = '<p>' . implode('<br/>', $buf) . '</p>';
            }
        }

        return implode("\n", $html);
    }

    /**
     * @param string[] $lines
     * @return array{0:string,1:int} html and number of lines consumed
     */
    private function table(array $lines, int $start): array
    {
        $header = $this->splitRow($lines[$start]);
        $consumed = 2; // header + separator
        $body = [];
        $r = $start + 2;
        while (isset($lines[$r]) && str_contains($lines[$r], '|') && trim($lines[$r]) !== '') {
            $body[] = $this->splitRow($lines[$r]);
            $consumed++;
            $r++;
        }

        $html = '<table class="ixpf-md-table"><thead><tr>';
        foreach ($header as $cell) {
            $html .= '<th>' . $this->inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($body as $row) {
            $html .= '<tr>';
            foreach ($header as $ci => $_) {
                $html .= '<td>' . $this->inline($row[$ci] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return [$html, $consumed];
    }

    /**
     * @return string[]
     */
    private function splitRow(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\||\|$/', '', $line);
        return array_map('trim', explode('|', $line));
    }

    private function inline(string $text): string
    {
        $text = $this->esc($text);
        // inline code first so its content is not further transformed
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        // links [text](url)
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
            return '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
        }, $text);
        // bold then italic
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);
        return $text;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
