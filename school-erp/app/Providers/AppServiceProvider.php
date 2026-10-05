<?php

declare(strict_types=1);

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
            $rawEmail = $request->input('email');
            $email = is_string($rawEmail) ? strtolower(trim($rawEmail)) : '';
            $ip = (string) $request->ip();

            return Limit::perMinute(5)->by($email.'|'.$ip);
        });
    }
}
