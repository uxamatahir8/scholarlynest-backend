<?php

namespace Tests\Feature;

use App\Services\Security\HtmlSanitizer;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_sanitizer_removes_unsafe_html_and_keeps_safe_formatting(): void
    {
        $dirty = '<p onclick="alert(1)">Hello <strong>Scholar</strong><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">bad</a></p><ul><li>Safe</li></ul>';

        $clean = app(HtmlSanitizer::class)->sanitize($dirty);

        $this->assertStringContainsString('<strong>Scholar</strong>', $clean);
        $this->assertStringContainsString('<ul><li>Safe</li></ul>', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
    }
}
