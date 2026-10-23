<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $account = is_string($email) ? strtolower(substr($email, 0, 254)) : 'invalid';

            return [
                Limit::perMinute(20)->by($request->ip()),
                Limit::perMinute(5)->by(hash('sha256', $account).'|'.$request->ip()),
            ];
        });
    }
}
