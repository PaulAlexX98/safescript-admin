<?php
// app/Http/Controllers/Admin/ConsultationPdfController.php
namespace App\Http\Controllers\Admin;

use Log;
use Throwable;
use App\Http\Controllers\Controller;
use App\Models\ConsultationSession;
use App\Models\ConsultationFormResponse;
use App\Models\Order;
use App\Models\Patient;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class ConsultationPdfController extends Controller
{
    protected function baseData(ConsultationSession $session): array
    {
        $order = Order::query()
            ->with('patient')
            ->where('meta->consultation_session_id', $session->id)
            ->latest()
            ->first();

        $meta = is_array($order?->meta) ? $order->meta : (json_decode($order?->meta ?? '[]', true) ?: []);

        // Resolve Patient model (prefer relations, then IDs in order/session/meta, then by contact)
        $patientModel = $order?->patient ?? ($session->patient ?? null);
        if (!$patientModel) {
            $candidateId = $order->patient_id
                ?? ($session->patient_id ?? null)
                ?? data_get($meta, 'patient_id')
                ?? data_get($meta, 'patient.id');

            if ($candidateId) {
                $patientModel = Patient::query()->find($candidateId);
            }

            if (!$patientModel) {
                $email = $meta['email'] ?? data_get($meta, 'patient.email');
                $dob   = $meta['dob'] ?? data_get($meta, 'patient.dob');
                if ($email || $dob) {
                    $q = Patient::query();
                    if ($email) { $q->where('email', $email); }
                    if ($dob)   { $q->whereDate('dob', $dob); }
                    $patientModel = $q->first();
                }
            }
        }

        // Name (prefer Patient model)
        $first = $patientModel?->first_name
            ?? $meta['first_name'] ?? $meta['firstName'] ?? $meta['given_name'] ?? '';
        $last  = $patientModel?->last_name
            ?? $meta['last_name']  ?? $meta['lastName']  ?? $meta['family_name'] ?? '';
        $fallbackName = trim(($meta['name'] ?? '') !== '' ? (string)$meta['name'] : trim($first.' '.$last));
        $fullName = $patientModel?->full_name ?? $patientModel?->name ?? $fallbackName;

        // DOB (prefer Patient model)
        $dob = $patientModel?->dob ?? ($meta['dob'] ?? $meta['date_of_birth'] ?? null);

        // Contact (prefer Patient model)
        $email = $patientModel?->email ?? ($meta['email'] ?? $meta['contact_email'] ?? null);
        $phone = $patientModel?->phone ?? ($meta['phone'] ?? $meta['mobile'] ?? $meta['contact_phone'] ?? null);

        // Address (prefer shipping recipient address; then Patient model; then patient_snapshot; then meta/session)
        $ship = data_get($meta, 'shipping.request.items.0.recipient.address', []);

        $shipFullName = data_get($ship, 'fullName');
        $shipAddr1    = data_get($ship, 'addressLine1') ?? data_get($ship, 'address_line1') ?? data_get($ship, 'line1');
        $shipAddr2    = data_get($ship, 'addressLine2') ?? data_get($ship, 'address_line2') ?? data_get($ship, 'line2');
        $shipCity     = data_get($ship, 'city');
        $shipPostcode = data_get($ship, 'postcode');
        $shipCountry  = data_get($ship, 'country') ?? data_get($ship, 'countryName');
        $shipCountryCode = data_get($ship, 'countryCode');

        // If shipping only provides a country code, map GB/UK to United Kingdom
        if ((!$shipCountry || trim((string) $shipCountry) === '') && $shipCountryCode) {
            $cc = strtoupper(trim((string) $shipCountryCode));
            if (in_array($cc, ['GB', 'UK'], true)) {
                $shipCountry = 'United Kingdom';
            } else {
                $shipCountry = $cc;
            }
        }

        $addr1 = $shipAddr1
            ?? $patientModel?->address1
            ?? data_get($meta, 'patient_snapshot.address1')
            ?? $meta['address1'] ?? data_get($meta, 'patient.address1')
            ?? data_get($session->meta ?? [], 'address1') ?? data_get($session->meta ?? [], 'patient.address1');

        $addr2 = $shipAddr2
            ?? $patientModel?->address2
            ?? data_get($meta, 'patient_snapshot.address2')
            ?? $meta['address2'] ?? data_get($meta, 'patient.address2')
            ?? data_get($session->meta ?? [], 'address2') ?? data_get($session->meta ?? [], 'patient.address2');

        $city  = $shipCity
            ?? $patientModel?->city
            ?? data_get($meta, 'patient_snapshot.city')
            ?? $meta['city'] ?? data_get($meta, 'patient.city')
            ?? data_get($session->meta ?? [], 'city') ?? data_get($session->meta ?? [], 'patient.city');

        // Some datasets use county/state/region; include it if available
        $county = $patientModel?->county
            ?? data_get($meta, 'patient_snapshot.county')
            ?? data_get($meta, 'patient_snapshot.state')
            ?? data_get($meta, 'patient_snapshot.region')
            ?? $meta['county'] ?? $meta['state'] ?? $meta['region']
            ?? data_get($meta, 'patient.county') ?? data_get($meta, 'patient.state') ?? data_get($meta, 'patient.region')
            ?? data_get($session->meta ?? [], 'county') ?? data_get($session->meta ?? [], 'state') ?? data_get($session->meta ?? [], 'region')
            ?? data_get($session->meta ?? [], 'patient.county') ?? data_get($session->meta ?? [], 'patient.state') ?? data_get($session->meta ?? [], 'patient.region');

        $pc    = $shipPostcode
            ?? $patientModel?->postcode
            ?? data_get($meta, 'patient_snapshot.postcode')
            ?? $meta['postcode'] ?? $meta['postal_code'] ?? data_get($meta, 'patient.postcode')
            ?? data_get($session->meta ?? [], 'postcode') ?? data_get($session->meta ?? [], 'patient.postcode');

        $ctry  = $shipCountry
            ?? $patientModel?->country
            ?? data_get($meta, 'patient_snapshot.country')
            ?? $meta['country'] ?? data_get($meta, 'patient.country')
            ?? data_get($session->meta ?? [], 'country') ?? data_get($session->meta ?? [], 'patient.country');

        $addressParts = collect([$addr1, $addr2, $city, $county, $pc, $ctry])
            ->filter(fn ($v) => trim((string) $v) !== '')
            ->values()
            ->all();

        $address = trim(implode(', ', $addressParts));
        $addressMultiline = trim(implode("\n", $addressParts));

        // Items
        $items = Arr::wrap(
            $meta['items'] ?? $meta['products'] ?? $meta['lines'] ?? $meta['line_items'] ?? []
        );

        // Fetch Pharmacist Declaration / Advice details (name, GPhC, signature)
        $pharmacistName = null;
        $pharmacistGphc = null;
        $pharmacistSignature = null;

        try {
            $resp = ConsultationFormResponse::query()
                ->where('consultation_session_id', $session->id)
                ->where(function ($q) {
                    $q->whereIn('step_slug', [
                        'pharmacist-declaration', 'pharmacist_declaration',
                        'pharmacist-advice', 'pharmacist_advice',
                    ])->orWhereIn('form_type', [
                        'pharmacist_declaration', 'pharmacist_advice',
                    ]);
                })
                ->latest('id')
                ->first();

            if ($resp) {
                $rdata = is_array($resp->data) ? $resp->data : (json_decode($resp->data ?? '[]', true) ?: []);

                // Name candidates
                $pharmacistName = data_get($rdata, 'pharmacist_name')
                    ?? data_get($rdata, 'pharmacist.name')
                    ?? data_get($rdata, 'name')
                    ?? data_get($rdata, 'pharmacist_full_name')
                    ?? data_get($rdata, 'field_0');

                // GPhC candidates
                $pharmacistGphc = data_get($rdata, 'gphc_number')
                    ?? data_get($rdata, 'pharmacist_gphc')
                    ?? data_get($rdata, 'pharmacist.gphc')
                    ?? data_get($rdata, 'gphc')
                    ?? data_get($rdata, 'field_1');

                // Signature candidates (data URI / base64)
                $pharmacistSignature = data_get($rdata, 'signature')
                    ?? data_get($rdata, 'pharmacist_signature')
                    ?? data_get($rdata, 'signature.data')
                    ?? data_get($rdata, 'signature_image')
                    ?? data_get($rdata, 'field_2');
            }
        } catch (Throwable $e) {
            // non-fatal; continue without pharmacist details
        }

        return [
            'ref'     => $order->reference ?? ('PWLN'.$session->id),
            'order'   => $order,
            'session' => $session,
            'meta'    => $meta,
            'patient' => [
                'name'    => $fullName,
                'dob'     => $dob,
                'email'   => $email,
                'phone'   => $phone,
                'address' => $address,
                'address_multiline' => $addressMultiline,
                'address_parts' => $addressParts,
            ],
            'pharmacist' => [
                'name'      => $pharmacistName,
                'gphc'      => $pharmacistGphc,
                'signature' => $pharmacistSignature,
            ],
            'pharmacy' => [
                'name'    => 'Pharmacy Express',
                'address' => 'Unit 4, The Office Campus Paragon Business Park, Wakefield, West Yorkshire WF1 2UY',
                'tel'     => '01924 971414',
                'email'   => 'info@pharmacy-express.co.uk',
                'vat'     => '274797643',
                'logo'    => public_path('pharmacy-express-logo.png'), // your path
            ],
            'items'   => $items,
        ];
    }

    public function generateAndStorePdfs(ConsultationSession $session): array
    {
        $data = $this->baseData($session);

        /** @var \App\Models\Order|null $order */
        $order = $data['order'] ?? null;
        if (! $order) {
            return [];
        }

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        $ref = $data['ref'] ?? ('PWLN' . $session->id);

        // Store under storage/app/public/consultations/{session_id}
        $disk = Storage::disk('public');
        $dir  = 'consultations/' . $session->id;

        if (! $disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        $pdfConfigs = [
            [
                'key'      => 'record_of_supply',
                'view'     => 'pdf.record-of-supply',
                'filename' => $ref . '_supply.pdf',
            ],
            [
                'key'      => 'invoice',
                'view'     => 'pdf.invoice',
                'filename' => $ref . '_invoice.pdf',
            ],
            [
                'key'      => 'notification_of_treatment',
                'view'     => 'pdf.notification-of-treatment',
                'filename' => $ref . '_notification-of-treatment-issued.pdf',
            ],
        ];

        $meta['pdfs'] = is_array($meta['pdfs'] ?? null) ? $meta['pdfs'] : [];

        $results = [];

        foreach ($pdfConfigs as $cfg) {
            try {
                $pdf = Pdf::loadView($cfg['view'], $data)->setPaper('a4');

                $relativePath = $dir . '/' . $cfg['filename'];

                // Write the PDF bytes to the public disk
                $disk->put($relativePath, $pdf->output());

                $publicPath = '/storage/' . $relativePath;

                $meta['pdfs'][$cfg['key']] = $publicPath;
                $results[$cfg['key']]      = $publicPath;
            } catch (Throwable $e) {
                Log::warning('Failed to generate consultation PDF', [
                    'session_id' => $session->id,
                    'order_id'   => $order->id ?? null,
                    'view'       => $cfg['view'] ?? null,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $order->meta = $meta;
        $order->save();

        return $results;
    }

    public function full(ConsultationSession $session)
    {
        $data = $this->baseData($session);
        return Pdf::loadView('pdf.consultation-full', $data)
            ->setPaper('a4')
            ->download("{$data['ref']}_full.pdf");
    }

    public function ros(ConsultationSession $session)
    {
        $data = $this->baseData($session);
        return Pdf::loadView('pdf.record-of-supply', $data)
            ->setPaper('a4')
            ->download("{$data['ref']}_supply.pdf");
    }

    public function invoice(ConsultationSession $session)
    {
        $data = $this->baseData($session);
        return Pdf::loadView('pdf.invoice', $data)
            ->setPaper('a4')
            ->download("{$data['ref']}_invoice.pdf");
    }
    public function pre(ConsultationSession $session)
    {
        $data = $this->baseData($session);
        return Pdf::loadView('pdf.private-prescription', $data)
            ->setPaper('a4')
            ->download("{$data['ref']}_private-prescription.pdf");
    }
    public function prePatient(ConsultationSession $session)
    {
        $data = $this->baseData($session);
        return Pdf::loadView('pdf.private-prescription-for-patients', $data)
            ->setPaper('a4')
            ->download("{$data['ref']}_private-prescription-patient.pdf");
    }
    
    public function notificationOfTreatmentIssued(ConsultationSession $session)
    {
        $data = $this->baseData($session);
        return Pdf::loadView('pdf.notification-of-treatment', $data)
            ->setPaper('a4')
            ->download("{$data['ref']}_notification-of-treatment-issued.pdf");
    }
}