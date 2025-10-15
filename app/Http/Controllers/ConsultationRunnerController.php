<?php

namespace App\Http\Controllers;

use App\Models\ConsultationSession;

class ConsultationRunnerController
{
    public function pharmacistAdvice(ConsultationSession $session)
    {
        return redirect()->to("/admin/consultations/{$session->id}/pharmacist-advice");
    }

    public function pharmacistDeclaration(ConsultationSession $session)
    {
        return redirect()->to("/admin/consultations/{$session->id}/pharmacist-declaration");
    }

    public function recordOfSupply(ConsultationSession $session)
    {
        return redirect()->to("/admin/consultations/{$session->id}/record-of-supply");
    }

    public function riskAssessment(ConsultationSession $session)
    {
        return redirect()->to("/admin/consultations/{$session->id}/risk-assessment");
    }

    public function patientDeclaration(ConsultationSession $session)
    {
        return redirect()->to("/admin/consultations/{$session->id}/patient-declaration");
    }
}