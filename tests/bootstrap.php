<?php

$configCache = dirname(__DIR__) . '/bootstrap/cache/config.php';

if (is_file($configCache)) {
    unlink($configCache);
}

require dirname(__DIR__) . '/vendor/autoload.php';
