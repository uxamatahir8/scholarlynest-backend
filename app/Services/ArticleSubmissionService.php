<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Owns the transaction boundary for a complete submission aggregate.
 *
 * The callback must create/provision every database record required by the
 * submission, including authors, files, the original version and outbox event.
 */
class ArticleSubmissionService
{
    public function submit(Closure $submission): mixed
    {
        return DB::transaction($submission, 3);
    }
}
