<?php

namespace App\Filament\Resources\WalkIns\Actions;

use App\Models\Order;
use App\Services\Consultations\StartConsultation;
use Filament\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class StartWalkInConsultation
{
    public static function make(): Action
    {
        return Action::make('startConsultation')
            ->label('Start Consultation')
            ->color('success')
            ->icon('heroicon-o-play');
    }

    public static function start(Order $record): string
    {
        // Load meta as array
        $meta = is_array($record->meta)
            ? $record->meta
            : (json_decode($record->meta ?? '[]', true) ?: []);

        // Decide desired form type with broader reorder hints
        $desiredType = 'risk_assessment';

        // 1 explicit query or stored hints
        $rawType = strtolower((string) (
            request()->query('mode')
            ?? request()->query('type')
            ?? data_get($meta, 'consultation.mode')
            ?? data_get($meta, 'consultation.type')
            ?? data_get($meta, 'mode')
            ?? data_get($meta, 'type')
            ?? ''
        ));

        // 2 meta order type and reference pattern
        $orderType = strtolower((string) (
            data_get($meta, 'order.type')
            ?? data_get($meta, 'order.meta.type')
            ?? ''
        ));
        $ref = (string) ($record->reference ?? data_get($meta, 'reference') ?? '');

        $refIsReorder = (bool) preg_match('/^PTC[A-Z]*R[0-9]{6}$/', $ref);

        // 3 boolean flags
        $isReorderFlag = false;
        foreach (['is_reorder', 'reorder', 'flags.reorder'] as $k) {
            $v = data_get($meta, $k);
            if (is_bool($v)) {
                $isReorderFlag = $isReorderFlag || $v;
            } elseif (is_numeric($v)) {
                $isReorderFlag = $isReorderFlag || ((int) $v === 1);
            } elseif (is_string($v)) {
                $isReorderFlag = $isReorderFlag || in_array(strtolower($v), ['1', 'true', 'yes', 'y'], true);
            }
        }

        if (in_array($rawType, ['reorder'], true) || $orderType === 'reorder' || $refIsReorder || $isReorderFlag) {
            $desiredType = 'reorder';
        }

        // Persist the intent so downstream resolvers can pick the right form
        data_set($meta, 'consultation.mode', $desiredType);
        data_set($meta, 'consultation.type', $desiredType);

        // Pull answers from common paths
        $answers = data_get($meta, 'assessment.answers')
            ?? data_get($meta, 'assessment_answers')
            ?? data_get($meta, 'answers')
            ?? null;

        // Normalize answers to string JSON
        $answersJson = null;
        if (is_string($answers)) {
            $decoded = json_decode($answers, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $answersJson = $answers;
            }
        } elseif (is_array($answers)) {
            $answersJson = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($answersJson !== null) {
            data_set($meta, 'assessment.answers', $answersJson);
            data_set($meta, 'assessment_answers', $answersJson);
            data_set($meta, 'answers', $answersJson);
        }

        // Save meta before starting consultation
        $record->meta = $meta;
        $record->save();

        // Store a durable snapshot on the order
        if ($answers !== null) {
            $answersArray = is_array($answers) ? $answers : (json_decode((string) $answers, true) ?: []);
            data_set($meta, 'assessment_snapshot', $answersArray);
            $record->meta = $meta;
            $record->save();

            if ($desiredType === 'risk_assessment') {
                $existingSid = (int) (data_get($meta, 'consultation_session_id') ?? 0);
                if ($existingSid > 0) {
                    DB::table('consultation_form_responses')->updateOrInsert(
                        ['consultation_session_id' => $existingSid, 'form_type' => 'risk_assessment'],
                        [
                            'clinic_form_id' => null,
                            'data' => $answersJson ?? json_encode($answersArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'is_complete' => 1,
                            'completed_at' => now(),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            }
        }

        // Start consultation
        $starter = app(StartConsultation::class);
        try {
            /** @var \App\Models\ConsultationSession $session */
            $session = $starter($record, ['desired_type' => $desiredType]);
        } catch (\ArgumentCountError $e) {
            $session = $starter($record);
        }

        // If a new session exists and we have answers copy them only for risk assessment flow
        if (isset($session) && isset($session->id) && ($answersJson !== null) && $desiredType === 'risk_assessment') {
            DB::table('consultation_form_responses')->updateOrInsert(
                ['consultation_session_id' => (int) $session->id, 'form_type' => 'risk_assessment'],
                [
                    'clinic_form_id' => null,
                    'data' => $answersJson,
                    'is_complete' => 1,
                    'completed_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // Try to persist the session form_type if the attribute exists on the model
        try {
            $attrs = method_exists($session, 'getAttributes') ? $session->getAttributes() : [];
            if (array_key_exists('form_type', $attrs)) {
                $session->form_type = $desiredType;
                $session->save();
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Build redirect with explicit step routing when reorder
        $params = [
            'session' => $session->id,
        ];

        if ($desiredType === 'reorder') {
            if (Route::has('consultations.runner.reorder')) {
                return route('consultations.runner.reorder', $params);
            }
            if (Route::has('consultations.reorder')) {
                return route('consultations.reorder', $params);
            }

            // direct path fallback
            return url("/admin/consultations/{$session->id}/reorder");
        }

        // Fallback to generic start route with type hint
        return route('consultations.runner.start', $params);
    }
}
