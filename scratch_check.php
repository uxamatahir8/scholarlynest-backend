<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Article;

$sampleArticle = Article::with(['articleAuthors', 'assets'])->where('status', 'approved')->first();
if ($sampleArticle) {
    echo json_encode($sampleArticle, JSON_PRETTY_PRINT) . PHP_EOL;
}
