<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Shipping\DespatchEmailQueue;
use Illuminate\Http\Request;

class DespatchEmailQueueController extends Controller
{
    public function eligible(DespatchEmailQueue $queue) { $recipients = $queue->eligible(); return response()->json(['count' => count($recipients), 'recipients' => $recipients]); }
    public function send(Request $request, DespatchEmailQueue $queue) { return response()->json($queue->send($request->validate(['order_ids' => ['required','array','min:1'], 'order_ids.*' => ['integer','distinct']])['order_ids'])); }
    public function skip(Request $request, DespatchEmailQueue $queue) { return response()->json($queue->skip($request->validate(['order_ids' => ['required','array','min:1'], 'order_ids.*' => ['integer','distinct']])['order_ids'])); }
}
