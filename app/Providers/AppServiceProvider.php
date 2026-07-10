<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use App\Models\Article;
use App\Policies\ArticlePolicy;
use App\Events\ArticleSubmitted;
use App\Events\ArticleWorkflowEventOccurred;
use App\Listeners\SendArticleSubmissionNotifications;
use App\Listeners\SendArticleWorkflowNotifications;
use App\Services\Media\AntivirusScannerContract;
use App\Services\Media\ClamAvScanner;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AntivirusScannerContract::class, ClamAvScanner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Article::class, ArticlePolicy::class);

        Event::listen(
            ArticleSubmitted::class,
            SendArticleSubmissionNotifications::class
        );

        Event::listen(
            ArticleWorkflowEventOccurred::class,
            SendArticleWorkflowNotifications::class
        );

        foreach ([
            'media-upload-initiate' => config('media_uploads.rate_limit_per_minute', 150),
            'media-upload-sign-parts' => config('media_uploads.rate_limit_per_minute', 150),
            'media-upload-complete' => 20,
            'media-upload-read' => 60,
            'media-download' => 60,
        ] as $name => $maxAttempts) {
            RateLimiter::for($name, function ($request) use ($maxAttempts) {
                $userId = $request->user()?->id ?: 'guest';
                return Limit::perMinute($maxAttempts)->by($userId . '|' . $request->ip());
            });
        }
    }
}
