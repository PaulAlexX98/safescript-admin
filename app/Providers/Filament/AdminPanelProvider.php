<?php

namespace App\Providers\Filament;

use Illuminate\Support\HtmlString;
use App\Filament\Resources\Scheduling\Schedules\ScheduleResource;
use App\Filament\Pages\ConsultationRunner;
use App\Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use App\Filament\Resources\Appointments\AppointmentResource as AppointmentsAppointmentResource;
use App\Filament\Resources\WalkIns\WalkInResource;
use Illuminate\Support\Facades\Route;
use Guava\Calendar\CalendarPlugin;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use App\Filament\Pages\Auth\EditProfile;
use Filament\Enums\ThemeMode;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->default()
            ->path('admin')
            ->homeUrl(fn () => Dashboard::getUrl())
            ->middleware([
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ])
            ->topbar(false)
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->emailChangeVerification()
            ->renderHook(
                'panels::body.end',
                fn () => view('components.layout.app')
            )
            ->renderHook(
                'panels::body.end',
                fn () => view('vendor.filament.components.global-shortcuts')
            )
            ->renderHook(
                'panels::scripts.after',
                fn () => view('partials.dictation-worker')
            )
            ->plugins([
                CalendarPlugin::make(),
            ])
            ->widgets([
                \App\Filament\Widgets\ClockWidget::class,
                \App\Filament\Widgets\WeightManagementAlertsWidget::class,
                \App\Filament\Widgets\AppointmentsCalendarWidget::class,
                \App\Filament\Widgets\KpiStats::class,
                \App\Filament\Widgets\BookingStatusTable::class,
                \App\Filament\Widgets\ServicesPerformance::class,
                \App\Filament\Widgets\DailyRevenueTable::class,
                \App\Filament\Widgets\RevenueBookingsChart::class,
            ])
            ->authGuard('web')
            ->authMiddleware([
                FilamentAuthenticate::class,
            ])
            ->profile(EditProfile::class)
            ->discoverResources(
                in: app_path('Filament/Resources'),
                for: 'App\\Filament\\Resources'
            )
            ->pages([
                Dashboard::class,
                ConsultationRunner::class,
            ])
            ->discoverWidgets(
                in: app_path('Filament/Widgets'),
                for: 'App\\Filament\\Widgets'
            )
            ->routes(function () {
                Route::get(
                    '/consultations/{session}',
                    ConsultationRunner::class
                )->name('filament.admin.consultations.session');

                Route::get(
                    '/consultations/{session}/{tab}',
                    ConsultationRunner::class
                )->name('filament.admin.consultations.session.tab');
            })
            ->renderHook('panels::head.end', function () {
                if (! request()->boolean('inline')) {
                    return '';
                }

                $style = <<<HTML
<style>
  .fi-sidebar,
  .fi-topbar,
  .fi-header,
  aside,
  nav {
    display: none !important;
  }

  .fi-main {
    margin-left: 0 !important;
  }

  body {
    background: transparent !important;
    padding: 16px !important;
  }

  html,
  body {
    overflow-x: hidden;
  }
</style>
HTML;

                return HtmlString::from($style);
            })
            ->navigationGroups([
                NavigationGroup::make('Private Services')
                    ->collapsed(false),

                NavigationGroup::make('NHS Services')
                    ->collapsed(false),

                NavigationGroup::make('Notifications')
                    ->collapsed(false)
                    ->items([
                        NavigationItem::make('Pending NHS')
                            ->icon('heroicon-o-clipboard-document-check')
                            ->url(
                                fn () => AppointmentsAppointmentResource::getUrl('index')
                            )
                            ->visible(true),

                        NavigationItem::make('Appointments')
                            ->icon('heroicon-o-calendar')
                            ->url(
                                fn () => AppointmentsAppointmentResource::getUrl('index')
                            )
                            ->visible(true),

                        NavigationItem::make('Walk In')
                            ->icon('heroicon-o-user-plus')
                            ->url(
                                fn () => WalkInResource::getUrl('index')
                            )
                            ->visible(true),

                        NavigationItem::make('Pending Approval')
                            ->icon('heroicon-o-clock')
                            ->url(
                                fn () => AppointmentsAppointmentResource::getUrl('index')
                            )
                            ->visible(true),

                        NavigationItem::make('Upcoming Appointments')
                            ->icon('heroicon-o-calendar-days')
                            ->url(
                                fn () => AppointmentsAppointmentResource::getUrl(
                                    'index',
                                    ['filter' => 'upcoming']
                                )
                            )
                            ->visible(true),
                    ]),

                NavigationGroup::make('Orders')
                    ->collapsed(false),

                NavigationGroup::make('People')
                    ->collapsed(false),

                NavigationGroup::make('Operations')
                    ->collapsed(false),

                NavigationGroup::make('Logs')
                    ->collapsed(false),

                NavigationGroup::make('Scheduling')
                    ->collapsed(false)
                    ->items([
                        NavigationItem::make('Schedules')
                            ->icon('heroicon-o-clock')
                            ->url(function () {
                                $class = ScheduleResource::class;

                                return class_exists($class)
                                    ? $class::getUrl('index')
                                    : '/admin';
                            })
                            ->visible(true),
                    ]),

                NavigationGroup::make('Front')
                    ->collapsed(false),

                NavigationGroup::make('Forms')
                    ->collapsed(true),
            ])
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Dark)
            ->colors([
                'primary' => '#f59e0b',
            ]);
    }
}