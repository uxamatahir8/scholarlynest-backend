<?php

namespace App\Services;

use App\Services\Security\HtmlSanitizer;

class EmailContentFormatter
{
    public function __construct(private readonly HtmlSanitizer $sanitizer)
    {
    }

    public function format(array $lines): array
    {
        return collect($lines)
            ->map(fn ($line) => $this->formatLine((string) $line))
            ->filter()
            ->values()
            ->all();
    }

    private function formatLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || preg_match('/^<br\s*\/?>(\s*)$/i', $line)) {
            return null;
        }

        if (str_contains($line, 'class="code-box"') && preg_match('/class="code-value"[^>]*>([^<]+)</i', $line, $match)) {
            return ['type' => 'code', 'value' => trim(strip_tags($match[1]))];
        }

        if (preg_match('/^<div\b[^>]*font-size:\s*12px[^>]*>(.*)<\/div>$/is', $line, $match)) {
            return ['type' => 'note', 'html' => $this->sanitizer->sanitize($match[1])];
        }

        if (preg_match('/^(?:<br\s*\/?>\s*)?<strong>([^<]+)<\/strong>\s*$/i', $line, $match)) {
            return ['type' => 'heading', 'text' => rtrim(trim($match[1]), ':')];
        }

        if (str_starts_with($line, '•')) {
            $content = trim(mb_substr($line, 1));
            if (preg_match('/^<strong>([^<]+):<\/strong>\s*(.*)$/is', $content, $match)) {
                return [
                    'type' => 'list_item',
                    'label' => trim($match[1]),
                    'value_html' => $this->sanitizer->sanitize($match[2]),
                ];
            }

            return ['type' => 'list_item', 'value_html' => $this->sanitizer->sanitize($content)];
        }

        if (preg_match('/^<div\b[^>]*>(.*)<\/div>$/is', $line, $match)) {
            return [
                'type' => 'quote',
                'html' => $this->sanitizer->sanitize($match[1]),
            ];
        }

        $plainLine = trim(strip_tags($line));
        if (!str_contains($line, '<') && preg_match('/^[A-Z][^:]{1,70}:$/', $plainLine)) {
            return ['type' => 'heading', 'text' => rtrim($plainLine, ':')];
        }
        if (!str_contains($line, '<') && preg_match('/^([A-Z][A-Za-z &\/-]{1,48}):\s*(.+)$/s', $plainLine, $match)) {
            $title = trim($match[1]);
            $content = trim($match[2]);
            $rows = $this->detailRows($content);

            return [
                'type' => in_array($title, ['Next Action', 'Privacy Note'], true) ? 'callout' : 'details',
                'title' => $title,
                'html' => $this->sanitizer->sanitize(nl2br(e($content))),
                'rows' => $rows,
            ];
        }

        return [
            'type' => 'paragraph',
            'html' => $this->sanitizer->sanitize($line),
        ];
    }

    private function detailRows(string $content): array
    {
        $parts = preg_split('/\.\s+(?=[A-Z][A-Za-z &\/-]{1,40}:\s)/', $content) ?: [$content];
        $rows = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^([A-Z][A-Za-z &\/-]{1,40}):\s*(.+)$/s', $part, $match)) {
                $rows[] = [
                    'label' => trim($match[1]),
                    'value' => rtrim(trim($match[2]), '.'),
                ];
            }
        }

        return count($rows) >= 2 ? $rows : [];
    }
}
