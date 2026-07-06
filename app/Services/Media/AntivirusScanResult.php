<?php

namespace App\Services\Media;

final readonly class AntivirusScanResult
{
    public function __construct(
        public string $status,
        public ?string $engine = null,
        public ?string $safeReason = null,
    ) {
    }
}
