<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Group-wide screens (dashboards, comparison, tracker): admin + CEO.
        Gate::define('view-group', fn (User $u) => $u->isGroupLevel());
        // Administration (periods, audit log, exports, AMP import): admin only.
        Gate::define('administer', fn (User $u) => $u->isAdmin());

        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email')).'|'.$request->ip();

            return [Limit::perMinute(5)->by($key), Limit::perMinute(20)->by($request->ip())];
        });

        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));

        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
