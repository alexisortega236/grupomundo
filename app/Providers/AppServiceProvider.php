<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

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
        Passport::tokensCan([
            'properties:read' => 'Read published commercial properties.',
        ]);
        Passport::defaultScopes([]);
        Passport::tokensExpireIn(now()->addMinutes(60));
        Passport::clientCredentialsTokensExpireIn(now()->addMinutes(15));

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }

        RateLimiter::for('contact', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
        ]);

        RateLimiter::for('api-properties', fn (Request $request) => [
            Limit::perMinute(60)->by((string) ($request->attributes->get('oauth_client_id') ?: 'unknown')),
        ]);
    }
}
