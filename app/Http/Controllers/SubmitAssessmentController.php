<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\ClinicForm;
use App\Models\Order;
use App\Models\ConsultationSession;

class SubmitAssessmentController extends Controller
{
    public function __invoke(Request $request)
    {
        // Be tolerant to different payload shapes and JSON-encoded bodies
        $payload = $request->all();

        // Normaliser that always returns an array
        $normalizeToArray = function ($value) {
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value)) {
                $t = trim($value);
                if ($t === '') {
                    return [];
                }
                try {
                    $decoded = json_decode($t, true, 512, JSON_THROW_ON_ERROR);
                    return is_array($decoded) ? $decoded : [];
                } catch (\Throwable $e) {
                    return [];
                }
            }
            return [];
        };

        // Answers can arrive as an array or a JSON string under various keys
        $answers = $normalizeToArray($payload['answers'] ?? ($payload['form'] ?? ($payload['fields'] ?? [])));

        $lines      = $payload['lines'] ?? [];
        $type       = $payload['type'] ?? 'new';
        $service    = $payload['service'] ?? 'weight-management-service';
        $treatment  = $payload['treatment'] ?? null;
        $formType   = $payload['form_type'] ?? $payload['step'] ?? $payload['current'] ?? 'raf';
        $sessionId  = $payload['session_id'] ?? null;
        $orderId    = $payload['order_id'] ?? null;
        $orderRef   = $payload['order_ref'] ?? null;

        // Normalise the form "type" into a consistent snake_key used everywhere
        $normaliseKey = function ($s) {
            if (! is_string($s)) return 'raf';
            $s = trim($s);
            // convert spaces and hyphens to underscores, drop other non-word chars
            $s = str_replace([' ', '-'], '_', $s);
            $s = preg_replace('/[^A-Za-z0-9_]/', '', $s);
            $s = strtolower($s);
            return $s !== '' ? $s : 'raf';
        };
        $formKey = $normaliseKey($formType);

        $result = DB::transaction(function () use ($answers, $lines, $type, $service, $treatment, $formType, $formKey, $sessionId, $orderId, $orderRef) {
            // Try to locate an existing session in several ways
            $session = null;
            if ($sessionId) {
                $session = ConsultationSession::find($sessionId);
            }
            if (! $session && $orderId) {
                $session = ConsultationSession::where('order_id', $orderId)->first();
            }
            if (! $session && is_string($orderRef) && $orderRef !== '') {
                $order = Order::where('reference', $orderRef)->first();
                if ($order) {
                    $session = ConsultationSession::where('order_id', $order->id)->first();
                }
            }
            if (! $session) {
                // Fall back to the most recent session for this user and service
                $session = ConsultationSession::where('user_id', auth()->id())
                    ->where('service', $service)
                    ->latest()
                    ->first();
            }

            // Optional form template lookup (best-effort)
            $form = ClinicForm::query()
                ->where('service_slug', $service)
                ->when(\Schema::hasColumn('clinic_forms', 'form_type'), fn ($q) => $q->where('form_type', $formKey))
                ->first();

            if (! $session) {
                // Create a fresh session with a fully-initialised meta structure
                $session = ConsultationSession::create([
                    'service'   => $service,
                    'treatment' => $treatment,
                    'form_id'   => $form?->id,
                    'form_type' => $formKey,
                    'order_id'  => null,
                    'user_id'   => auth()->id(),
                    'meta'      => [
                        'current'   => $formKey,
                        'steps'     => [$formKey],
                        'answers'   => [$formKey => $answers],
                        'templates' => $form ? [
                            $formKey => [
                                'id'      => $form->id,
                                'name'    => $form->name ?? $form->title ?? strtoupper($formKey),
                                'version' => $form->version ?? 1,
                            ],
                        ] : [],
                    ],
                ]);
            } else {
                // Merge into existing session meta safely
                $meta = $session->meta;
                if (! is_array($meta)) {
                    $meta = (is_string($meta) ? json_decode($meta, true) : []) ?: [];
                }

                $meta['current'] = $formKey;
                $meta['steps']   = array_values(array_unique(array_merge($meta['steps'] ?? [], [$formKey])));
                if (! isset($meta['answers']) || ! is_array($meta['answers'])) {
                    $meta['answers'] = [];
                }
                $existing = data_get($meta, "answers.$formKey", []);
                if (! is_array($existing)) {
                    $existing = [];
                }
                $meta['answers'][$formKey] = array_replace($existing, $answers);

                if ($form) {
                    $meta['templates'][$formKey] = [
                        'id'      => $form->id,
                        'name'    => $form->name ?? $form->title ?? strtoupper($formKey),
                        'version' => $form->version ?? 1,
                    ];
                    $session->form_id   = $form->id;
                    $session->form_type = $formKey;
                }

                $session->meta = $meta;
                $session->save();
            }

            // Ensure an order exists and mirror `formsQA` there for pending views
            $order = $session->order_id ? Order::find($session->order_id) : null;
            if (! $order) {
                $order = Order::create([
                    'user_id' => auth()->id(),
                    'status'  => 'pending',
                    'meta'    => [
                        'service'    => $service,
                        'treatment'  => $treatment,
                        'type'       => $type,
                        'lines'      => $lines,
                        'session_id' => $session->id,
                        'form_type'  => $formKey,
                        'form_id'    => $form?->id,
                        'formsQA'    => [
                            $formKey => [
                                'qa'  => [],
                                'raw' => $session->meta['answers'][$formKey] ?? $answers,
                            ],
                        ],
                    ],
                ]);
                $session->order_id = $order->id;
                $session->save();
            } else {
                $om = $order->meta;
                if (! is_array($om)) {
                    $om = (is_string($om) ? json_decode($om, true) : []) ?: [];
                }
                if (! isset($om['formsQA']) || ! is_array($om['formsQA'])) {
                    $om['formsQA'] = [];
                }

                $om['service']    = $service;
                $om['treatment']  = $treatment ?? ($om['treatment'] ?? null);
                $om['type']       = $type;
                $om['lines']      = $lines ?: ($om['lines'] ?? []);
                $om['session_id'] = $session->id;
                $om['form_type']  = $formKey;
                $om['form_id']    = $form?->id ?? ($om['form_id'] ?? null);

                $raw = $session->meta['answers'][$formKey] ?? $answers;
                $om['formsQA'][$formKey]['raw'] = $raw;

                $order->meta = $om;
                $order->save();
            }

            return [
                'ok'         => true,
                'session_id' => $session->id,
                'order_id'   => $order->id,
                'reference'  => $order->reference ?? null,
                'meta'       => $session->meta,
            ];
        });
        return response()->json($result);
    }
}