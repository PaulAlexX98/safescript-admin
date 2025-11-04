<?php

use App\Http\Controllers\PageApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\Order;
use App\Http\Controllers\ClinicFormApiController;
use App\Http\Controllers\SubmitAssessmentController;
use App\Models\ClinicForm;

Route::get('/debug/order/by-ref/{reference}', function (Request $req, string $reference) {
    $o = \App\Models\Order::where('reference', $reference)->firstOrFail();
    return response()->json([
        'id' => $o->id,
        'reference' => $o->reference,
        'payment_status' => $o->payment_status,
        'booking_status' => $o->booking_status,
        'meta_items_0_variations' => data_get($o, 'meta.items.0.variations'),
        'meta_lines_0_variation'  => data_get($o, 'meta.lines.0.variation'),
        'meta_items_0_unitMinor'  => data_get($o, 'meta.items.0.unitMinor'),
        'meta_items_0_totalMinor' => data_get($o, 'meta.items.0.totalMinor'),
        'meta_totalMinor'         => data_get($o, 'meta.totalMinor'),
        'selectedProduct'         => data_get($o, 'meta.selectedProduct'),
    ]);
});
Route::get('pages/slug/{slug}', [PageApiController::class, 'showBySlug']);
Route::get('pages/{id}', [PageApiController::class, 'showById']);
Route::match(['get','post'], '/sessions/{session}/weight-management-service', [ClinicFormApiController::class, 'handle'])
    ->middleware('auth:sanctum');

Route::middleware(['auth:sanctum']) // or your auth
    ->post('/weight/assessment/submit', SubmitAssessmentController::class);

Route::get('/raf/{service}', function (string $service) {
    $schema = ClinicForm::where('service_slug', $service)->value('raf_schema') ?? ['stages' => []];
    return response()->json(['ok' => true, 'schema' => $schema]);
});

use App\Support\Settings as AppSettings;

Route::get('/theme', function () {
    return response()->json([
        'cardTheme' => AppSettings::get('card_theme', 'sky'),
    ]);
});