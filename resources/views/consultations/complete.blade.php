{{-- resources/views/consultations/complete.blade.php --}}

@once
    <style>
      .cf-section-card{border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:24px;margin-top:20px;box-shadow:0 1px 2px rgba(0,0,0,.45)}
      .cf-grid{display:grid;grid-template-columns:1fr;gap:16px}
      .cf-field-card{border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:18px}
      .cf-title{font-weight:600;font-size:16px;margin:0 0 6px 0}
      .cf-summary{font-size:13px;margin:0}
      .cf-label{font-size:14px;display:block;margin-bottom:6px}
      .cf-help{font-size:12px;margin-top:6px}
      .cf-ul{list-style:disc;padding-left:20px;margin:0}
      .cf-ul li{margin:4px 0}
      .cf-paras p{margin:8px 0;line-height:1.6}
      @media(min-width:768px){.cf-section-card{padding:28px}.cf-grid{gap:20px}.cf-field-card{padding:20px}}
    </style>

@endonce

@php
    $shippingMeta = [];
    if (isset($order)) {
        $rawMeta = $order->meta ?? [];
        $metaArr = is_array($rawMeta) ? $rawMeta : (json_decode($rawMeta ?? '[]', true) ?: []);
        $shippingMeta = $metaArr['shipping'] ?? [];
    }
@endphp

<div class="mb-4 flex flex-col gap-2">
    {{-- Consultation completion banner --}}
    <div class="inline-flex items-center gap-2 rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
        <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
        <span>Consultation completion step</span>
    </div>

    {{-- Royal Mail banner when shipping meta indicates Click and Drop --}}
    @if (!empty($shippingMeta) && ($shippingMeta['carrier'] ?? null) === 'royal_mail_click_and_drop')
        <div class="inline-flex flex-wrap items-center gap-2 rounded-md border border-sky-300 bg-sky-50 px-3 py-2 text-sm text-sky-900">
            <span class="font-medium">Royal Mail Click and Drop</span>

            @php
                $serviceName = $shippingMeta['service_name'] ?? $shippingMeta['service'] ?? null;
                $statusLabel = $shippingMeta['status_label'] ?? $shippingMeta['status'] ?? null;
                $tracking    = $shippingMeta['tracking'] ?? $shippingMeta['tracking_number'] ?? null;
            @endphp

            @if ($serviceName)
                <span class="text-xs text-sky-800">
                    Service
                    <span class="font-medium">{{ $serviceName }}</span>
                </span>
            @endif

            @if ($statusLabel)
                <span class="text-xs text-sky-800">
                    Status
                    <span class="font-medium">{{ $statusLabel }}</span>
                </span>
            @endif

            @if ($tracking)
                <span class="text-xs text-sky-800">
                    Tracking
                    <span class="font-mono">{{ $tracking }}</span>
                </span>
            @endif
        </div>
    @endif
</div>

<div class="cf-section-card">
    <div class="mb-4">
        <h3 class="cf-title">Complete consultation</h3>
        <p class="cf-summary">
            Review the patient and order details below then confirm you have completed all clinical checks and the record of supply.
        </p></br>
    </div>

    <div class="cf-grid">
        @if ($patient ?? null)
            <div class="cf-field-card">
                <div class="cf-label">Patient</div>
                <div class="text-sm">
                    <div>
                        {{ $patient->full_name ?? $patient->name ?? 'Unknown patient' }}
                    </div>
                    @if ($patient->email ?? null)
                        <div class="text-xs text-gray-500">
                            {{ $patient->email }}
                        </div>
                    @endif
                    @if ($patient->dob ?? null)
                        <div class="text-xs text-gray-500">
                            Date of birth
                            {{ \Illuminate\Support\Carbon::parse($patient->dob)->format('d M Y') }}
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($order ?? null)
            <div class="cf-field-card">
                <div class="text-sm space-y-1">
                    <div>
                        Reference
                        {{ $order->reference ?? $order->id }}
                    </div>
                    @php
                        $service = $order->service ?? null;
                        $treatment = $order->treatment ?? null;
                    @endphp
                    @if ($service || $treatment)
                        <div class="text-xs text-gray-500">
                            @if ($service)
                                Service
                                {{ $service->name ?? $service->title ?? $service->slug ?? '' }}
                            @endif
                            @if ($treatment)
                                 
                                Treatment
                                {{ $treatment->name ?? $treatment->title ?? $treatment->slug ?? '' }}
                            @endif
                        </div>
                    @endif
                    @if (isset($order->created_at))
                        <div class="text-xs text-gray-500">
                            Order created
                            {{ optional($order->created_at)->timezone('Europe/London')->format('d M Y H:i') }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

<div class="cf-section-card">
    <div class="mb-4">
        <h3 class="cf-title">Before you complete</h3>
        <p class="cf-summary">
            Once completed you will not normally edit the consultation further.
        </p>
    </div>
    <ul class="cf-ul text-sm">
        <li>Confirm that all consultation steps are saved including risk assessment pharmacist advice and record of supply</li>
        <li>Ensure the medication details batch and expiry and directions are correct</li>
        <li>Check that clinical notes reflect your final decision</li>
    </ul>
</div>

<form
    id="consult-complete-form"
    method="post"
    action="{{ url('/admin/consultations/' . ($session->id ?? $session->getKey()) . '/complete') }}"
    class="hidden"
>
    @csrf
    <input type="hidden" name="confirm_complete" value="1">
</form>
