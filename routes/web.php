<?php


use Illuminate\Support\Facades\Route;

// Note: The /admin/consultations/{session} route is registered by Filament in AdminPanelProvider; do not add it here to avoid duplicates.


Route::get('/__routes_ping', function () {
    return response()->json(['ok' => true, 'src' => 'routes/web.php']);
})->name('__routes.ping');

Route::redirect('/', '/admin'); // send the homepage to your Filament panel

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use App\Models\PendingOrder;
use App\Models\ApprovedOrder;
use App\Models\ClinicForm; 
use App\Models\ConsultationFormResponse;
use App\Http\Controllers\Admin\ConsultationPdfController;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;


Route::middleware([
    'web',
    FilamentAuthenticate::class,               // <- Filament’s auth middleware
])
->prefix('admin/consultations/{session}')
->name('admin.consultations.')
->group(function () {
    Route::get('pdf/full',            [ConsultationPdfController::class, 'full'])->name('pdf.full');
    Route::get('pdf/private-prescription',[ConsultationPdfController::class, 'pre'])->name('pdf.pre');
    Route::get('pdf/record-of-supply',[ConsultationPdfController::class, 'ros'])->name('pdf.ros');
    Route::get('pdf/invoice',         [ConsultationPdfController::class, 'invoice'])->name('pdf.invoice');
});

// Allow {form} route parameter to resolve from either a ClinicForm id
// or a ConsultationFormResponse id, mapping responses to their template ClinicForm.
Route::bind('form', function ($value) {
    // Allow ConsultationFormResponse id first
    if ($resp = \App\Models\ConsultationFormResponse::find($value)) {
        return $resp; // pass the response model through
    }

    // Fallback to ClinicForm id for template level routes or save endpoints
    if ($cf = \App\Models\ClinicForm::find($value)) {
        return $cf;
    }

    // Do not abort here. Return the raw value so the controller can decide what to do.
    return is_numeric($value) ? (int) $value : $value;
});


// Optionally, constrain numeric parameters early for clarity
// Route::pattern('session', '[0-9]+');
// Route::pattern('form', '[0-9]+');
use App\Services\Consultations\StartConsultation;
use App\Filament\Pages\ConsultationRunner;
use App\Models\ConsultationSession;
use App\Http\Controllers\ConsultationFormController;
use App\Http\Controllers\ConsultationRunnerController;

// Fallback for any middleware that uses route('login'):
Route::get('/login', fn () => redirect('/admin/login'))->name('login');

// Legacy redirect for old consultation runner links
Route::middleware(['web', \Filament\Http\Middleware\Authenticate::class . ':admin'])
    ->get('/admin/consultation-runner', function (Request $request) {
        $sessionId = (string) $request->query('session');
        $tab = (string) $request->query('tab', 'pharmacist-advice');
        $map = [
            'advice' => 'pharmacist-advice',
            'declaration' => 'pharmacist-declaration',
            'supply' => 'record-of-supply',
        ];
        $tab = $map[$tab] ?? $tab;
        abort_if(!$sessionId, 404);
        return redirect("/admin/consultations/{$sessionId}/{$tab}", 302);
    });

// Legacy redirect (unchanged logic, explicit numeric check already present)
Route::get('/admin/forms', function (Request $request) {
    $orderId = (int) $request->query('order', 0);
    abort_if($orderId <= 0, 404);

    $order = ApprovedOrder::findOrFail($orderId);
    $session = app(StartConsultation::class)($order);

    return redirect()->route('consultations.pharmacist_advice', ['session' => $session->id]);
})->name('admin.forms.legacy')->middleware(['web', \Filament\Http\Middleware\Authenticate::class . ':admin']);


Route::middleware(['web', \Filament\Http\Middleware\Authenticate::class . ':admin'])
    ->prefix('admin/consultations')
    ->group(function () {
        Route::post('{session}/forms/{form}/save', [ConsultationFormController::class, 'save'])
            ->name('consultations.forms.save');

        Route::post('{session}/start', function (\App\Models\ConsultationSession $session) {
            return redirect()->to("/admin/consultations/{$session->id}");
        })->name('consultations.start');

        // Hard redirect any /{session}/form hits to the base session with a valid tab key
        Route::get('{session}/form', function (\App\Models\ConsultationSession $session, Request $request) {
            $tab = (string) $request->query('tab', 'pharmacist-declaration');
            // normalise underscore to hyphen and whitelist known tabs
            $tab = str_replace('_', '-', strtolower($tab));
            $allowed = ['pharmacist-declaration','pharmacist-advice','record-of-supply','risk-assessment','patient-declaration'];
            if (!in_array($tab, $allowed, true)) {
                $tab = 'pharmacist-declaration';
            }
            return redirect()->to("/admin/consultations/{$session->id}?tab={$tab}", 302);
        })->name('consultations.form_legacy');

        Route::get('{session}/pharmacist-advice', function (\App\Models\ConsultationSession $session) {
            return redirect()->to("/admin/consultations/{$session->id}?tab=pharmacist-advice");
        })->name('consultations.pharmacist_advice');

        Route::get('{session}/pharmacist-declaration', function (\App\Models\ConsultationSession $session) {
            return redirect()->to("/admin/consultations/{$session->id}?tab=pharmacist-declaration");
        })->name('consultations.pharmacist_declaration');

        Route::get('{session}/record-of-supply', function (\App\Models\ConsultationSession $session) {
            return redirect()->to("/admin/consultations/{$session->id}?tab=record-of-supply");
        })->name('consultations.record_of_supply');

        Route::get('{session}/risk-assessment', [ConsultationRunnerController::class, 'riskAssessment'])
            ->name('consultations.risk_assessment');
        Route::get('{session}/patient-declaration', [ConsultationRunnerController::class, 'patientDeclaration'])
            ->name('consultations.patient_declaration');

        Route::get('debug', function () {
            return response()->json(['ok' => true, 'where' => 'admin/consultations group loaded']);
        })->name('consultations.debug');

        // Submitted Forms: View / Edit / History pages (used by CompletedOrderDetails buttons)
        Route::prefix('forms')->name('consultations.forms.')->controller(ConsultationFormController::class)->group(function () {
            Route::get('{session}/{form}/view', 'view')->name('view');
            Route::get('{session}/{form}/history', 'history')->name('history');
            Route::get('{session}/{form}/edit', 'edit')->name('edit');
        });

        // Parameter constraints for clarity
        Route::whereNumber('session');
        Route::whereNumber('form');
    });
Route::get('{session}/{form}/__probe', function (\App\Models\ConsultationSession $session, $form, \Illuminate\Http\Request $req) {
    return response()->json([
        'session_param_type' => get_class($session),
        'session_id'         => $session->id,
        'form_param_type'    => is_object($form) ? get_class($form) : gettype($form),
        'form_payload'       => is_object($form)
                                   ? array_filter([
                                         'id'    => $form->id ?? null,
                                         'consultation_session_id' => $form->consultation_session_id ?? null,
                                         'clinic_form_id' => $form->clinic_form_id ?? null,
                                         'step_slug' => $form->step_slug ?? null,
                                     ])
                                   : $form,
        'inline'             => $req->boolean('inline'),
        'user_id'            => auth()->id(),
    ]);
})->name('consultations.forms.probe');


// Inline view fallback if resources/views/consultations/placeholder.blade.php does not exist
if (!\View::exists('consultations.placeholder')) {
    \View::addNamespace('consultations', resource_path('views/consultations'));
    \View::composer('consultations.placeholder', function ($view) {});
    Route::get('/__inline/consultations/placeholder/{sessionId}', function ($sessionId) {
        return \Illuminate\Support\Facades\Blade::render('<x-filament::page><div class="space-y-4"><h2 class="text-xl font-semibold">Consultation Session</h2><p>Session ID: {{ $sessionId }}</p><p>This is a temporary placeholder page. The full Consultation Runner page class is not yet installed.</p></div></x-filament::page>', compact('sessionId'));
    })->middleware(['web', \Filament\Http\Middleware\Authenticate::class . ':admin'])->name('consultations.inline.placeholder');
}


Route::get('/_debug/pending-order', function (Request $req) {
    $q = PendingOrder::query();

    if ($ref = $req->string('ref')->toString()) {
        $q->where('reference', $ref);
    } elseif ($id = $req->integer('id')) {
        $q->whereKey($id);
    } else {
        $q->latest('created_at');
    }

    $po = $q->firstOrFail();

    $meta = is_array($po->meta) ? $po->meta : (json_decode($po->meta ?? '[]', true) ?: []);

    // Find items in common places
    $items = Arr::get($meta, 'products')
           ?? Arr::get($meta, 'items')
           ?? Arr::get($meta, 'lines')
           ?? Arr::get($meta, 'line_items')
           ?? Arr::get($meta, 'cart.items');

    if (is_string($items)) {
        $decoded = json_decode($items, true);
        if (json_last_error() === JSON_ERROR_NONE) $items = $decoded;
    }

    // If single associative item, wrap as one-element list
    if (is_array($items) && !empty($items)) {
        $isList = array_keys($items) === range(0, count($items) - 1);
        if (!$isList && (isset($items['name']) || isset($items['title']) || isset($items['product_name']))) {
            $items = [$items];
        }
    }

    $keys = [
        'variations','variation','optionLabel','option_label','optionText','option_text','option',
        'variant','dose','strength','strength_text','plan','package','bundle','pack','size','volume',
        'label','text','title','display','displayName','fullLabel','full_option_label',
        'meta.variations','meta.variation','meta.optionLabel','meta.option_label','meta.optionText','meta.option_text','meta.option',
        'selected.variations','selected.variation','selected.optionLabel','selected.option_label','selected.optionText','selected.option_text','selected.option',
        'selectedOption','selected_option','selectedOptions.0','selected_options.0',
    ];

    $findVar = function ($row) use ($keys) {
        foreach ($keys as $k) {
            $v = data_get($row, $k);
            if ($v !== null && $v !== '') {
                if (is_array($v)) {
                    if (array_key_exists('label', $v)) return ['path'=>$k,'value'=>$v['label']];
                    if (array_key_exists('value', $v)) return ['path'=>$k,'value'=>$v['value']];
                            return ['path'=>$k,'value'=>trim(implode(' ', array_map('strval', $v)) )];
                }
                return ['path'=>$k,'value'=>(string)$v];
            }
        }
        return null;
    };

    $lineInfo = [];
    if (is_array($items)) {
        foreach ($items as $i => $it) {
            $lineInfo[] = [
                'index'     => $i,
                'name'      => $it['name'] ?? $it['title'] ?? $it['product_name'] ?? $it,
                'qty'       => $it['qty'] ?? $it['quantity'] ?? 1,
                'variation' => $findVar($it),
            ];
        }
    }

    // Single-item meta-level fallback
    $singleMetaVar = null;
    if (count($lineInfo) === 1 && empty($lineInfo[0]['variation'])) {
        $containers = ['selectedProduct','selected_product','product','item','order.item'];
        foreach ($containers as $c) {
            foreach ($keys as $k) {
                $try = data_get($meta, $c.'.'.$k);
                if ($try !== null && $try !== '') {
                    $singleMetaVar = ['container'=>$c,'path'=>$k,'value'=>is_array($try)?( $try['label'] ?? $try['value'] ?? json_encode($try) ):(string)$try];
                    break 2;
                }
            }
        }
    }

    return response()->json([
        'id'        => $po->id,
        'reference' => $po->reference,
        'status'    => $po->status,
        'meta_keys' => array_keys($meta),
        'items_raw' => $items,
        'lines'     => $lineInfo,
        'selectedProduct' => $meta['selectedProduct'] ?? ($meta['selected_product'] ?? null),
        'single_item_meta_variation' => $singleMetaVar,
    ]);
})->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);


Route::get('/__session_touch', function (\Illuminate\Http\Request $r) {
    $r->session()->put('touched', now()->toIso8601String());
    return response()->json([
        'cookie_name' => config('session.cookie'),
        'session_id' => session()->getId(),
        'touched' => session('touched'),
    ]);
});
