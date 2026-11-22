<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Navigation\MenuItem;
use Filament\View\PanelsRenderHook;

use App\Filament\Pages\Auth\Register;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(fn () => auth()->user()?->account?->name ?? config('app.name'))
            //->registration(Register::class)
            // identidade única com a landing e o checkout: verde #00B389 + neutros quentes (ver config/brand.php)
            ->colors([
                'primary' => config('brand.primary'),
                'gray' => config('brand.gray'),
            ])
            ->font('Instrument Sans', provider: GoogleFontProvider::class)
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('1.75rem')
            ->breadcrumbs(false)
            ->globalSearch(false)
            // o painel só carrega o CSS do Filament (sem tema próprio), então ajustes de layout/tema entram inline.
            // Abas da lista de pagamentos: à esquerda (o Filament as centraliza com mx-auto) e coladas na tabela.
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.theme'))
            ->renderHook(PanelsRenderHook::PAGE_START, fn () => auth()->user()?->account?->isSuspended()
                ? new \Illuminate\Support\HtmlString('<div style="padding:12px 16px;border-radius:10px;background:#FBEAE3;color:#C1440E;font-weight:600;margin-bottom:16px">Esta conta está suspensa: a API não aceita novas cobranças. Fale com o suporte para reativar.</div>')
                : '')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')

            ->userMenuItems([
                // só super-admins: atalho para o painel da plataforma (contas, convites, suspensão)
                'platform' => MenuItem::make()
                    ->label('Painel da plataforma')
                    ->url('/platform')
                    ->icon('heroicon-m-building-office-2')
                    ->visible(fn (): bool => (bool) auth()->user()?->is_super_admin),
                'telescope' => MenuItem::make()
                    ->label(fn() => 'telescope')
                    ->visible(fn (): bool => (bool) auth()->user()?->is_super_admin)
                    ->url(fn (): string => '/telescope')
                    ->icon('heroicon-m-user-circle')
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
