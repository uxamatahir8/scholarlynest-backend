<?php

namespace App\Services\Media;

use Symfony\Component\Process\Process;

class ClamAvScanner implements AntivirusScannerContract
{
    public function scan(string $path): AntivirusScanResult
    {
        if (!is_file($path) || !is_readable($path)) {
            return new AntivirusScanResult('scan_failed', 'clamav', 'scan_file_unavailable');
        }

        $binary = config('media_uploads.clamav_binary', env('CLAMAV_SCAN_BINARY', 'clamdscan'));
        $process = new Process([$binary, '--no-summary', $path]);
        $process->setTimeout((int) env('CLAMAV_SCAN_TIMEOUT_SECONDS', 120));
        $process->run();

        return match ($process->getExitCode()) {
            0 => new AntivirusScanResult('clean', 'clamav'),
            1 => new AntivirusScanResult('infected', 'clamav', 'infected'),
            default => new AntivirusScanResult('scan_failed', 'clamav', 'scanner_unavailable'),
        };
    }
}
