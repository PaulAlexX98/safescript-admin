<?php

namespace App\Providers\Filament;

use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use App\Filament\Resources\Orders\OrderResource as OrdersOrderResource;
use App\Filament\Resources\Appointments\AppointmentResource as AppointmentsAppointmentResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Filament\Resources\Orders\PendingOrderResource;
use App\Filament\Resources\ApprovedOrders\ApprovedOrderResource;


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
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->pages([
                Dashboard::class,
                
            ])
            ->navigationGroups([
                NavigationGroup::make('Notifications')
                    ->collapsed(false)
                    ->items([
                        NavigationItem::make('Pending NHS')
                            ->icon('heroicon-o-clipboard-document-check')
                            ->badge($pendingNhs ?: null)
                            ->url(fn () => PendingOrderResource::getUrl('index'))
                            ->visible($hasOrders),

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
                NavigationGroup::make('Orders')
                    ->collapsed(false),
                NavigationGroup::make('Forms')
                    ->collapsed(true),
            ])
        ;
    }
}