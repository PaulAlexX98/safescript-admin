<?php

namespace App\Providers\Filament;

use Illuminate\Support\HtmlString;
use App\Filament\Resources\Scheduling\Schedules\ScheduleResource;
use App\Filament\Pages\ConsultationRunner;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use App\Filament\Resources\Appointments\AppointmentResource as AppointmentsAppointmentResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Filament\Resources\Orders\PendingOrderResource;
use Illuminate\Support\Facades\Route;
use Filament\Pages;
use App\Filament\Widgets\AppointmentsCalendarWidget;
use Guava\Calendar\CalendarPlugin;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use App\Filament\Pages\Auth\EditProfile;



class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Guard if tables don’t exist yet and compute counts up front
        $hasOrders = Schema::hasTable('orders');
        $hasAppointments = Schema::hasTable('appointments');

        $pendingNhs = 0;
        if ($hasOrders && Schema::hasColumn('orders', 'meta') && Schema::hasColumn('orders', 'status')) {
            $pendingNhs = DB::table('orders')
                ->where('meta->type', 'nhs')
                ->where('status', 'pending')
                ->count();
        }

        $pendingApproval = 0;
        if ($hasOrders && Schema::hasColumn('orders', 'status')) {
            $pendingApproval = DB::table('orders')
                ->where('status', 'pending')
                ->count();
        }

        $upcoming = 0;
        if ($hasAppointments
            && Schema::hasColumn('appointments', 'start_at')
            && Schema::hasColumn('appointments', 'status')) {
            $upcoming = DB::table('appointments')
                ->where('start_at', '>=', now())
                ->where('status', 'booked')
                ->count();
        }

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->homeUrl(fn () => url('/admin'))
            ->middleware([\Illuminate\Cookie\Middleware\EncryptCookies::class, \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class, \Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, \Illuminate\Routing\Middleware\SubstituteBindings::class,])
            ->topbar(false)
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->emailChangeVerification()
            
            
            ->plugins([
                \Guava\Calendar\CalendarPlugin::make(),
            ])
            ->widgets([
                \App\Filament\Widgets\AppointmentsCalendarWidget::class,
                \App\Filament\Widgets\KpiStats::class,
                \App\Filament\Widgets\BookingStatusTable::class,
                \App\Filament\Widgets\ServicesPerformance::class,
                \App\Filament\Widgets\DailyRevenueTable::class,
                \App\Filament\Widgets\RevenueBookingsChart::class,
            ])
            ->authGuard('web')
            ->authMiddleware([
                FilamentAuthenticate::class, // require login for /admin
            ])
            ->profile(EditProfile::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->pages([
                Dashboard::class,    
                ConsultationRunner::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')

            ->routes(function () {
                // These are scoped under the panel path "admin" and the admin auth guard
                Route::get('/consultations/{session}', ConsultationRunner::class)
                    ->name('filament.admin.consultations.session');
                Route::get('/consultations/{session}/{tab}', ConsultationRunner::class)
                    ->name('filament.admin.consultations.session.tab');
            })

            ->renderHook('panels::head.end', function () {
                if (!request()->boolean('inline')) return '';
                $style = <<<HTML
<style>
  /* Hide Filament chrome when viewing inside inline modal iframe */
  .fi-sidebar,
  .fi-topbar,
  .fi-header,
  aside,
  nav { display: none !important; }
  .fi-main { margin-left: 0 !important; }
  body { background: transparent !important; padding: 16px !important; }
  html, body { overflow-x: hidden; }
</style>
HTML;
                return HtmlString::from($style);
            })

            ->navigationGroups([
                NavigationGroup::make('Notifications')
                    ->collapsed(false)
                    ->items([
                        NavigationItem::make('Pending NHS')
                            ->icon('heroicon-o-clipboard-document-check')
                            ->badge($pendingNhs ?: null)
                            ->url(fn () => PendingOrderResource::getUrl('index'))
                            ->visible($hasOrders),

                        NavigationItem::make('Appointments')
                            ->icon('heroicon-o-calendar')
                            ->url(fn () => AppointmentsAppointmentResource::getUrl('index'))
                            ->visible(true),

                        NavigationItem::make('Pending Approval')
                            ->icon('heroicon-o-clock')
                            ->badge($pendingApproval ?: null)
                            ->url(fn () => PendingOrderResource::getUrl('index'))
                            ->visible($hasOrders),

                        NavigationItem::make('Upcoming Appointments')
                            ->icon('heroicon-o-calendar-days')
                            ->badge($upcoming ?: null)
                            ->url(fn () => AppointmentsAppointmentResource::getUrl('index', ['filter' => 'upcoming']))
                            ->visible($hasAppointments),
                    ]),
                NavigationGroup::make('Scheduling')
                    ->collapsed(false)
                    ->items([
                        NavigationItem::make('Schedules')
                            ->icon('heroicon-o-clock')
                            ->url(function () {
                                $class = ScheduleResource::class;
                                return class_exists($class) ? $class::getUrl('index') : '/admin';
                            })
                            ->visible(true),
                    ]),
                NavigationGroup::make('Orders')
                    ->collapsed(false),
                NavigationGroup::make('Forms')
                    ->collapsed(true),
            ])
            /*->viteTheme([
                'resources/css/filament/admin/theme.css',
                'resources/css/filament/inline-clean.css',
            ])*/
            
            ->darkMode(true)
            ->colors([
                'primary' => '#f59e0b',
            ]);
    }
}