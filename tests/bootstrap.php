<?php

$safeTestEnvironment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_HOST' => 'sqlite-local',
    'DB_PORT' => '0',
    'DB_DATABASE' => '/tmp/scholarlynest_testing',
    'DB_USERNAME' => 'sqlite-file-only',
    'DB_URL' => '',
    'ALLOW_DESTRUCTIVE_TEST_DATABASE' => 'true',
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
$testEnvironment = getenv('APP_ENV');
$testHost = getenv('DB_HOST');
$testPort = getenv('DB_PORT');
$testUsername = getenv('DB_USERNAME');
$destructiveAllowed = getenv('ALLOW_DESTRUCTIVE_TEST_DATABASE');
$databaseName = basename((string) $testDatabase);

if ($testEnvironment !== 'testing'
    || $testConnection !== 'sqlite'
    || ! preg_match('/_(test|testing)$/', $databaseName)
    || $destructiveAllowed !== 'true') {
    fwrite(STDERR, sprintf(
        "DATABASE SAFETY FAILED: APP_ENV=%s DB_CONNECTION=%s DB_HOST=%s DB_PORT=%s DB_DATABASE=%s DB_USERNAME=%s ALLOW_DESTRUCTIVE_TEST_DATABASE=%s.\n",
        $testEnvironment === false ? '<unset>' : $testEnvironment,
        $testConnection === false ? '<unset>' : $testConnection,
        $testHost === false ? '<unset>' : $testHost,
        $testPort === false ? '<unset>' : $testPort,
        $testDatabase === false ? '<unset>' : $testDatabase,
        $testUsername === false ? '<unset>' : $testUsername,
        $destructiveAllowed === false ? '<unset>' : $destructiveAllowed,
    ));
    exit(1);
}

fwrite(STDOUT, sprintf(
    "DATABASE SAFETY VERIFIED: APP_ENV=%s DB_CONNECTION=%s DB_HOST=%s DB_PORT=%s DB_DATABASE=%s DB_USERNAME=%s ALLOW_DESTRUCTIVE_TEST_DATABASE=%s DB_USER_SCOPE=sqlite-single-file-only\n",
    $testEnvironment, $testConnection, $testHost, $testPort, $testDatabase, $testUsername, $destructiveAllowed,
));

if (! is_file($testDatabase) && ! touch($testDatabase)) {
    fwrite(STDERR, "DATABASE SAFETY FAILED: dedicated SQLite test file could not be created.\n");
    exit(1);
}

$configCache = dirname(__DIR__).'/bootstrap/cache/config.php';

if (is_file($configCache)) {
    unlink($configCache);
}

require dirname(__DIR__).'/vendor/autoload.php';
