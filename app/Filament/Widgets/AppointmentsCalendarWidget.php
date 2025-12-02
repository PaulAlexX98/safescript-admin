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
        $start = Carbon::parse($info->start);
        $end   = Carbon::parse($info->end);

        // Month view usually requests ~4–6 weeks. Treat 28+ days as "month".
        $isMonthView = $start->diffInDays($end) >= 28;

        $events = [];

        // Helper to build the Appointments index URL with filters.
        $buildUrl = function (string $date) {
            $query = [
                'filters' => [
                    'day'    => ['value' => $date],
                    'status' => ['values' => ['completed']], // only approved
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
                    ->when(Schema::hasColumn('appointments', 'status'), function ($q) {
                        $q->whereIn('status', ['completed', 'booked', 'confirmed']);
                    })
                    ->groupBy('d')
                    ->get();

                foreach ($rows as $r) {
                    $date  = $r->d;
                    $title = (string) $r->c;

                    $events[] = CalendarEvent::make()
                        ->title($title)           // show just the count like "8"
                        ->start($date)            // YYYY-MM-DD
                        ->end($date)              // ensure end is initialised (single-day all-day)
                        ->allDay(true)            // render inside the day cell
                        ->url($buildUrl($date));  // clicking opens the day in Appointments
                }
            } else {
                // Week/Day views: render individual timed events.
                $cols = Schema::getColumnListing('appointments');
                $rows = DB::table('appointments')
                    ->whereBetween('start_at', [$start, $end])
                    ->when(in_array('status', $cols), function ($q) {
                        $q->whereIn('status', ['completed', 'booked', 'confirmed']);
                    })
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

        // ----- Fallback: orders.appointment_at (if no appointments or table missing) -----
        if (empty($events) && Schema::hasTable('orders') && Schema::hasColumn('orders', 'appointment_at')) {
            if ($isMonthView) {
                $rows = DB::table('orders')
                    ->selectRaw('DATE(appointment_at) as d, COUNT(*) as c')
                    ->whereNotNull('appointment_at')
                    ->whereBetween('appointment_at', [$start, $end])
                    ->when(Schema::hasColumn('orders', 'booking_status'), fn ($q) => $q->whereIn('booking_status', ['completed', 'confirmed']))
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
                    ->when(Schema::hasColumn('orders', 'booking_status'), fn ($q) => $q->whereIn('booking_status', ['completed', 'confirmed']))
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
                'left'   => 'prev,next today',
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
                    const url = `/admin/appointments?filters[day][value]=${encodeURIComponent(d)}&filters[status][values][0]=completed&sort=start_at:asc`;
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