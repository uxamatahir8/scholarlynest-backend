<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use App\Models\Article;
use App\Policies\ArticlePolicy;
use App\Events\ArticleSubmitted;
use App\Listeners\SendArticleSubmissionNotifications;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
