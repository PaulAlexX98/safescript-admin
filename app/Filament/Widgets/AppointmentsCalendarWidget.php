<?php

namespace App\Filament\Widgets;

use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Support\Carbon;
use Guava\Calendar\Enums\CalendarViewType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class AppointmentsCalendarWidget extends CalendarWidget
{
    protected CalendarViewType $calendarView = CalendarViewType::DayGridMonth;
    protected bool $dateClickEnabled = true;
    protected bool $eventClickEnabled = true;
    protected ?string $locale = 'en-gb';
    protected function getEvents(FetchInfo $info): Collection | array | Builder
    {
        $rawStart = is_callable([$info, 'start']) ? $info->start() : (property_exists($info, 'start') ? $info->start : null);
        $rawEnd   = is_callable([$info, 'end'])   ? $info->end()   : (property_exists($info, 'end')   ? $info->end   : null);

        // Fallback guards
        if (!$rawStart) { $rawStart = now()->startOfMonth()->toDateString(); }
        if (!$rawEnd)   { $rawEnd   = now()->endOfMonth()->addDay()->toDateString(); } // FullCalendar passes end-exclusive

        $start = Carbon::parse($rawStart);
        // end from FullCalendar is exclusive; subtract a micro to make it inclusive for SQL between
        $end   = Carbon::parse($rawEnd)->subSecond();

        // Month view usually requests ~4–6 weeks. Treat 28+ days as "month".
        $isMonthView = $start->diffInDays($end) >= 28;

        // Aggregate map for month counts when needed
        $counts = [];
        $addCount = function (string $date, int $n = 1) use (&$counts) {
            $counts[$date] = ($counts[$date] ?? 0) + $n;
        };

        $events = [];

        // Helper to build the Appointments index URL with filters.
        $buildUrl = function (string $date) {
            $query = [
                'filters' => [
                    'day' => ['value' => $date],
                ],
                'sort' => 'start_at:asc',
            ];
            return url('/admin/appointments') . '?' . http_build_query($query);
        };

        // ----- Primary source: appointments table -----
        if (Schema::hasTable('appointments') && Schema::hasColumn('appointments', 'start_at')) {
            if ($isMonthView) {
                // Aggregate count per day for the month grid.
                $rows = DB::table('appointments')
                    ->selectRaw('DATE(start_at) as d, COUNT(*) as c')
                    ->whereBetween('start_at', [$start, $end])
                    ->groupBy('d')
                    ->get();

                foreach ($rows as $r) {
                    if (!empty($r->d)) {
                        $addCount($r->d, (int) $r->c);
                    }
                }
            } else {
                // Week/Day views: render individual timed events.
                $cols = Schema::getColumnListing('appointments');
                $rows = DB::table('appointments')
                    ->whereBetween('start_at', [$start, $end])
                    ->orderBy('start_at')
                    ->get([
                        'id',
                        'start_at',
                        'end_at',
                        in_array('service', $cols) ? 'service' : DB::raw("'' as service"),
                        in_array('first_name', $cols) ? 'first_name' : DB::raw("NULL as first_name"),
                        in_array('last_name', $cols)  ? 'last_name'  : DB::raw("NULL as last_name"),
                    ]);

                foreach ($rows as $r) {
                    $name = trim(trim((string)($r->first_name ?? '')) . ' ' . trim((string)($r->last_name ?? '')));
                    $svc  = is_string($r->service ?? null) ? ucwords(str_replace(['-', '_'], ' ', $r->service)) : null;

                    $parts = [];
                    if ($name !== '') $parts[] = $name;
                    if ($svc) $parts[] = $svc;
                    $title = $parts ? implode(' · ', $parts) : 'Appointment';

                    $date = Carbon::parse($r->start_at)->toDateString();

                    $events[] = CalendarEvent::make()
                        ->title($title)
                        ->start($r->start_at)
                        ->end($r->end_at ?: $r->start_at) // fall back to zero-length if no end
                        ->allDay(false)
                        ->url($buildUrl($date));
                }
            }
        }

        // When showing the month grid, also include counts from orders.appointment_at
        if ($isMonthView && Schema::hasTable('orders') && Schema::hasColumn('orders', 'appointment_at')) {
            $rows = DB::table('orders')
                ->selectRaw('DATE(appointment_at) as d, COUNT(*) as c')
                ->whereNotNull('appointment_at')
                ->whereBetween('appointment_at', [$start, $end])
                ->when(Schema::hasColumn('orders', 'booking_status'), fn ($q) => $q->whereIn('booking_status', ['completed', 'confirmed', 'pending']))
                ->groupBy('d')
                ->get();

            foreach ($rows as $r) {
                if (!empty($r->d)) {
                    $addCount($r->d, (int) $r->c);
                }
            }
        }

        // If we have any counts, convert them to CalendarEvent items
        if ($isMonthView && !empty($counts)) {
            ksort($counts);
            foreach ($counts as $date => $count) {
                $events[] = CalendarEvent::make()
                    ->title((string) $count)
                    ->start($date)
                    ->end($date)
                    ->allDay(true)
                    ->url($buildUrl($date));
            }
        }

        // ----- Fallback: orders.appointment_at (non-month views OR when appointments produced nothing) -----
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'appointment_at') && (!$isMonthView ? true : empty($events))) {
            if ($isMonthView) {
                $rows = DB::table('orders')
                    ->selectRaw('DATE(appointment_at) as d, COUNT(*) as c')
                    ->whereNotNull('appointment_at')
                    ->whereBetween('appointment_at', [$start, $end])
                    ->when(Schema::hasColumn('orders', 'booking_status'), fn ($q) => $q->whereIn('booking_status', ['completed', 'confirmed', 'pending']))
                    ->groupBy('d')
                    ->get();

                foreach ($rows as $r) {
                    $date = $r->d;
                    $events[] = CalendarEvent::make()
                        ->title((string) $r->c)
                        ->start($date)
                        ->end($date)          // ensure end is initialised
                        ->allDay(true)
                        ->url($buildUrl($date));
                }
            } else {
                $rows = DB::table('orders')
                    ->whereNotNull('appointment_at')
                    ->whereBetween('appointment_at', [$start, $end])
                    ->when(Schema::hasColumn('orders', 'booking_status'), fn ($q) => $q->whereIn('booking_status', ['completed', 'confirmed', 'pending']))
                    ->orderBy('appointment_at')
                    ->get([
                        'appointment_at',
                        DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.service_slug')), JSON_UNQUOTE(JSON_EXTRACT(meta,'$.service')), '') as service"),
                    ]);

                foreach ($rows as $r) {
                    $date = Carbon::parse($r->appointment_at)->toDateString();
                    $svc  = is_string($r->service ?? null) ? ucwords(str_replace(['-', '_'], ' ', $r->service)) : null;

                    $events[] = CalendarEvent::make()
                        ->title($svc ?: 'Appointment')
                        ->start($r->appointment_at)
                        ->end($r->appointment_at)
                        ->allDay(false)
                        ->url($buildUrl($date));
                }
            }
        }

        return $events;
    }
    public function getOptions(): array
    {
        return [
            'initialView'   => 'dayGridMonth',
            'firstDay'      => 1, // Monday start for UK
            'height'        => 'auto',
            'headerToolbar' => [
                'left'   => 'prevYear,prev,next,nextYear today',
                'center' => 'title',
                'right'  => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
            ],
            'buttonText' => [
                'today'       => 'Today',
                'dayGridMonth'=> 'Month',
                'timeGridWeek'=> 'Week',
                'timeGridDay' => 'Day',
                'listWeek'    => 'List',
            ],
            'navLinks'     => true,
            'dayMaxEvents' => false,

            // Click on any empty day cell to open Appointments list filtered to that date
            'dateClick' => $this->js(<<<'JS'
                (info) => {
                    const d = info.dateStr;
                    const url = `/admin/appointments?filters[day][value]=${encodeURIComponent(d)}&sort=start_at:asc`;
                    window.location.href = url;
                }
            JS),

            // Neutral styling without hiding controls
            'viewDidMount' => $this->js(<<<'JS'
                (arg) => {
                    const root = (arg && arg.el) ? arg.el : document;
                    if (!root || root.__peNeutralStyle) return;

                    const s = document.createElement('style');
                    s.textContent = `
                        .fc {
                            --fc-page-bg-color: transparent !important;
                            --fc-neutral-bg-color: transparent !important;
                            --fc-today-bg-color: transparent !important;
                            --fc-list-event-hover-bg-color: transparent !important;
                            --fc-now-indicator-color: currentColor !important;
                            color: inherit !important;
                        }
                        .fc .fc-toolbar-title { color: inherit !important; }

                        /* Keep buttons visible and clickable */
                        .fc .fc-button {
                            background: transparent !important;
                            border: 1px solid rgba(0,0,0,0.15) !important;
                            box-shadow: none !important;
                            color: inherit !important;
                        }
                        /* Ensure toolbar buttons are actually clickable */
                        .fc .fc-toolbar.fc-header-toolbar {
                            position: relative !important;
                            z-index: 50 !important;
                            pointer-events: auto !important;
                        }
                        .fc .fc-button {
                            pointer-events: auto !important;
                        }

                        /* List views */
                        .fc .fc-list, .fc .fc-list table { background: transparent !important; color: inherit !important; }
                        .fc .fc-list-day, .fc .fc-list-day-cushion { background: transparent !important; color: inherit !important; }
                        .fc .fc-list-event { background: transparent !important; color: inherit !important; border: 0 !important; }
                        .fc .fc-list-event-graphic, .fc .fc-list-event-dot { background: transparent !important; border-color: transparent !important; }

                        /* Time-grid / day-grid events */
                        .fc .fc-h-event, .fc .fc-daygrid-event { background: transparent !important; border: 0 !important; }
                        .fc .fc-h-event .fc-event-main, .fc .fc-daygrid-event .fc-event-main { color: inherit !important; }

                        /* Dots/pills in month view */
                        .fc .fc-daygrid-event-dot { background: transparent !important; border-color: transparent !important; }

                        /* Today highlight */
                        .fc .fc-day-today,
                        .fc .fc-list-day.fc-day-today,
                        .fc .fc-list-day.fc-day-today .fc-list-day-cushion { background: transparent !important; color: inherit !important; }
                    `;
                    (arg.el || document.body).appendChild(s);
                    root.__peNeutralStyle = true;
                }
            JS),
        ];
    }

    public function options(): array
    {
        // Bridge for Guava v2 which sometimes calls options() instead of getOptions().
        return $this->getOptions();
    }

    
}