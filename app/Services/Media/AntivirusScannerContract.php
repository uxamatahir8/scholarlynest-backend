<?php

namespace App\Services\Media;

interface AntivirusScannerContract
{
    public function scan(string $path): AntivirusScanResult;
}
