<?php

$safeTestEnvironment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'FILESYSTEM_DISK' => 'local',
    'AWS_ACCESS_KEY_ID' => 'testing-access-key',
    'AWS_SECRET_ACCESS_KEY' => 'testing-secret-key',
    'AWS_DEFAULT_REGION' => 'us-east-1',
    'AWS_BUCKET' => 'scholarlynest-testing',
    'AWS_ENDPOINT' => '',
    'MEDIA_S3_PREFIX' => 'testing',
];

foreach ($safeTestEnvironment as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$testConnection = getenv('DB_CONNECTION');
$testDatabase = getenv('DB_DATABASE');

if ($testConnection !== 'sqlite' || $testDatabase !== ':memory:') {
    fwrite(STDERR, sprintf(
        "Refusing to start PHPUnit: tests must use SQLite in-memory, resolved DB_CONNECTION=%s DB_DATABASE=%s.\n",
        $testConnection === false ? '<unset>' : $testConnection,
        $testDatabase === false ? '<unset>' : $testDatabase,
    ));
    exit(1);
}

$configCache = dirname(__DIR__) . '/bootstrap/cache/config.php';

if (is_file($configCache)) {
    unlink($configCache);
}

require dirname(__DIR__) . '/vendor/autoload.php';
