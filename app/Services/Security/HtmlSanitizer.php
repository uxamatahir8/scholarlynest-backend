<?php

namespace App\Services\Security;

class HtmlSanitizer
{
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><ol><ul><li><blockquote><a><h2><h3><h4><table><thead><tbody><tr><th><td><sup><sub>';

    public function sanitize(?string $html): string
    {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        $clean = preg_replace('/<\s*(script|iframe|object|embed)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? '';
        $clean = preg_replace('/<\s*(script|iframe|object|embed)\b[^>]*\/?\s*>/is', '', $clean) ?? '';
        $clean = strip_tags($clean, self::ALLOWED_TAGS);
        $clean = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
        $clean = preg_replace('/\s+(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', ' $1="#"', $clean) ?? '';
        $clean = preg_replace('/\s+(href|src)\s*=\s*javascript:[^\s>]*/i', ' $1="#"', $clean) ?? '';

        return trim($clean);
    }
}
