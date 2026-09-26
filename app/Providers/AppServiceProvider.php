<?php

namespace App\Providers;

use App\Models\User;
use CodeWithKyrian\FilamentDateRange\Forms\Components\DateRangePicker;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Mmoollllee\FilamentUserProfile\UserProfile;

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
        $this->configureProxies();

        // Profile photos are visible to everybody sharing a group.
        UserProfile::authorizePhotoUsing(fn (User $viewer, User $owner): bool => $viewer->sharesGroupWith($owner));

        // Dates can be typed as well as picked, like in the other apps.
        DateRangePicker::configureUsing(fn (DateRangePicker $picker) => $picker->editableInputs()->weekStartsOnMonday());
    }

    /**
     * Behind a TLS-terminating proxy Laravel would otherwise see plain http
     * and the wrong host, which breaks asset URLs and signed invitation links.
     */
    private function configureProxies(): void
    {
        $proxies = config('foodpecker.trusted_proxies');

        if (filled($proxies)) {
            TrustProxies::at($proxies === '*' ? '*' : Str::of($proxies)->explode(',')->map(fn (string $proxy): string => trim($proxy))->all());
        }

        if (Str::startsWith((string) config('app.url'), 'https://')) {
            URL::forceHttps();
        }
    }
}
