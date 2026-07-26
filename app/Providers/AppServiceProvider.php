<?php

namespace App\Providers;

use App\Events\ArticleSubmitted;
use App\Events\ArticleWorkflowEventOccurred;
use App\Models\Article;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;
use App\Models\UserNotification;
use App\Observers\WorkflowAssignmentObserver;
use App\Policies\ArticlePolicy;
use App\Policies\NotificationPolicy;
use App\Services\Media\AntivirusScannerContract;
use App\Services\Media\ClamAvScanner;
use App\Services\Notifications\RecordArticleNotificationEvent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
        if ($this->app->environment('testing')) {
            $connection = (string) config('database.default');
            $driver = (string) config("database.connections.{$connection}.driver");

            if ($driver !== 'sqlite') {
                throw new RuntimeException(
                    "Unsafe testing database [{$connection}/{$driver}]. ScholarlyNest tests may only run against SQLite."
                );
            }
        }

        Gate::policy(Article::class, ArticlePolicy::class);
        Gate::policy(UserNotification::class, NotificationPolicy::class);

        Event::listen(ArticleSubmitted::class, RecordArticleNotificationEvent::class);
        Event::listen(ArticleWorkflowEventOccurred::class, RecordArticleNotificationEvent::class);

        SubEditorAssignment::observe(WorkflowAssignmentObserver::class);
        ReviewerAssignment::observe(WorkflowAssignmentObserver::class);
        ProductionAssignment::observe(WorkflowAssignmentObserver::class);

        // Event listeners are auto-discovered by Laravel 11.

        foreach ([
            'media-upload-initiate' => config('media_uploads.rate_limit_per_minute', 150),
            'media-upload-sign-parts' => config('media_uploads.rate_limit_per_minute', 150),
            'media-upload-complete' => 20,
            'media-upload-read' => 60,
            'media-download' => 60,
        ] as $name => $maxAttempts) {
            RateLimiter::for($name, function ($request) use ($maxAttempts) {
                $userId = $request->user()?->id ?: 'guest';

                return Limit::perMinute($maxAttempts)->by($userId.'|'.$request->ip());
            });
        }

        foreach ([
            'mfa-setup' => 10,
            'mfa-verify' => 10,
            'mfa-sensitive' => 5,
        ] as $name => $maxAttempts) {
            RateLimiter::for($name, function ($request) use ($maxAttempts) {
                $userId = $request->user()?->id ?: 'guest';

                return Limit::perMinute($maxAttempts)->by($userId.'|'.$request->ip());
            });
        }
    }
}
