<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\Tenancy\EditGroupProfile;
use App\Filament\Pages\Tenancy\RegisterGroup;
use App\Models\Group;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Mmoollllee\FilamentUserProfile\UserProfilePlugin;

class GlobalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('global')
            ->path('/')
            ->brandName('Foodpecker 🪶')
            ->viteTheme('resources/css/filament/theme.css')
            ->login(Login::class)
            ->registration(Register::class)
            ->passwordReset()
            ->plugin(UserProfilePlugin::make()->page(EditProfile::class))
            ->colors([
                'primary' => Color::Amber,
                // Badge colors of the enums: round phases, roles and product categories.
                'amber' => Color::Amber,
                'blue' => Color::Blue,
                'emerald' => Color::Emerald,
                'green' => Color::Green,
                'indigo' => Color::Indigo,
                'lime' => Color::Lime,
                'orange' => Color::Orange,
                'pink' => Color::Pink,
                'purple' => Color::Purple,
                'red' => Color::Red,
                'rose' => Color::Rose,
                'sky' => Color::Sky,
                'yellow' => Color::Yellow,
            ])
            ->tenant(Group::class, slugAttribute: 'slug')
            ->tenantRoutePrefix('g')
            ->tenantRegistration(RegisterGroup::class)
            ->tenantProfile(EditGroupProfile::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->navigationGroups([
                'Bestellungen',
                'Stammdaten',
                'Gruppe',
            ])
            ->sidebarCollapsibleOnDesktop()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
