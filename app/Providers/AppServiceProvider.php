<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\Interaction;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRouteBindings();
        $this->configureRateLimiting();
    }

    /**
     * Route model bindings are scoped to the authenticated user, so records
     * owned by someone else resolve to a 404 rather than leaking existence.
     */
    private function configureRouteBindings(): void
    {
        Route::bind('contact', fn (string $value, $route) => Contact::query()
            ->where('user_id', request()->user()?->id)
            ->findOrFail((int) $value));

        Route::bind('interaction', fn (string $value) => Interaction::query()
            ->where('user_id', request()->user()?->id)
            ->findOrFail((int) $value));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(config('orbit.exports.rate_limit_per_minute'))
            ->by('token:'.($request->user()?->currentAccessToken()?->id ?? $request->ip())));
    }
}
