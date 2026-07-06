<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class FrontendExposureAuditTest extends TestCase
{
    public function test_active_frontend_files_do_not_contain_debug_logging_or_sensitive_storage_fields(): void
    {
        $frontendRoot = $this->frontendPath('src');

        $forbiddenPattern = '/console\\.(log|error|warn|debug)|error\\.response|err\\.response\\.data|file_path|pdf_path|storage_path|606295156376/';
        $allowedArchiveFragments = [
            DIRECTORY_SEPARATOR . '.context_old' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . '.components_old' . DIRECTORY_SEPARATOR,
        ];

        $violations = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($frontendRoot));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !preg_match('/\\.(js|jsx|ts|tsx)$/', $file->getFilename())) {
                continue;
            }

            $path = $file->getPathname();
            foreach ($allowedArchiveFragments as $fragment) {
                if (str_contains($path, $fragment)) {
                    continue 2;
                }
            }

            $content = file_get_contents($path);
            if (preg_match($forbiddenPattern, $content, $match)) {
                $violations[] = str_replace($frontendRoot . DIRECTORY_SEPARATOR, '', $path) . ': ' . $match[0];
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_frontend_error_helper_uses_fallback_for_rate_limit_payloads(): void
    {
        $safeErrorsPath = $this->frontendPath('src/utils/safeErrors.js');
        $content = file_get_contents($safeErrorsPath);

        $this->assertStringContainsString('ALLOWED_MESSAGE_KEYS', $content);
        $this->assertStringContainsString('return fallback', $content);
        $this->assertStringNotContainsString('Too Many Attempts', $content);
        $this->assertStringNotContainsString('too_many_attempts', $content);
    }

    public function test_legacy_app_old_frontend_directories_are_removed_from_routing_tree(): void
    {
        $frontendRoot = $this->frontendPath('src/app');

        $this->assertDirectoryDoesNotExist($frontendRoot . '/_admin_old');
        $this->assertDirectoryDoesNotExist($frontendRoot . '/_magazines_old');
    }
    private function frontendPath(string $relative): string
    {
        foreach ([
            base_path('../frontend-ui/' . $relative),
            getcwd() . '/../frontend-ui/' . $relative,
            '/home/developer/workspace/frontend-ui/' . $relative,
        ] as $candidate) {
            $path = realpath($candidate);
            if ($path !== false) {
                return $path;
            }
        }

        $this->fail('frontend-ui/' . $relative . ' must exist for exposure scan.');
    }
}
