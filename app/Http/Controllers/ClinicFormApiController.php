<?php

namespace App\Http\Controllers;

use App\Models\ClinicForm;
use App\Models\ConsultationSession;
use Illuminate\Http\Request;

class ClinicFormApiController extends Controller
{
    public function handle(Request $request, ConsultationSession $session)
    {
        $form = ClinicForm::where('slug', 'weight-management-service')->firstOrFail();
        $versionId = $session->form_version_id ?: $form->raf_version;
        if (!$session->form_version_id) {
            $session->update(['form_version_id' => $versionId]);
        }

        if ($request->isMethod('get')) {
            $answers = $session->form_submission->answers_json ?? [];
            return response()->json([
                'version' => $versionId,
                'schema'  => $form->raf_schema ?? ['stages' => []],
                'answers' => $answers,
            ]);
        }

        $data = $request->validate([
            'action'  => 'required|string|in:save,submit',
            'answers' => 'required|array',
        ]);

        $submission = $session->form_submission()->updateOrCreate([], [
            'answers_json' => $data['answers'],
            'status' => $data['action'] === 'submit' ? 'submitted' : 'draft',
            'submitted_at' => $data['action'] === 'submit' ? now() : null,
        ]);

        return response()->json(['ok' => true, 'status' => $submission->status]);
    }
}