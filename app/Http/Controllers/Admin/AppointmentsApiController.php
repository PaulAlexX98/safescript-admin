<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class AppointmentsApiController extends Controller
{
    public function stats(Request $req)
    {
        [$from, $to] = $this->rangeFromRequest($req);

        $rows = $this->baseQuery($from, $to)->get();
        $byDay = [];
        foreach ($rows as $r) {
            $d = Carbon::parse($r->start_at)->toDateString();
            $byDay[$d] = ($byDay[$d] ?? 0) + 1;
        }

        // Optional: holidays/closures
        $closed = [];
        if (Schema::hasTable('availability_overrides')) {
            $ov = DB::table('availability_overrides')
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->get();
            foreach ($ov as $o) {
                if (!empty($o->is_closed)) $closed[$o->date] = true;
            }
        }

        $days = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $d = $cursor->toDateString();
            $days[] = ['date' => $d, 'count' => (int)($byDay[$d] ?? 0), 'closed' => !empty($closed[$d])];
            $cursor->addDay();
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),
            'days' => $days,
        ]);
    }

    public function day(Request $req)
    {
        $date = Carbon::parse($req->input('date', now()->toDateString()))->toDateString();

        $items = [];
        if (Schema::hasTable('appointments') && Schema::hasColumn('appointments','start_at')) {
            $q = DB::table('appointments')
                ->whereDate('start_at', $date)
                ->select('id','start_at','status','service_slug','order_id','patient_id');
            foreach ($q->get() as $r) {
                $items[] = [
                    'id'      => $r->id,
                    'time'    => Carbon::parse($r->start_at)->format('H:i'),
                    'name'    => null, // join if you have patients table
                    'service' => $r->service_slug ?? '',
                    'status'  => $r->status ?? '',
                    'orderId' => $r->order_id ?? null,
                ];
            }
        } elseif (Schema::hasTable('orders') && Schema::hasColumn('orders','appointment_at')) {
            $cols = Schema::getColumnListing('orders');
            $q = DB::table('orders')
                ->whereDate('appointment_at', $date)
                ->select([
                    'id',
                    'appointment_at as start_at',
                    DB::raw(in_array('status',$cols) ? 'status' : "'paid' as status"),
                    DB::raw(in_array('service_slug',$cols) ? 'service_slug' : "'' as service_slug"),
                    DB::raw(in_array('first_name',$cols) ? 'first_name' : "'' as first_name"),
                    DB::raw(in_array('last_name',$cols) ? 'last_name' : "'' as last_name"),
                    DB::raw(in_array('reference',$cols) ? 'reference' : "NULL as reference"),
                ]);
            foreach ($q->get() as $r) {
                $items[] = [
                    'id'      => $r->id,
                    'time'    => Carbon::parse($r->start_at)->format('H:i'),
                    'name'    => trim(($r->first_name ?? '').' '.($r->last_name ?? '')) ?: null,
                    'service' => $r->service_slug ?? '',
                    'status'  => $r->status ?? '',
                    'orderId' => $r->id,
                    'ref'     => $r->reference ?? null,
                ];
            }
        }

        return response()->json(['date' => $date, 'items' => $items]);
    }

    // ----- helpers -----
    private function rangeFromRequest(Request $req): array
    {
        if ($m = $req->input('month')) {
            $from = Carbon::parse($m.'-01')->startOfMonth();
            $to   = $from->copy()->endOfMonth();
        } else {
            $from = Carbon::parse($req->input('from', now()->startOfMonth()->toDateString()))->startOfDay();
            $to   = Carbon::parse($req->input('to',   now()->endOfMonth()->toDateString()))->endOfDay();
        }
        return [$from, $to];
    }

    private function baseQuery(Carbon $from, Carbon $to)
    {
        if (Schema::hasTable('appointments') && Schema::hasColumn('appointments','start_at')) {
            return DB::table('appointments')
                ->whereBetween('start_at', [$from, $to])
                ->select('id','start_at','status','service_slug');
        }
        if (Schema::hasTable('orders') && Schema::hasColumn('orders','appointment_at')) {
            return DB::table('orders')
                ->whereBetween('appointment_at', [$from, $to])
                ->select(DB::raw('id'), DB::raw('appointment_at as start_at'), DB::raw("COALESCE(status,'') as status"), DB::raw("COALESCE(service_slug,'') as service_slug"));
        }
        // empty query
        return DB::table(DB::raw('(select 1) dummy'))->whereRaw('1=0')->selectRaw('1 as id, NOW() as start_at, "" as status, "" as service_slug');
    }
}