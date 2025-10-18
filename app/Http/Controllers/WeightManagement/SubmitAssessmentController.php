<?php

namespace App\Http\Controllers\WeightManagement;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\ClinicForm;
use App\Models\Order;
use App\Models\ConsultationSession;

class SubmitAssessmentController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'answers' => 'required|array',
            // add fields your frontend sends (product, type=new|transfer, etc.)
            'lines'   => 'required|array', // e.g. [{ sku, name, qty, ... }]
            'type'    => 'required|string|in:new,transfer',
            'service' => 'nullable|string', // if you send it; default below
            'treatment' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request) {
            $answers   = $request->input('answers');
            $lines     = $request->input('lines', []);
            $type      = $request->input('type', 'new');
            $service   = $request->input('service', 'weight-management-service');
            $treatment = $request->input('treatment', 'mounjaro');

            $form = ClinicForm::where('service_slug', 'weight-management-service')->firstOrFail();

            // 1) Persist consultation session
            $session = ConsultationSession::create([
                'service'   => $service,
                'treatment' => $treatment,
                'form_id'   => $form->id,
                'form_type' => 'raf',
                'answers'   => $answers,
                'order_id'  => null,
                'user_id'   => auth()->id(), // if you track user
            ]);

            // 2) Create pending order with assessment embedded in meta
            $order = Order::create([
                'user_id' => auth()->id(),
                'status'  => 'pending',
                'meta'    => [
                    'service'    => 'Weight Management Service',
                    'type'       => $type,
                    'lines'      => $lines,        // use what your cart sends
                    'form_type'  => 'raf',
                    'form_id'    => $form->id,
                    'session_id' => $session->id,
                    'form_data'  => $answers,      // <- Pending page can render this
                ],
            ]);

            $session->update(['order_id' => $order->id]);

            return response()->json([
                'ok'    => true,
                'order' => $order->id,
                'ref'   => $order->reference ?? null,
            ]);
        });
    }
}