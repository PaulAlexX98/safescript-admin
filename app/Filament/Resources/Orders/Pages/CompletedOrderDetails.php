<?php

namespace App\Filament\Resources\Orders\Pages;

use Filament\Actions\ActionGroup;
use Filament\Actions\Action;
use Throwable;
use Carbon\Carbon;
use App\Filament\Resources\Orders\CompletedOrderResource;
use App\Models\ConsultationFormResponse;
use App\Models\Order;
use App\Models\PendingOrder;
use Filament\Actions;
use Illuminate\Support\Facades\Route;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Mail;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use App\Support\ZplLabelBuilder;
use Illuminate\Support\Facades\Log;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;

class CompletedOrderDetails extends ViewRecord
{
    protected static string $resource = CompletedOrderResource::class;

    public function mount($record): void
    {
        parent::mount($record);

        if (request()->query('download') === 'pre') {
            $sessionId = data_get($this->record->meta ?? [], 'consultation_session_id');

            // optional fallback if meta is missing
            if (!$sessionId) {
                try {
                    $sessionId = \Illuminate\Support\Facades\DB::table('consultations')
                        ->where('order_reference', $this->record->reference)
                        ->orderByDesc('id')
                        ->value('session_id');
                } catch (\Throwable $e) {}
            }

            if ($sessionId && Route::has('admin.consultations.pdf.pre')) {
                $url = route('admin.consultations.pdf.pre', ['session' => $sessionId]);
                $this->js("
                    (function(u){
                        try {
                            var f = document.createElement('iframe');
                            f.style.display = 'none';
                            f.setAttribute('aria-hidden', 'true');
                            f.src = u;
                            document.body.appendChild(f);
                            setTimeout(function(){ try { document.body.removeChild(f); } catch(e){} }, 10000);
                        } catch(e) {}
                    })('{$url}');
                ");
            }
        }
    }

    public function getTitle(): string
    {
        return 'Order ' . ($this->record->reference ?? $this->record->getKey());
    }


    protected static function sixMonthReviewRowsForRecord($record): \Illuminate\Support\Collection
    {
        $userId = (int) ($record->user_id ?? optional($record->user)->id ?? 0);

        if ($userId < 1) {
            return collect();
        }

        $rows = collect();

        $collectFromRecord = function ($sourceRecord, string $sourceLabel) use (&$rows): void {
            if (! $sourceRecord) {
                return;
            }

            $meta = is_array($sourceRecord->meta)
                ? $sourceRecord->meta
                : (json_decode($sourceRecord->meta ?? '[]', true) ?: []);

            $reviews = data_get($meta, 'six_month_reviews', []);

            if (! is_array($reviews)) {
                return;
            }

            foreach ($reviews as $index => $review) {
                if (! is_array($review)) {
                    continue;
                }

                $text = trim((string) ($review['text'] ?? $review['review'] ?? $review['note'] ?? ''));

                $date = $review['date'] ?? $review['created_at'] ?? $sourceRecord->created_at ?? null;

                try {
                    $dateText = $date ? Carbon::parse($date)->timezone('Europe/London')->format('d-m-Y H:i') : '—';
                    $sort = $date ? Carbon::parse($date)->timestamp : 0;
                } catch (Throwable $e) {
                    $dateText = (string) $date;
                    $sort = 0;
                }

                $reference = (string) ($sourceRecord->reference ?? '');
                $sourceId = method_exists($sourceRecord, 'getKey') ? $sourceRecord->getKey() : null;
                $sourceType = $sourceRecord instanceof PendingOrder ? 'pending' : 'order';
                $id = implode('|', [$sourceType, $sourceId, $index]);

                $formatNumber = function ($value, int $decimals = 1): string {
                    if ($value === null || $value === '') {
                        return '';
                    }

                    $formatted = number_format((float) $value, $decimals, '.', '');

                    return str_contains($formatted, '.')
                        ? rtrim(rtrim($formatted, '0'), '.')
                        : $formatted;
                };

                $heightUnit = strtolower(trim((string) ($review['height_unit'] ?? '')));
                $weightUnit = strtolower(trim((string) ($review['weight_unit'] ?? '')));

                $heightText = '';
                if ($heightUnit === 'imperial') {
                    $heightParts = [];
                    if (($review['height_ft'] ?? null) !== null && $review['height_ft'] !== '') {
                        $heightParts[] = $formatNumber($review['height_ft']) . ' ft';
                    }
                    if (($review['height_in'] ?? null) !== null && $review['height_in'] !== '') {
                        $heightParts[] = $formatNumber($review['height_in']) . ' in';
                    }
                    $heightText = trim(implode(' ', $heightParts));
                } elseif (($review['height_cm'] ?? null) !== null && $review['height_cm'] !== '') {
                    $heightText = $formatNumber($review['height_cm']) . ' cm';
                } elseif (($review['height'] ?? null) !== null && trim((string) $review['height']) !== '') {
                    $heightText = trim((string) $review['height']);
                }

                $weightText = '';
                if ($weightUnit === 'imperial') {
                    $weightParts = [];
                    if (($review['weight_st'] ?? null) !== null && $review['weight_st'] !== '') {
                        $weightParts[] = $formatNumber($review['weight_st']) . ' st';
                    }
                    if (($review['weight_lb'] ?? null) !== null && $review['weight_lb'] !== '') {
                        $weightParts[] = $formatNumber($review['weight_lb']) . ' lb';
                    }
                    $weightText = trim(implode(' ', $weightParts));
                } elseif (($review['weight_kg'] ?? null) !== null && $review['weight_kg'] !== '') {
                    $weightText = $formatNumber($review['weight_kg']) . ' kg';
                } elseif (($review['weight'] ?? null) !== null && trim((string) $review['weight']) !== '') {
                    $weightText = trim((string) $review['weight']);
                }

                $bmiText = '';
                if (($review['bmi'] ?? null) !== null && $review['bmi'] !== '') {
                    $bmiText = $formatNumber($review['bmi']);
                }

                $measurementParts = [];
                if ($heightText !== '') {
                    $measurementParts[] = 'Height: ' . $heightText;
                }
                if ($weightText !== '') {
                    $measurementParts[] = 'Weight: ' . $weightText;
                }
                if ($bmiText !== '') {
                    $measurementParts[] = 'BMI: ' . $bmiText;
                }
                if ($text === '' && empty($measurementParts)) {
                    continue;
                }

                $rows->push([
                    'id' => $id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'index' => $index,
                    'sort' => $sort,
                    'date' => $dateText,
                    'text' => $text,
                    'measurements' => implode(' | ', $measurementParts),
                    'reference' => $reference,
                    'source' => $sourceLabel,
                ]);
            }
        };

        try {
            PendingOrder::query()
                ->where('user_id', $userId)
                ->whereNotNull('meta')
                ->latest('id')
                ->limit(50)
                ->get()
                ->each(fn ($pending) => $collectFromRecord($pending, 'Pending'));
        } catch (Throwable $e) {
            // Ignore lookup failures.
        }

        try {
            Order::query()
                ->where('user_id', $userId)
                ->whereNotNull('meta')
                ->latest('id')
                ->limit(50)
                ->get()
                ->each(fn ($order) => $collectFromRecord($order, 'Order'));
        } catch (Throwable $e) {
            // Ignore lookup failures.
        }

        return $rows
            ->sortByDesc('sort')
            ->unique(fn ($row) => implode('|', [$row['date'], $row['text'], $row['reference']]))
            ->values();
    }

    protected static function sixMonthReviewHistoryForCompleted($record): string
    {
        $rows = static::sixMonthReviewRowsForRecord($record);

        if ($rows->isEmpty()) {
            return '—';
        }

        return $rows->map(function ($row) {
            $parts = ['[' . $row['date'] . ']'];

            if (! empty($row['measurements'])) {
                $parts[] = $row['measurements'];
            }

            if (! empty($row['text'])) {
                $parts[] = $row['text'];
            }

            return implode("\n", $parts);
        })->implode("\n\n");
    }

    protected static function deleteSixMonthReviewForCompleted($record, string $entryId): bool
    {
        [$sourceType, $sourceId, $index] = array_pad(explode('|', $entryId, 3), 3, null);

        if (! $sourceType || ! $sourceId || $index === null) {
            return false;
        }

        $target = null;

        if ($sourceType === 'pending') {
            $target = PendingOrder::query()->find($sourceId);
        }

        if ($sourceType === 'order') {
            $target = Order::query()->find($sourceId);
        }

        if (! $target) {
            return false;
        }

        $meta = is_array($target->meta)
            ? $target->meta
            : (json_decode($target->meta ?? '[]', true) ?: []);

        $reviews = data_get($meta, 'six_month_reviews', []);

        if (! is_array($reviews) || ! array_key_exists((int) $index, $reviews)) {
            return false;
        }

        unset($reviews[(int) $index]);
        $reviews = array_values($reviews);

        if (empty($reviews)) {
            data_forget($meta, 'six_month_reviews');
        } else {
            data_set($meta, 'six_month_reviews', $reviews);
        }

        $target->meta = $meta;
        $target->save();

        return true;
    }

    protected function getHeaderActions(): array
    {
        $rec = $this->record;
        $sessionId = data_get($rec->meta ?? [], 'consultation_session_id');
        $hasSession = !empty($sessionId);

        return [
            Action::make('print_label')
                ->label('Print label')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->action(function () {
                    $o  = $this->record;
                    $m  = is_array($o->meta) ? $o->meta : [];

                    // Resolve product rows from meta (first of items/lines/products/etc or selectedProduct)
                    $allItems = null;
                    foreach (['items','lines','products','line_items','order.items','order.line_items','cart.items'] as $path) {
                        $arr = data_get($m, $path);
                        if (is_array($arr) && count($arr)) { $allItems = $arr; break; }
                    }
                    if (!$allItems && isset($m['selectedProduct']) && is_array($m['selectedProduct'])) {
                        $allItems = [$m['selectedProduct']];
                    }
                    // Wrap associative product
                    if (is_array($allItems) && !is_numeric(array_key_first($allItems)) && isset($allItems['name'])) {
                        $allItems = [$allItems];
                    }
                    $allItems = is_array($allItems) ? array_values($allItems) : [];

                    // Helper to render a single item as "qty name variation strength" (without literal "x")
                    $renderItem = function (array $row): string {
                        $qty = (int) ($row['qty'] ?? $row['quantity'] ?? 1);
                        $name = trim((string) ($row['name'] ?? $row['title'] ?? $row['product_name'] ?? ''));
                        $var  = trim((string) (
                            $row['variation'] ?? $row['variant'] ?? $row['optionLabel'] ?? $row['strength']
                            ?? data_get($row, 'meta.variation') ?? data_get($row, 'meta.variant') ?? data_get($row, 'meta.optionLabel') ?? data_get($row, 'meta.strength') ?? ''
                        ));
                        $str  = trim((string) ($row['strength'] ?? ''));
                        $parts = [];
                        if ($qty > 1) { $parts[] = (string)$qty; }
                        if ($name !== '') { $parts[] = $name; }
                        if ($var !== '' && strcasecmp($var, $name) !== 0) { $parts[] = $var; }
                        if ($str !== '' && strcasecmp($str, $name) !== 0 && strcasecmp($str, $var) !== 0) { $parts[] = $str; }
                        return trim(implode(' ', $parts));
                    };

                    // Compose line1 with ALL items shown (no "+N more")
                    if (!empty($allItems)) {
                        $lines = [];
                        foreach ($allItems as $row) {
                            $lines[] = $renderItem((array) $row);
                        }
                        // Join all items with a middle dot separator to keep it compact
                        // Example: "Hepatitis A • Typhoid Vi • Malarone 250mg"
                        $line1 = trim(implode(' • ', array_filter($lines, fn ($s) => $s !== '')));
                    } else {
                        $line1 = '';
                    }

                    // Patient
                    $first = $m['firstName'] ?? $o->shipping_address?->first_name ?? '';
                    $last  = $m['lastName']  ?? $o->shipping_address?->last_name  ?? '';
                    $patient = trim($first . ' ' . $last);

                    // Pharmacy sender
                    $pharmacy = 'Pharmacy Express FME51  Unit 4  WF1 2UY';
                    $phone    = '01924971414';

                    // Directions and warnings
                    $directions = 'Use once a week same day as directed';
                    $warningLines = [
                        'Warning. Read the additional information given with this medicine',
                        'Keep out of the reach and sight of children',
                    ];
                    $warning = implode("\n", $warningLines);

                    // Date from approved_at or now
                    $dateText = isset($m['approved_at'])
                        ? \Carbon\Carbon::parse($m['approved_at'])->timezone('Europe/London')->format('d/m/y')
                        : \Carbon\Carbon::now('Europe/London')->format('d/m/y');

                    $payload = [
                        'line1'      => $line1,
                        'directions' => $directions,
                        'warning'    => $warning,     // supports two lines in the builder
                        'patient'    => $patient,
                        'pharmacy'   => $pharmacy,
                        'phone'      => $phone,
                        'date_text'  => $dateText,
                        'qr'         => $o->reference,
                    ];

                    $zpl = app(\App\Support\ZplLabelBuilder::class)->forOrder($payload);

                    logger()->info('print_label dispatch', [
                        'order_id'  => $o->id,
                        'reference' => $o->reference,
                        'len'       => strlen($zpl),
                    ]);

                    $this->dispatch('print-zpl', zpl: $zpl);

                    \Filament\Notifications\Notification::make()
                        ->title('Label sent to browser')
                        ->success()
                        ->send();
                }),

            Action::make('print_address_label')
                ->label('Print address label')
                ->icon('heroicon-o-user')
                ->color('gray')
                ->action(function () {
                    $o = $this->record;
                    $m = is_array($o->meta) ? $o->meta : (json_decode($o->meta ?? '[]', true) ?: []);

                    $user = $o->user ?? null;

                    $first = data_get($m, 'firstName')
                        ?? data_get($m, 'first_name')
                        ?? data_get($m, 'patient.first_name')
                        ?? data_get($m, 'customer.first_name')
                        ?? ($user->first_name ?? '');

                    $last = data_get($m, 'lastName')
                        ?? data_get($m, 'last_name')
                        ?? data_get($m, 'patient.last_name')
                        ?? data_get($m, 'customer.last_name')
                        ?? ($user->last_name ?? '');

                    $patient = trim(trim((string) $first) . ' ' . trim((string) $last));
                    if ($patient === '') {
                        $patient = 'Patient';
                    }

                    $line1 = trim($patient);

                    $address1 = data_get($m, 'shippingAddress.address1')
                        ?? data_get($m, 'shipping.address1')
                        ?? data_get($m, 'shipping.line1')
                        ?? data_get($m, 'address1')
                        ?? data_get($m, 'patient.address1')
                        ?? ($user->shipping_address1 ?? null)
                        ?? ($user->address1 ?? null);

                    $address2 = data_get($m, 'shippingAddress.address2')
                        ?? data_get($m, 'shipping.address2')
                        ?? data_get($m, 'shipping.line2')
                        ?? data_get($m, 'address2')
                        ?? data_get($m, 'patient.address2')
                        ?? ($user->shipping_address2 ?? null)
                        ?? ($user->address2 ?? null);

                    $city = data_get($m, 'shippingAddress.city')
                        ?? data_get($m, 'shipping.city')
                        ?? data_get($m, 'city')
                        ?? data_get($m, 'patient.city')
                        ?? ($user->shipping_city ?? null)
                        ?? ($user->city ?? null);

                    $postcode = data_get($m, 'shippingAddress.postcode')
                        ?? data_get($m, 'shipping.postcode')
                        ?? data_get($m, 'postcode')
                        ?? data_get($m, 'patient.postcode')
                        ?? ($user->shipping_postcode ?? null)
                        ?? ($user->postcode ?? null);

                    $addressLines = array_values(array_filter([
                        $address1 ? trim((string) $address1) : null,
                        $address2 ? trim((string) $address2) : null,
                        trim(trim((string) $city) . ' ' . trim((string) $postcode)) ?: null,
                    ], fn ($value) => is_string($value) && trim($value) !== ''));

                    $line1 = mb_strtoupper(trim((string) $line1));

                    $addressText = mb_strtoupper(
                        'Address: ' . (! empty($addressLines) ? implode(' ', $addressLines) : 'Address not available')
                    );

                    // Same label size and font family as ZplLabelBuilder::forOrder.
                    // Only prints name and address. No warning, no QR, no pharmacy details.
                    $zpl = "^XA
                    ^CI28
                    ^PW609
                    ^LL288
                    ^LH0,0

                    ^CF0,22
                    ^FO32,24^FB560,2,0,C,10^FD{$line1}^FS

                    ^CF0,20
                    ^FO32,80^FB560,3,0,C,20^FD{$addressText}^FS

                    ^XZ";

                    logger()->info('print_patient_label dispatch', [
                        'order_id'  => $o->id,
                        'reference' => $o->reference,
                        'len'       => strlen($zpl),
                    ]);

                    $this->dispatch('print-zpl', zpl: $zpl);

                    \Filament\Notifications\Notification::make()
                        ->title('Patient label sent to browser')
                        ->success()
                        ->send();
                }),

            ActionGroup::make([
                Action::make('addSixMonthReview')
                    ->label('Add 6-month review')
                    ->icon('heroicon-o-calendar-days')
                    ->color('warning')
                    ->modalHeading('Add 6-month review')
                    ->modalSubmitActionLabel('Save review')
                    ->form([
                        ToggleButtons::make('height_unit')
                            ->label('Height units')
                            ->options([
                                'metric' => 'Metric',
                                'imperial' => 'Imperial',
                            ])
                            ->default('metric')
                            ->inline()
                            ->live(),

                        TextInput::make('height_cm')
                            ->label('Height (cm)')
                            ->numeric()
                            ->step('0.1')
                            ->visible(fn (Get $get) => $get('height_unit') === 'metric'),

                        TextInput::make('height_ft')
                            ->label('Height (ft)')
                            ->numeric()
                            ->step('1')
                            ->visible(fn (Get $get) => $get('height_unit') === 'imperial'),

                        TextInput::make('height_in')
                            ->label('Height (in)')
                            ->numeric()
                            ->step('0.1')
                            ->visible(fn (Get $get) => $get('height_unit') === 'imperial'),

                        ToggleButtons::make('weight_unit')
                            ->label('Weight units')
                            ->options([
                                'metric' => 'Metric',
                                'imperial' => 'Imperial',
                            ])
                            ->default('metric')
                            ->inline()
                            ->live(),

                        TextInput::make('weight_kg')
                            ->label('Weight (kg)')
                            ->numeric()
                            ->step('0.1')
                            ->visible(fn (Get $get) => $get('weight_unit') === 'metric'),

                        TextInput::make('weight_st')
                            ->label('Weight (st)')
                            ->numeric()
                            ->step('0.1')
                            ->visible(fn (Get $get) => $get('weight_unit') === 'imperial'),

                        TextInput::make('weight_lb')
                            ->label('Weight (lb)')
                            ->numeric()
                            ->step('0.1')
                            ->visible(fn (Get $get) => $get('weight_unit') === 'imperial'),

                        Textarea::make('review_text')
                            ->label('Review note')
                            ->rows(5)
                            ->placeholder('Optional note for this 6-month review.'),
                    ])
                    ->action(function (array $data) {
                        $record = $this->record;

                        if (! $record) {
                            return;
                        }

                        $text = trim((string) ($data['review_text'] ?? ''));

                        $heightUnit = $data['height_unit'] ?? 'metric';
                        $weightUnit = $data['weight_unit'] ?? 'metric';

                        $heightCm = null;
                        $weightKg = null;

                        if ($heightUnit === 'metric') {
                            $heightCm = (float) ($data['height_cm'] ?? 0);
                        } else {
                            $ft = (float) ($data['height_ft'] ?? 0);
                            $in = (float) ($data['height_in'] ?? 0);
                            $heightCm = (($ft * 12) + $in) * 2.54;
                        }

                        if ($weightUnit === 'metric') {
                            $weightKg = (float) ($data['weight_kg'] ?? 0);
                        } else {
                            $st = (float) ($data['weight_st'] ?? 0);
                            $lb = (float) ($data['weight_lb'] ?? 0);
                            $weightKg = (($st * 14) + $lb) * 0.45359237;
                        }

                        $bmi = null;

                        if ($heightCm > 0 && $weightKg > 0) {
                            $heightM = $heightCm / 100;
                            $bmi = round($weightKg / ($heightM * $heightM), 1);
                        }

                        if ($text === '' && ! ($heightCm > 0) && ! ($weightKg > 0)) {
                            Notification::make()
                                ->danger()
                                ->title('Add height, weight or a note')
                                ->send();

                            return;
                        }

                        $meta = is_array($record->meta)
                            ? $record->meta
                            : (json_decode($record->meta ?? '[]', true) ?: []);

                        $reviews = data_get($meta, 'six_month_reviews', []);

                        if (! is_array($reviews)) {
                            $reviews = [];
                        }

                        $user = auth()->user();

                        $reviews[] = [
                            'date' => now()->toIso8601String(),

                            'height_unit' => $heightUnit,
                            'height_cm' => $heightCm ?: null,
                            'height_ft' => $data['height_ft'] ?? null,
                            'height_in' => $data['height_in'] ?? null,

                            'weight_unit' => $weightUnit,
                            'weight_kg' => $weightKg ?: null,
                            'weight_st' => $data['weight_st'] ?? null,
                            'weight_lb' => $data['weight_lb'] ?? null,

                            'bmi' => $bmi,

                            'text' => $text,
                            'by' => $user?->name ?: trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')),
                            'user_id' => auth()->id(),
                        ];

                        data_set($meta, 'six_month_reviews', $reviews);

                        $record->meta = $meta;
                        $record->save();

                        Notification::make()
                            ->success()
                            ->title('6-month review saved')
                            ->send();
                    }),

                Action::make('deleteSixMonthReview')
                    ->label('Delete 6-month review')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('Delete 6-month review')
                    ->modalSubmitActionLabel('Delete selected review')
                    ->requiresConfirmation()
                    ->form([
                        Select::make('review_entry')
                            ->label('Select review')
                            ->options(fn (): array => static::sixMonthReviewRowsForRecord($this->record)
                                ->mapWithKeys(function (array $row) {
                                    $details = trim(implode(' ', array_filter([
                                        (string) ($row['measurements'] ?? ''),
                                        (string) ($row['text'] ?? ''),
                                    ], fn ($value) => trim((string) $value) !== '')));

                                    $label = '[' . $row['date'] . '] ' . ($details !== '' ? $details : '6-month review');

                                    return [$row['id'] => mb_strlen($label) > 140 ? mb_substr($label, 0, 140) . '…' : $label];
                                })
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->visible(fn (): bool => static::sixMonthReviewRowsForRecord($this->record)->isNotEmpty())
                    ->action(function (array $data): void {
                        $entryId = is_string($data['review_entry'] ?? null) ? trim($data['review_entry']) : '';

                        if ($entryId === '') {
                            Notification::make()
                                ->danger()
                                ->title('Select a review')
                                ->send();

                            return;
                        }

                        if (! static::deleteSixMonthReviewForCompleted($this->record, $entryId)) {
                            Notification::make()
                                ->danger()
                                ->title('Could not delete review')
                                ->body('The selected review could not be found.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('6-month review deleted')
                            ->send();
                    }),
            ])
                ->label('6-month review')
                ->icon('heroicon-o-calendar-days')
                ->color('warning')
                ->button(),
                
            ActionGroup::make([
                Action::make('pdf_full')
                    ->label('Full Consultation Record')
                    ->icon('heroicon-o-document-text')
                    ->url(function () use ($sessionId) {
                        if (!$sessionId) return null;
                        return Route::has('admin.consultations.pdf.full')
                            ? route('admin.consultations.pdf.full', ['session' => $sessionId])
                            : url("/admin/consultations/{$sessionId}/pdf/full");
                    })
                    ->openUrlInNewTab(),
                Action::make('pdf_pre')
                    ->label('Private Prescription')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->url(function () use ($sessionId) {
                        if (!$sessionId) return null;
                        return Route::has('admin.consultations.pdf.pre')
                            ? route('admin.consultations.pdf.pre', ['session' => $sessionId])
                            : url("/admin/consultations/{$sessionId}/pdf/private-prescription");
                    })
                    ->openUrlInNewTab(),
                Action::make('pdf_pre_patient')
                    ->label('Private Prescription Patient')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->url(function () use ($sessionId) {
                        if (!$sessionId) return null;
                        // Try named route first then fallback to controller url
                        $name = 'admin.consultations.pdf.pre.patient';
                        if (\Illuminate\Support\Facades\Route::has($name)) {
                            return route($name, ['session' => $sessionId]);
                        }
                        return url("/admin/consultations/{$sessionId}/pdf/private-prescription-patient");
                    })
                    ->openUrlInNewTab(),
                Action::make('pdf_notification')
                    ->label('Notification of Treatment Issued')
                    ->icon('heroicon-o-clipboard-document')
                    ->url(function () use ($sessionId) {
                        if (!$sessionId) return null;
                        $name = 'admin.consultations.pdf.notification';
                        if (\Illuminate\Support\Facades\Route::has($name)) {
                            return route($name, ['session' => $sessionId]);
                        }
                        return url("/admin/consultations/{$sessionId}/pdf/notification-of-treatment-issued");
                    })
                    ->openUrlInNewTab(),
                Action::make('pdf_ros')
                    ->label('Record of Supply')
                    ->icon('heroicon-o-clipboard-document')
                    ->url(function () use ($sessionId) {
                        if (!$sessionId) return null;
                        return Route::has('admin.consultations.pdf.ros')
                            ? route('admin.consultations.pdf.ros', ['session' => $sessionId])
                            : url("/admin/consultations/{$sessionId}/pdf/record-of-supply");
                    })
                    ->openUrlInNewTab(),
                Action::make('pdf_invoice')
                    ->label('Invoice')
                    ->icon('heroicon-o-receipt-refund')
                    ->url(function () use ($sessionId) {
                        if (!$sessionId) return null;
                        return Route::has('admin.consultations.pdf.invoice')
                            ? route('admin.consultations.pdf.invoice', ['session' => $sessionId])
                            : url("/admin/consultations/{$sessionId}/pdf/invoice");
                    })
                    ->openUrlInNewTab(),
            ])
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->button()
                ->visible($hasSession),

            ActionGroup::make([
                Action::make('email_full')
                    ->label('Full Consultation Record')
                    ->icon('heroicon-o-document-text')
                    ->action(function () use ($sessionId) {
                        if (!$sessionId) { Notification::make()->danger()->title('No consultation session.')->send(); return; }
                        $this->sendPdfEmail('full', $sessionId);
                    }),
                Action::make('email_pre')
                    ->label('Private Prescription')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->action(function () use ($sessionId) {
                        if (!$sessionId) { Notification::make()->danger()->title('No consultation session.')->send(); return; }
                        $this->sendPdfEmail('pre', $sessionId);
                    }),
                Action::make('email_pre_patient')
                    ->label('Private Prescription Patient')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->action(function () use ($sessionId) {
                        if (!$sessionId) { Notification::make()->danger()->title('No consultation session.')->send(); return; }
                        $this->sendPdfEmail('pre_patient', $sessionId);
                    }),
                Action::make('email_notification')
                    ->label('Notification of Treatment Issued')
                    ->icon('heroicon-o-clipboard-document')
                    ->action(function () use ($sessionId) {
                        if (!$sessionId) { Notification::make()->danger()->title('No consultation session.')->send(); return; }
                        $this->sendPdfEmail('notification', $sessionId);
                    }),
                Action::make('email_ros')
                    ->label('Record of Supply')
                    ->icon('heroicon-o-clipboard-document')
                    ->action(function () use ($sessionId) {
                        if (!$sessionId) { Notification::make()->danger()->title('No consultation session.')->send(); return; }
                        $this->sendPdfEmail('ros', $sessionId);
                    }),
                Action::make('email_invoice')
                    ->label('Invoice')
                    ->icon('heroicon-o-receipt-refund')
                    ->action(function () use ($sessionId) {
                        if (!$sessionId) { Notification::make()->danger()->title('No consultation session.')->send(); return; }
                        $this->sendPdfEmail('invoice', $sessionId);
                    }),
            ])
                ->label('Email PDF')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->button()
                ->visible($hasSession),

            Action::make('email_people')
                ->label('Email People')
                ->icon('heroicon-o-envelope')
                ->color('info')
                ->modalHeading('Send Email')
                ->form([
                    TextInput::make('to')
                        ->label('To')
                        ->placeholder('name@example.com, other@example.com')
                        ->helperText('Separate multiple emails with commas or new lines')
                        ->required(),
                    TextInput::make('cc')
                        ->label('CC')
                        ->placeholder('optional'),
                    TextInput::make('bcc')
                        ->label('BCC')
                        ->placeholder('optional'),
                    TextInput::make('subject')
                        ->label('Subject')
                        ->required(),
                    FileUpload::make('attachments')
                        ->label('Attachments')
                        ->helperText('Optional. Up to 5 files max 5 MB each')
                        ->multiple()
                        ->maxFiles(5)
                        ->maxSize(5120)
                        ->downloadable()
                        ->openable()
                        ->storeFiles(false),
                    Textarea::make('message')
                        ->label('Message')
                        ->rows(10)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $parseList = function ($input) {
                        $s = (string) ($input ?? '');
                        if ($s === '') return [];
                        $parts = preg_split('/[,\n;]/', $s);
                        $parts = array_map('trim', $parts);
                        $parts = array_filter($parts, fn ($e) => $e !== '');
                        // keep only valid email addresses
                        $parts = array_values(array_filter($parts, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
                        // de-duplicate
                        return array_values(array_unique($parts));
                    };

                    $to   = $parseList($data['to'] ?? '');
                    $cc   = $parseList($data['cc'] ?? '');
                    $bcc  = $parseList($data['bcc'] ?? '');
                    $subj = trim((string) ($data['subject'] ?? ''));
                    $body = (string) ($data['message'] ?? '');
                    $files = is_array($data['attachments'] ?? null) ? $data['attachments'] : [];

                    if (empty($to)) {
                        Notification::make()->danger()->title('No valid recipient email addresses')->send();
                        return;
                    }
                    if ($subj === '') {
                        Notification::make()->danger()->title('Subject is required')->send();
                        return;
                    }
                    if ($body === '') {
                        Notification::make()->danger()->title('Message is required')->send();
                        return;
                    }

                    try {
                        $fromAddress = config('mail.from.address') ?: 'info@safescript.co.uk';
                        $fromName    = config('mail.from.name') ?: 'Safescript Pharmacy';

                        \Illuminate\Support\Facades\Mail::send([], [], function ($m) use ($to, $cc, $bcc, $subj, $body, $fromAddress, $fromName, $files) {
                            $m->from($fromAddress, $fromName)
                              ->subject($subj);
                            
                            // Symfony Mailer expects an AbstractPart. Use html() or text() instead of setBody()
                            $hasHtml = is_string($body) && ($body !== strip_tags($body) || str_contains($body, '<') || str_contains($body, '>'));
                            if ($hasHtml) {
                                $m->html($body);
                            } else {
                                $m->text($body);
                            }

                            // add recipients
                            foreach ($to as $addr) { $m->to($addr); }
                            if (!empty($cc))  { $m->cc($cc); }
                            if (!empty($bcc)) { $m->bcc($bcc); }

                            if (!empty($files)) {
                                foreach ($files as $file) {
                                    try {
                                        // Support both TemporaryUploadedFile and Symfony UploadedFile
                                        $path = method_exists($file, 'getRealPath') ? $file->getRealPath() : (string) ($file->path() ?? '');
                                        $name = method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : basename((string) ($file->getFilename() ?? 'attachment'));
                                        $mime = method_exists($file, 'getMimeType') ? ($file->getMimeType() ?: 'application/octet-stream') : 'application/octet-stream';
                                        if ($path && is_readable($path)) {
                                            $m->attach($path, ['as' => $name, 'mime' => $mime]);
                                        } elseif (method_exists($file, 'getContent')) {
                                            $content = $file->getContent();
                                            if (is_string($content) && $content !== '') {
                                                $m->attachData($content, $name, ['mime' => $mime]);
                                            }
                                        }
                                    } catch (\Throwable $e) {
                                        // continue attaching others
                                    }
                                }
                            }
                        });

                        Notification::make()->success()->title('Email sent successfully')->send();
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->danger()
                            ->title('Could not send email')
                            ->body(substr($e->getMessage(), 0, 200))
                            ->send();
                    }
                })
                ->button(),
            Action::make('add_consultation_note')
                ->label('Add consultation note')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->modalHeading('Add consultation note')
                ->form([
                    Textarea::make('note')
                        ->label('Consultation note')
                        ->rows(8)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $note = trim((string) ($data['note'] ?? ''));
                    if ($note === '') {
                        Notification::make()->danger()->title('Note is required')->send();
                        return;
                    }

                    $rec = $this->record;
                    $meta = is_array($rec->meta) ? $rec->meta : (json_decode($rec->meta ?? '[]', true) ?: []);
                    if (!is_array($meta)) $meta = [];

                    $normalise = function ($v): array {
                        $arr = is_array($v) ? $v : ($v == null ? [] : [$v]);
                        $out = [];

                        foreach ($arr as $x) {
                            if (is_array($x)) {
                                $n = trim((string) (data_get($x, 'note') ?? data_get($x, 'text') ?? data_get($x, 'message') ?? ''));
                                $at = data_get($x, 'at') ?? data_get($x, 'created_at') ?? data_get($x, 'createdAt') ?? data_get($x, 'ts') ?? null;
                                if ($n !== '') {
                                    $out[] = [
                                        'note' => $n,
                                        'at'   => $at ? (string) $at : null,
                                    ];
                                }
                                continue;
                            }

                            $n = is_string($x) ? trim($x) : trim((string) $x);
                            if ($n !== '') {
                                $out[] = [
                                    'note' => $n,
                                    'at'   => null,
                                ];
                            }
                        }

                        return $out;
                    };

                    $existing = $normalise(
                        $rec->consultation_notes
                        ?? $rec->consultant_notes
                        ?? data_get($meta, 'consultation_notes')
                        ?? data_get($meta, 'consultant_notes')
                        ?? []
                    );

                    $existing[] = [
                        'note' => $note,
                        'at'   => now('UTC')->toIso8601String(),
                    ];

                    // De-dupe by note + timestamp
                    $seen = [];
                    $deduped = [];
                    foreach ($existing as $row) {
                        $n = trim((string) data_get($row, 'note'));
                        $a = (string) (data_get($row, 'at') ?? '');
                        if ($n === '') continue;

                        $k = $n . '|' . $a;
                        if (isset($seen[$k])) continue;

                        $seen[$k] = true;
                        $deduped[] = [
                            'note' => $n,
                            'at'   => $a !== '' ? $a : null,
                        ];
                    }

                    $existing = $deduped;

                    $meta['consultation_notes'] = $existing;
                    $meta['consultant_notes'] = $existing;

                    $rec->meta = $meta;

                    // Optional if columns exist
                    if (isset($rec->consultation_notes)) {
                        $rec->consultation_notes = json_encode($existing);
                    }
                    if (isset($rec->consultant_notes)) {
                        $rec->consultant_notes = json_encode($existing);
                    }

                    $rec->save();

                    // Also store a copy on the user so notes can be seen per patient
                    try {
                        $u = $rec->user;
                        if ($u) {
                            $normaliseUser = function ($v): array {
                                // DB column may be json-cast, array, json string, or null
                                if (is_string($v)) {
                                    $trim = trim($v);
                                    if ($trim === '') return [];
                                    $decoded = json_decode($trim, true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                        $v = $decoded;
                                    } else {
                                        $v = [$trim];
                                    }
                                }

                                $arr = is_array($v) ? $v : ($v == null ? [] : [$v]);
                                $out = [];

                                foreach ($arr as $x) {
                                    if (is_array($x)) {
                                        $n = trim((string) (data_get($x, 'note') ?? data_get($x, 'text') ?? data_get($x, 'message') ?? ''));
                                        $at = data_get($x, 'at') ?? data_get($x, 'created_at') ?? data_get($x, 'createdAt') ?? data_get($x, 'ts') ?? null;
                                        $ref = data_get($x, 'order_reference') ?? data_get($x, 'reference') ?? null;
                                        if ($n !== '') {
                                            $out[] = [
                                                'note' => $n,
                                                'at' => $at ? (string) $at : null,
                                                'order_reference' => $ref ? (string) $ref : null,
                                            ];
                                        }
                                        continue;
                                    }

                                    $n = is_string($x) ? trim($x) : trim((string) $x);
                                    if ($n !== '') {
                                        $out[] = [
                                            'note' => $n,
                                            'at' => null,
                                            'order_reference' => null,
                                        ];
                                    }
                                }

                                return $out;
                            };

                            $uExisting = $normaliseUser($u->consultation_notes ?? null);

                            $uExisting[] = [
                                'note' => $note,
                                'at' => now('UTC')->toIso8601String(),
                                'order_reference' => (string) ($rec->reference ?? ''),
                            ];

                            // De-dupe by note + at + reference
                            $seenU = [];
                            $dedupedU = [];
                            foreach ($uExisting as $row) {
                                $n = trim((string) data_get($row, 'note'));
                                $a = (string) (data_get($row, 'at') ?? '');
                                $r = (string) (data_get($row, 'order_reference') ?? '');
                                if ($n === '') continue;

                                $k = $n . '|' . $a . '|' . $r;
                                if (isset($seenU[$k])) continue;

                                $seenU[$k] = true;
                                $dedupedU[] = [
                                    'note' => $n,
                                    'at' => $a !== '' ? $a : null,
                                    'order_reference' => $r !== '' ? $r : null,
                                ];
                            }

                            // Users table now has a real column, store JSON
                            $u->consultation_notes = json_encode($dedupedU);
                            $u->save();
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    Notification::make()->success()->title('Consultation note added')->send();
                })
                ->button(),

            Action::make('delete_consultation_note')
            ->label('Delete consultation note')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->modalHeading('Delete consultation note')
            ->form([
                Select::make('note_key')
                    ->label('Select note')
                    ->options(function () {
                        $rec = $this->record;
                        $meta = is_array($rec->meta) ? $rec->meta : (json_decode($rec->meta ?? '[]', true) ?: []);
                        if (!is_array($meta)) $meta = [];

                        $normalise = function ($v): array {
                            $arr = is_array($v) ? $v : ($v == null ? [] : [$v]);
                            $out = [];

                            foreach ($arr as $x) {
                                if (is_array($x)) {
                                    $n = trim((string) (data_get($x, 'note') ?? data_get($x, 'text') ?? data_get($x, 'message') ?? ''));
                                    $at = data_get($x, 'at') ?? data_get($x, 'created_at') ?? data_get($x, 'createdAt') ?? data_get($x, 'ts') ?? null;
                                    if ($n !== '') {
                                        $out[] = ['note' => $n, 'at' => $at ? (string) $at : null];
                                    }
                                    continue;
                                }

                                $n = is_string($x) ? trim($x) : trim((string) $x);
                                if ($n !== '') $out[] = ['note' => $n, 'at' => null];
                            }

                            return $out;
                        };

                        $existing = $normalise(
                            $rec->consultation_notes
                            ?? $rec->consultant_notes
                            ?? data_get($meta, 'consultation_notes')
                            ?? data_get($meta, 'consultant_notes')
                            ?? []
                        );

                        $opts = [];
                        foreach ($existing as $row) {
                            $n = trim((string) data_get($row, 'note'));
                            $a = (string) (data_get($row, 'at') ?? '');
                            if ($n === '') continue;

                            $key = base64_encode($n . "\n" . $a);

                            $labelNote = mb_strlen($n) > 80 ? (mb_substr($n, 0, 80) . '…') : $n;
                            $labelAt = '';
                            if ($a !== '') {
                                try {
                                    $labelAt = Carbon::parse($a)->timezone('Europe/London')->format('d-m-Y H:i');
                                } catch (Throwable $e) {
                                    $labelAt = $a;
                                }
                            }

                            $opts[$key] = $labelAt !== '' ? ($labelAt . '  ' . $labelNote) : $labelNote;
                        }

                        return $opts;
                    })
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data) {
                $key = (string) ($data['note_key'] ?? '');
                if ($key === '') {
                    Notification::make()->danger()->title('Select a note')->send();
                    return;
                }

                $decoded = base64_decode($key, true);
                if ($decoded === false) $decoded = '';
                [$noteText, $noteAt] = array_pad(explode("\n", $decoded, 2), 2, '');
                $noteText = trim((string) $noteText);
                $noteAt = trim((string) $noteAt);

                if ($noteText === '') {
                    Notification::make()->danger()->title('Invalid note selection')->send();
                    return;
                }

                $rec = $this->record;
                $meta = is_array($rec->meta) ? $rec->meta : (json_decode($rec->meta ?? '[]', true) ?: []);
                if (!is_array($meta)) $meta = [];

                $normalise = function ($v): array {
                    $arr = is_array($v) ? $v : ($v == null ? [] : [$v]);
                    $out = [];

                    foreach ($arr as $x) {
                        if (is_array($x)) {
                            $n = trim((string) (data_get($x, 'note') ?? data_get($x, 'text') ?? data_get($x, 'message') ?? ''));
                            $at = data_get($x, 'at') ?? data_get($x, 'created_at') ?? data_get($x, 'createdAt') ?? data_get($x, 'ts') ?? null;
                            if ($n !== '') $out[] = ['note' => $n, 'at' => $at ? (string) $at : null];
                            continue;
                        }

                        $n = is_string($x) ? trim($x) : trim((string) $x);
                        if ($n !== '') $out[] = ['note' => $n, 'at' => null];
                    }

                    return $out;
                };

                $existing = $normalise(
                    $rec->consultation_notes
                    ?? $rec->consultant_notes
                    ?? data_get($meta, 'consultation_notes')
                    ?? data_get($meta, 'consultant_notes')
                    ?? []
                );

                $filtered = [];
                $removed = false;

                foreach ($existing as $row) {
                    $n = trim((string) data_get($row, 'note'));
                    $a = trim((string) (data_get($row, 'at') ?? ''));

                    if (!$removed && $n === $noteText && ($noteAt === '' || $a === $noteAt)) {
                        $removed = true;
                        continue;
                    }

                    if ($n !== '') $filtered[] = ['note' => $n, 'at' => $a !== '' ? $a : null];
                }

                $meta['consultation_notes'] = $filtered;
                $meta['consultant_notes'] = $filtered;

                $rec->meta = $meta;

                if (isset($rec->consultation_notes)) $rec->consultation_notes = json_encode($filtered);
                if (isset($rec->consultant_notes)) $rec->consultant_notes = json_encode($filtered);

                $rec->save();

                // Also delete from user consultation_notes column if present
                try {
                    $u = $rec->user;
                    if ($u) {
                        $normaliseUser = function ($v): array {
                            if (is_string($v)) {
                                $trim = trim($v);
                                if ($trim === '') return [];
                                $decoded = json_decode($trim, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $v = $decoded;
                                } else {
                                    $v = [$trim];
                                }
                            }

                            $arr = is_array($v) ? $v : ($v == null ? [] : [$v]);
                            $out = [];

                            foreach ($arr as $x) {
                                if (is_array($x)) {
                                    $n = trim((string) (data_get($x, 'note') ?? data_get($x, 'text') ?? data_get($x, 'message') ?? ''));
                                    $at = data_get($x, 'at') ?? data_get($x, 'created_at') ?? data_get($x, 'createdAt') ?? data_get($x, 'ts') ?? null;
                                    $ref = data_get($x, 'order_reference') ?? data_get($x, 'reference') ?? null;
                                    if ($n !== '') {
                                        $out[] = [
                                            'note' => $n,
                                            'at' => $at ? (string) $at : null,
                                            'order_reference' => $ref ? (string) $ref : null,
                                        ];
                                    }
                                    continue;
                                }

                                $n = is_string($x) ? trim($x) : trim((string) $x);
                                if ($n !== '') {
                                    $out[] = [
                                        'note' => $n,
                                        'at' => null,
                                        'order_reference' => null,
                                    ];
                                }
                            }

                            return $out;
                        };

                        $uExisting = $normaliseUser($u->consultation_notes ?? null);

                        $uFiltered = [];
                        $uRemoved = false;
                        foreach ($uExisting as $row) {
                            $n = trim((string) data_get($row, 'note'));
                            $a = trim((string) (data_get($row, 'at') ?? ''));
                            $r = trim((string) (data_get($row, 'order_reference') ?? ''));

                            $refOk = ($r === '' || $r === (string) ($rec->reference ?? ''));
                            if (!$uRemoved && $n === $noteText && ($noteAt === '' || $a === $noteAt) && $refOk) {
                                $uRemoved = true;
                                continue;
                            }

                            if ($n !== '') {
                                $uFiltered[] = [
                                    'note' => $n,
                                    'at' => $a !== '' ? $a : null,
                                    'order_reference' => $r !== '' ? $r : null,
                                ];
                            }
                        }

                        $u->consultation_notes = json_encode($uFiltered);
                        $u->save();
                    }
                } catch (\Throwable $e) {
                    // ignore
                }

                Notification::make()
                    ->success()
                    ->title($removed ? 'Consultation note deleted' : 'No matching note found')
                    ->send();
            })
            ->button(),

        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $rec  = $this->record;
        $meta = is_array($rec->meta) ? $rec->meta : (json_decode($rec->meta ?? '[]', true) ?: []);
        $sessionId = data_get($meta, 'consultation_session_id');

        // Submitted forms collapsed so only latest per type is shown
        $forms = [];
        $formQuickActions = [];
        if ($sessionId) {
            // Helper to resolve correct URL for form actions (plural or singular)
            $formUrl = function (int|string $sessionId, int|string $formId, string $action = 'view'): string {
                $action = in_array($action, ['view', 'edit', 'history'], true) ? $action : 'view';
                $candidates = [
                    'admin.consultations.forms.' . $action,
                    'admin.consultations.form.' . $action,
                    'consultations.forms.' . $action,
                    'consultations.form.' . $action,
                ];
                foreach ($candidates as $name) {
                    if (Route::has($name)) {
                        try {
                            return route($name, ['session' => $sessionId, 'form' => $formId]);
                        } catch (Throwable $e) {}
                    }
                }
                return "/admin/consultations/{$sessionId}/forms/{$formId}/{$action}";
            };
            $rows = ConsultationFormResponse::query()
                ->where('consultation_session_id', $sessionId)
                ->orderByDesc('id')
                ->get();

            $canonical = function (ConsultationFormResponse $f): ?string {
                $t = strtolower((string) ($f->form_type ?? ''));
                $title = strtolower((string) ($f->title ?? ''));

                // normalise separators
                $t = str_replace(['-', ' '], '_', $t);
                $title = str_replace(['-', '  '], ['_', ' '], $title);

                // Clinical Notes is Record of Supply
                if (str_contains($t, 'clinical_notes') || str_contains($title, 'clinical notes')) {
                    return 'record_of_supply';
                }

                // Record of Supply explicit matches
                if ($t === 'ros' || $t === 'record_of_supply' || str_contains($title, 'record of supply')) {
                    return 'record_of_supply';
                }

                // Risk Assessment including RAF
                if ($t === 'raf' || $t === 'risk_assessment' || str_contains($title, 'risk assessment')) {
                    return 'risk_assessment';
                }

                // Pharmacist Advice variants
                if (
                    str_contains($t, 'pharmacist_advice')
                    || str_contains($t, 'advice')
                    || str_contains($t, 'consultation')
                    || str_contains($title, 'advice')
                    || str_contains($title, 'consultation')
                ) {
                    return 'pharmacist_advice';
                }

                // Pharmacist Declaration
                if (str_contains($t, 'pharmacist_declaration') || str_contains($title, 'declaration')) {
                    return 'pharmacist_declaration';
                }

                // Reorder
                if ($t === 'reorder' || $t === 're_order') {
                    return 'reorder';
                }

                // Ignore other assessments that are not the above
                if (str_contains($t, 'assessment') || str_contains($title, 'assessment')) {
                    return null;
                }

                return null;
            };

            $latest = [];
            foreach ($rows as $r) {
                $key = $canonical($r);
                if ($key === null) continue;
                // Always let the most recent matching form win so Record of Supply doesn't point at Risk Assessment
                $latest[$key] = $r;
            }


            $labelOf = [
                'risk_assessment'        => ['Risk Assessment', 1],
                'reorder'                => ['Reorder', 1],
                'pharmacist_advice'      => ['Pharmacist Advice', 10],
                'pharmacist_declaration' => ['Pharmacist Declaration', 20],
                'record_of_supply'       => ['Record of Supply', 30],
            ];

            $forms = collect($latest)
                ->map(function ($f, $key) use ($labelOf) {
                    [$label, $ord] = $labelOf[$key] ?? [ucwords(str_replace('_', ' ', $key)), 99];

                    $metaArr = is_array($f->meta)
                        ? $f->meta
                        : (json_decode($f->meta ?? '[]', true) ?: []);

                    $item = data_get($metaArr, 'product_name')
                        ?? data_get($metaArr, 'treatment')
                        ?? data_get($metaArr, 'item.name')
                        ?? data_get($metaArr, 'selectedProduct.name')
                        ?? '—';

                    return [
                        'id'      => $f->id,
                        'title'   => $label,
                        'type'    => $key,
                        'item'    => $item,
                        'created' => optional($f->created_at)->format('d-m-Y H:i'),
                        'order'   => $ord,
                    ];
                })
                ->sortBy(fn ($x) => [$x['order'], -$x['id']])
                ->values()
                ->toArray();

            $formQuickActions = $forms;
        }

        $items = Arr::wrap(
            data_get($meta, 'items')
            ?? data_get($meta, 'products')
            ?? data_get($meta, 'lines')
            ?? data_get($meta, 'line_items')
            ?? data_get($meta, 'order.items')
            ?? data_get($meta, 'order.line_items')
            ?? data_get($meta, 'cart.items')
            ?? $rec->products
            ?? $rec->items
            ?? $rec->lines
            ?? $rec->line_items
            ?? []
        );
        if ($items && !is_numeric(array_key_first($items)) && isset($items['name'])) $items = [$items];

        return $schema->components([
    
            // Row 1: Customer, Payment, Status
            Section::make('Order Overview')
                ->extraAttributes(['class' => 'bg-transparent shadow-none ring-0 border-0'])
                ->schema([
                    Grid::make(12)->schema([
                        Section::make('Customer')->columnSpan(8)->schema([
                            Grid::make(3)->schema([
                                TextEntry::make('first_name')->label('First Name')
                                    ->state(fn () => data_get($meta, 'firstName') ?? data_get($meta, 'first_name') ?? $rec->user?->first_name),
                                TextEntry::make('last_name')->label('Last Name')
                                    ->state(fn () => data_get($meta, 'lastName') ?? data_get($meta, 'last_name') ?? $rec->user?->last_name),
                                TextEntry::make('meta.dob')
                                    ->label('DOB')
                                    ->state(function ($record) {
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        return data_get($meta, 'dob') ?? optional($record->user)->dob;
                                    })
                                    ->formatStateUsing(function ($state) {
                                        if (empty($state)) return null;
                                        try { return Carbon::parse($state)->format('d-m-Y'); } catch (Throwable) { return (string)$state; }
                                    }),
                                TextEntry::make('email')->label('Email')
                                    ->state(fn () => data_get($meta,'email') ?? $rec->user?->email),
                                TextEntry::make('phone')->label('Phone')
                                    ->state(fn () => data_get($meta,'phone') ?? $rec->user?->phone),
                                TextEntry::make('created_at')->label('Created')->dateTime('d-m-Y H:i'),
                                TextEntry::make('home_address_block')
                                    ->label('Home address')
                                    ->state(function ($record) {
                                        $u = $record->user ?? null;
                                        if (!$u) return null;
                                        $line1 = $u->address1 ?? $u->address_1 ?? $u->address_line1 ?? null;
                                        $line2 = $u->address2 ?? $u->address_2 ?? $u->address_line2 ?? null;
                                        $city  = $u->city ?? $u->town ?? null;
                                        $pc    = $u->postcode ?? $u->post_code ?? $u->postal_code ?? $u->zip ?? $u->zip_code ?? null;
                                        $country = $u->country ?? $u->country_name ?? null;
                                        $parts = [];
                                        if (is_string($line1) && trim($line1) !== '') $parts[] = trim($line1);
                                        if (is_string($line2) && trim($line2) !== '') $parts[] = trim($line2);
                                        if (is_string($city)  && trim($city)  !== '' || is_string($pc) && trim($pc) !== '') {
                                            $cityLine = trim(trim((string)$city) . ' ' . trim((string)$pc));
                                            if ($cityLine !== '') $parts[] = $cityLine;
                                        }
                                        if (is_string($country) && trim($country) !== '') $parts[] = trim($country);
                                        return $parts ? implode("\n", $parts) : null;
                                    })
                                    ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : null)
                                    ->html(),

                                TextEntry::make('shipping_address_block')
                                    ->label('Shipping address')
                                    ->state(function ($record) {
                                        $u = $record->user ?? null;
                                        if (!$u) return null;
                                        // Prefer user shipping fields
                                        $line1 = $u->shipping_address1 ?? $u->shipping_address_1 ?? $u->shipping_line1 ?? null;
                                        $line2 = $u->shipping_address2 ?? $u->shipping_address_2 ?? $u->shipping_line2 ?? null;
                                        $city  = $u->shipping_city ?? $u->shipping_town ?? null;
                                        $pc    = $u->shipping_postcode ?? $u->shipping_post_code ?? $u->shipping_postal_code ?? $u->shipping_zip ?? $u->shipping_zip_code ?? null;
                                        $country = $u->shipping_country ?? null;

                                        // Fallback to home if shipping is incomplete
                                        if (!$line1) $line1 = $u->address1 ?? $u->address_1 ?? $u->address_line1 ?? null;
                                        if (!$line2) $line2 = $u->address2 ?? $u->address_2 ?? $u->address_line2 ?? null;
                                        if (!$city)  $city  = $u->city ?? $u->town ?? null;
                                        if (!$pc)    $pc    = $u->postcode ?? $u->post_code ?? $u->postal_code ?? $u->zip ?? $u->zip_code ?? null;
                                        if (!$country) $country = $u->country ?? $u->country_name ?? null;

                                        $parts = [];
                                        if (is_string($line1) && trim($line1) !== '') $parts[] = trim($line1);
                                        if (is_string($line2) && trim($line2) !== '') $parts[] = trim($line2);
                                        if (is_string($city)  && trim($city)  !== '' || is_string($pc) && trim($pc) !== '') {
                                            $cityLine = trim(trim((string)$city) . ' ' . trim((string)$pc));
                                            if ($cityLine !== '') $parts[] = $cityLine;
                                        }
                                        if (is_string($country) && trim($country) !== '') $parts[] = trim($country);
                                        return $parts ? implode("\n", $parts) : null;
                                    })
                                    ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : null)
                                    ->html(),
                                
                            ]),
                        ]),

                        Section::make('Payment')->columnSpan(2)->schema([
                             TextEntry::make('payment_status')
                                ->hiddenLabel()
                                ->badge()
                                ->formatStateUsing(fn ($state) => $state ? ucfirst((string) $state) : null)
                                ->color(function ($state) {
                                $s = strtolower((string) $state);
                                return match ($s) {
                                    'paid'     => 'success',
                                    'unpaid'   => 'warning',
                                    'refunded' => 'danger',
                                    default    => 'gray',
                                };
                                }),
                        ]),

                        Section::make('Status')->columnSpan(2)->schema([
                            TextEntry::make('status')->hiddenLabel()
                                ->state(fn () => ucfirst((string) $rec->status))
                                ->badge()
                                ->color('success'),
                        ]),

                        Section::make('Appointment')
                            ->columnSpan(4)
                            ->schema([
                                TextEntry::make('appointment_datetime')
                                    ->hiddenLabel()
                                    ->state(function ($record) {
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        $start = data_get($meta, 'appointment_start_at')
                                            ?? data_get($meta, 'appointment.start_at')
                                            ?? data_get($meta, 'appointment_at')
                                            ?? data_get($meta, 'booking.start_at');

                                        $end = data_get($meta, 'appointment_end_at')
                                            ?? data_get($meta, 'appointment.end_at')
                                            ?? data_get($meta, 'booking.end_at');

                                        $sd = null; $ed = null;
                                        try { if ($start) { $sd = \Carbon\Carbon::parse($start, 'UTC')->setTimezone('Europe/London'); } } catch (\Throwable $e) {}
                                        try { if ($end)   { $ed = \Carbon\Carbon::parse($end,   'UTC')->setTimezone('Europe/London'); } } catch (\Throwable $e) {}

                                        if (!$sd) return null;

                                        if ($ed) {
                                            return $sd->format('Y-m-d') === $ed->format('Y-m-d')
                                                ? $sd->format('d-m-Y H:i') . ' — ' . $ed->format('H:i')
                                                : $sd->format('d-m-Y H:i') . ' — ' . $ed->format('d-m-Y H:i');
                                        }

                                        return $sd->format('d-m-Y H:i');
                                    }),
                            ]),

                        Section::make('SCR Verified')
                            ->columnSpan(4)
                            ->visible(function ($record) {
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                $slug = data_get($meta, 'service_slug')
                                    ?? data_get($meta, 'service.slug')
                                    ?? data_get($meta, 'consultation.service_slug');
                                return is_string($slug) && strtolower($slug) === 'weight-management';
                            })
                            ->schema([
                                TextEntry::make('scr_verified')
                                    ->hiddenLabel()
                                    ->state(function ($record) {
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                                        // First preference from this order's meta
                                        $val = data_get($meta, 'scr_verified')
                                            ?? data_get($meta, 'scr_status')
                                            ?? data_get($meta, 'scrVerified');

                                        // Fallback from the patient user record so it carries forward across orders
                                        if ($val === null || $val === '') {
                                            $user = $record->user;
                                            if ($user) {
                                                $val = data_get($user, 'scr_verified')
                                                    ?? data_get($user, 'meta.scr_verified')
                                                    ?? data_get($user, 'meta.scr_status')
                                                    ?? data_get($user, 'meta.scrVerified');
                                            }
                                        }

                                        if (is_bool($val)) return $val ? 'Yes' : 'No';
                                        $s = strtolower(trim((string) $val));
                                        return match ($s) {
                                            'y', 'yes', 'true', '1' => 'Yes',
                                            'n', 'no', 'false', '0' => 'No',
                                            default => '—',
                                        };
                                    })
                                    ->badge()
                                    ->color(function ($state) {
                                        $s = strtolower((string) $state);
                                        return match ($s) {
                                            'yes' => 'success',
                                            'no'  => 'danger',
                                            default => 'gray',
                                        };
                                    }),
                            ]),
                        // --- ID Verified section for weight-management ---
                        Section::make('ID Verified')
                            ->columnSpan(4)
                            ->visible(function ($record) {
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                $slug = data_get($meta, 'service_slug')
                                    ?? data_get($meta, 'service.slug')
                                    ?? data_get($meta, 'consultation.service_slug');
                                return is_string($slug) && strtolower($slug) === 'weight-management';
                            })
                            ->schema([
                                TextEntry::make('id_verified')
                                    ->hiddenLabel()
                                    ->state(function ($record) {
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                                        // Prefer this order's meta
                                        $val = data_get($meta, 'id_verified')
                                            ?? data_get($meta, 'id_status')
                                            ?? data_get($meta, 'idVerified');

                                        // Fallback from the patient so it carries forward across orders
                                        if ($val === null || $val === '') {
                                            $user = $record->user;
                                            if ($user) {
                                                $val = data_get($user, 'id_verified')
                                                    ?? data_get($user, 'meta.id_verified')
                                                    ?? data_get($user, 'meta.id_status')
                                                    ?? data_get($user, 'meta.idVerified');
                                            }
                                        }

                                        if (is_bool($val)) return $val ? 'Yes' : 'No';
                                        $s = strtolower(trim((string) $val));
                                        return match ($s) {
                                            'y', 'yes', 'true', '1' => 'Yes',
                                            'n', 'no', 'false', '0' => 'No',
                                            default => '—',
                                        };
                                    })
                                    ->badge()
                                    ->color(function ($state) {
                                        $s = strtolower((string) $state);
                                        return match ($s) {
                                            'yes' => 'success',
                                            'no'  => 'danger',
                                            default => 'gray',
                                        };
                                    }),
                            ]),
                    ]),
                ])
                ->columnSpanFull(),

            // Row 2: Items
            Section::make('Items')
                ->extraAttributes(['class' => 'bg-transparent shadow-none ring-0 border-0'])
                ->schema([
                    RepeatableEntry::make('products')
                        ->hiddenLabel()
                        ->getStateUsing(function ($record) {
                            $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                            // Collect items from common locations
                            $items = null;
                            foreach (['items','lines','products','line_items','cart.items','order.items','order.line_items'] as $path) {
                                $arr = data_get($meta, $path);
                                if (is_array($arr) && count($arr)) { $items = $arr; break; }
                            }
                            if ((!is_array($items) || empty($items)) && is_array(data_get($meta, 'selectedProduct'))) {
                                $items = [data_get($meta, 'selectedProduct')];
                            }
                            // Wrap single associative product
                            if (is_array($items)) {
                                $isList = array_keys($items) === range(0, count($items) - 1);
                                if (!$isList && (isset($items['name']) || isset($items['title']) || isset($items['product_name']))) {
                                    $items = [$items];
                                }
                            }
                            if (!is_array($items) || empty($items)) return [];

                            // Helper to parse money to minor units (pence)
                            $parseMoneyToMinor = function ($value) {
                                if ($value === null || $value === '') return null;
                                if (is_int($value)) return $value;
                                if (is_float($value)) return (int) round($value * 100);
                                if (is_string($value)) {
                                    $s = trim($value);
                                    $s = preg_replace('/[^\d\.\,\-]/', '', $s);
                                    if (strpos($s, ',') !== false && strpos($s, '.') === false) {
                                        $s = str_replace(',', '.', $s);
                                    } else {
                                        $s = str_replace(',', '', $s);
                                    }
                                    if ($s === '' || !is_numeric($s)) return null;
                                    return (int) round(((float) $s) * 100);
                                }
                                return null;
                            };

                            $out = [];
                            foreach ($items as $it) {
                                if (is_string($it)) {
                                    $out[] = [
                                        'name'           => (string) $it,
                                        'variation'      => '',
                                        'qty'            => 1,
                                        'priceFormatted' => '—',
                                    ];
                                    continue;
                                }
                                if (!is_array($it)) continue;

                                $name = $it['name'] ?? ($it['title'] ?? 'Item');
                                $qty  = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                                if ($qty < 1) $qty = 1;

                                // Robust variation resolver (covers variations/variation/variant/optionLabel/strength/dose/options/attributes)
                                $resolveVar = function ($row) {
                                    $keys = [
                                        'variations','variation','variant','optionLabel','option','dose','strength',
                                        'meta.variations','meta.variation','meta.variant','meta.optionLabel','meta.option','meta.dose','meta.strength',
                                        'selected.variations','selected.variation','selected.variant','selected.optionLabel','selected.option','selected.dose','selected.strength',
                                        'selectedProduct.variation','selectedProduct.strength',
                                    ];
                                    foreach ($keys as $k) {
                                        $v = data_get($row, $k);
                                        if ($v !== null && $v !== '') {
                                            if (is_array($v)) {
                                                if (array_key_exists('label', $v)) return (string) $v['label'];
                                                if (array_key_exists('value', $v)) return (string) $v['value'];
                                                $flat = [];
                                                foreach ($v as $vv) {
                                                    if (is_array($vv)) {
                                                        if (isset($vv['label'])) $flat[] = (string) $vv['label'];
                                                        elseif (isset($vv['value'])) $flat[] = (string) $vv['value'];
                                                    } else {
                                                        $flat[] = (string) $vv;
                                                    }
                                                }
                                                $j = trim(implode(' ', array_filter($flat, fn($s) => $s !== '')));
                                                if ($j !== '') return $j;
                                                continue;
                                            }
                                            return (string) $v;
                                        }
                                    }
                                    if (is_array($row['options'] ?? null)) {
                                        $parts = [];
                                        foreach ($row['options'] as $op) {
                                            if (is_array($op)) $parts[] = $op['label'] ?? $op['value'] ?? null;
                                        }
                                        $j = trim(implode(' ', array_filter(array_map('strval', array_filter($parts)))));
                                        if ($j !== '') return $j;
                                    }
                                    if (is_array($row['attributes'] ?? null)) {
                                        $parts = [];
                                        foreach ($row['attributes'] as $op) {
                                            if (is_array($op)) $parts[] = $op['label'] ?? $op['value'] ?? null;
                                        }
                                        $j = trim(implode(' ', array_filter(array_map('strval', array_filter($parts)))));
                                        if ($j !== '') return $j;
                                    }
                                    // As a minor fallback, parse suffix after colon in SKU like "mounjaro:15mg"
                                    $sku = (string) ($row['sku'] ?? '');
                                    if ($sku && str_contains($sku, ':')) {
                                        $after = trim((string) substr($sku, strpos($sku, ':') + 1));
                                        if ($after !== '') return $after;
                                    }
                                    return '';
                                };
                                $variation = $resolveVar($it);

                                // Price: prefer minor-unit keys then fall back to major unit
                                $minorCandidates = [
                                    'lineTotalMinor','line_total_minor','line_total_pennies','lineTotalPennies',
                                    'totalMinor','total_minor','totalPennies','total_pennies',
                                    'amountMinor','amount_minor',
                                    'subtotalMinor','subtotal_minor',
                                    'priceMinor','price_minor','priceInMinor','priceInPence','price_in_minor','price_in_pence',
                                    'unitMinor','unit_minor','unitPriceMinor','unit_price_minor','unitPricePennies','unit_price_pennies',
                                    'minor','pennies','value_minor','valueMinor',
                                ];
                                $unitMinor = null;
                                $priceMinor = null;
                                foreach ($minorCandidates as $key) {
                                    if (array_key_exists($key, $it) && $it[$key] !== null && $it[$key] !== '') {
                                        $val = $it[$key];
                                        if (in_array($key, ['unitMinor','unit_minor','unitPriceMinor','unit_price_minor'], true)) {
                                            $unitMinor = (int) $val;
                                        } else {
                                            $priceMinor = (int) $val;
                                            break;
                                        }
                                    }
                                }
                                if ($priceMinor === null && $unitMinor !== null) {
                                    $priceMinor = (int) $unitMinor * $qty;
                                }
                                if ($priceMinor === null) {
                                    $majorCandidates = [
                                        'lineTotal','line_total','linePrice','line_price','line_total_price',
                                        'total','amount','subtotal',
                                        'price','cost',
                                        'unitPrice','unit_price','unitCost','unit_cost',
                                    ];
                                    $unitMajor = null;
                                    foreach ($majorCandidates as $key) {
                                        if (array_key_exists($key, $it) && $it[$key] !== null && $it[$key] !== '') {
                                            if (in_array($key, ['unitPrice','unit_price','unitCost','unit_cost'], true)) {
                                                $unitMajor = $parseMoneyToMinor($it[$key]);
                                            } else {
                                                $parsed = $parseMoneyToMinor($it[$key]);
                                                if ($parsed !== null) { $priceMinor = $parsed; break; }
                                            }
                                        }
                                    }
                                    if ($priceMinor === null && $unitMajor !== null) {
                                        $priceMinor = (int) $unitMajor * $qty;
                                    }
                                }
                                if ($priceMinor === null && array_key_exists('totalMinor', $it) && $it['totalMinor'] !== null && $it['totalMinor'] !== '') {
                                    $priceMinor = (int) $it['totalMinor'];
                                }

                                $displayPrice = (is_numeric($priceMinor) ? ('£' . number_format(((int) $priceMinor) / 100, 2)) : '—');

                                $out[] = [
                                    'name'           => (string) $name,
                                    'variation'      => (string) $variation,
                                    'qty'            => $qty,
                                    'priceFormatted' => $displayPrice,
                                ];
                            }
                            return $out;
                        })
                        ->schema([
                            Grid::make(12)->schema([
                                TextEntry::make('name')->label('Product')->columnSpan(6),
                                TextEntry::make('variation')->label('Variation')->formatStateUsing(fn ($state) => $state ?: '—')->columnSpan(3),
                                TextEntry::make('qty')->label('Qty')->formatStateUsing(fn ($state) => (string) $state)->columnSpan(1),
                                TextEntry::make('priceFormatted')->label('Price')->columnSpan(2)->extraAttributes(['class' => 'text-right whitespace-nowrap']),
                            ]),
                        ])
                        ->columnSpanFull(),

                    Grid::make(12)
                        ->columnSpanFull()
                        ->schema([
                            TextEntry::make('products_total_label')
                                ->hiddenLabel()
                                ->default('Total')
                                ->columnSpan(10)
                                ->extraAttributes(['class' => 'text-right font-medium']),
                            TextEntry::make('products_total_minor')
                                ->hiddenLabel()
                                ->label('')
                                ->columnSpan(2)
                                ->getStateUsing(function ($record) {
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                    $minor = data_get($meta, 'totalMinor')
                                          ?? data_get($meta, 'amountMinor')
                                          ?? data_get($meta, 'total_minor')
                                          ?? data_get($meta, 'amount_minor');
                                    if (!is_numeric($minor)) {
                                        // Sum from items as a fallback
                                        $sum = 0;
                                        $items = data_get($meta, 'items') ?? data_get($meta, 'line_items') ?? [];
                                        if (is_array($items)) {
                                            foreach ($items as $it) {
                                                $v = $it['totalMinor'] ?? $it['lineTotalMinor'] ?? $it['priceMinor'] ?? null;
                                                if (is_numeric($v)) $sum += (int) $v;
                                            }
                                        }
                                        $minor = $sum;
                                    }
                                    return (int) $minor;
                                })
                                ->formatStateUsing(fn ($state) => '£' . number_format(((int) $state) / 100, 2))
                                ->placeholder('£0.00')
                                ->extraAttributes(['class' => 'text-right tabular-nums']),
                        ]),
                ])
                ->columnSpanFull(),

            Section::make('6-month review')
                ->collapsible()
                ->collapsed(false)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('six_month_review_history')
                        ->hiddenLabel()
                        ->state(fn ($record) => static::sixMonthReviewHistoryForCompleted($record))
                        ->formatStateUsing(fn ($state) => $state && $state !== '—' ? nl2br(e($state)) : '—')
                        ->html(),
                ]),

            Section::make('Consultation notes')
    ->collapsible()
    ->collapsed(false)
    ->columnSpanFull()
    ->schema([
        RepeatableEntry::make('consultation_notes_block')
            ->hiddenLabel()
            ->state(function ($record) {
                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                $raw =
                    $record->consultation_notes
                    ?? $record->consultant_notes
                    ?? data_get($meta, 'consultation_notes')
                    ?? data_get($meta, 'consultant_notes')
                    ?? [];

                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) $raw = $decoded;
                }

                $arr = is_array($raw) ? $raw : ($raw == null ? [] : [$raw]);
                $out = [];

                foreach ($arr as $x) {
                    if (is_array($x)) {
                        $note = trim((string) (data_get($x, 'note') ?? data_get($x, 'text') ?? data_get($x, 'message') ?? ''));
                        $at   = data_get($x, 'at') ?? data_get($x, 'created_at') ?? data_get($x, 'createdAt') ?? data_get($x, 'ts') ?? null;

                        if ($note !== '') {
                            $out[] = [
                                'note' => $note,
                                'at'   => $at ? (string) $at : null,
                            ];
                        }
                        continue;
                    }

                    $note = is_string($x) ? trim($x) : trim((string) $x);
                    if ($note !== '') {
                        $out[] = [
                            'note' => $note,
                            'at'   => null,
                        ];
                    }
                }

                return $out;
            })
            ->schema([
                TextEntry::make('note')
                    ->label('Note')
                    ->formatStateUsing(fn ($state) => $state ? nl2br(e((string) $state)) : null)
                    ->html(),

                TextEntry::make('at')
                    ->label('Date and time')
                    ->visible(fn ($state) => !empty($state))
                    ->formatStateUsing(function ($state) {
                        if (!$state) return null;
                        try {
                            return Carbon::parse($state, 'UTC')->timezone('Europe/London')->format('d-m-Y H:i');
                        } catch (Throwable $e) {
                            try {
                                return Carbon::parse($state)->timezone('Europe/London')->format('d-m-Y H:i');
                            } catch (Throwable $e2) {
                                return (string) $state;
                            }
                        }
                    }),
            ]),
    ]),

            // Row 4: Admin Notes
            Section::make('Admin Notes')
                ->extraAttributes(['class' => 'bg-transparent shadow-none ring-0 border-0'])
                ->schema([
                TextEntry::make('meta.admin_notes')
                    ->hiddenLabel()
                    ->state(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $v = data_get($meta, 'admin_notes');

                        if (is_array($v)) {
                            $lines = [];

                            foreach ($v as $x) {
                                if (is_array($x)) {
                                    $x = data_get($x, 'note') ?? data_get($x, 'text') ?? data_get($x, 'message') ?? '';
                                }
                                $s = is_string($x) ? trim($x) : trim((string) $x);
                                if ($s !== '') $lines[] = $s;
                            }

                            $lines = array_values(array_unique($lines));
                            return count($lines) ? implode("\n", $lines) : '—';
                        }

                        $s = is_string($v) ? trim($v) : trim((string) ($v ?? ''));
                        return $s !== '' ? $s : '—';
                    })
                    ->formatStateUsing(fn ($state) => nl2br(e($state)))
                    ->extraAttributes(function ($record) {
                        $ts = optional($record->updated_at)->timestamp ?? time();
                        return ['wire:key' => 'approved-admin-notes-' . $record->getKey() . '-' . $ts];
                    })
                    ->html(),
            ])->columnSpanFull(),

            // Row 6: Quick Form Actions (dropdown buttons below the table)
            Section::make('Submitted Forms')
                ->extraAttributes(['class' => 'bg-transparent shadow-none ring-0 border-0'])
                ->schema([
                    // One dropdown button per form with View / Edit / History
                    ...array_map(function ($f) use ($sessionId, $formUrl) {
                        $id = $f['id'];
                        $title = $f['title'];
                        return ActionGroup::make([
                            Action::make("view_{$id}")
                                ->label('View')
                                ->icon('heroicon-o-eye')
                                ->modalHeading($title . ' – View')
                                ->modalWidth('7xl')
                                ->modalSubmitAction(false)
                                ->modalFooterActions([])      // ⬅ removes the white footer area (Cancel row)
                                ->modalHeading($title . ' — View')
                                ->closeModalByClickingAway(true)
                                ->modalContent(function () use ($sessionId, $id, $formUrl) {
                                    // Use the edit endpoint but enforce read-only in the iframe
                                    $src = $formUrl($sessionId, $id, 'edit');
                                    if (!str_contains($src, 'inline=1')) {
                                        $src .= (str_contains($src, '?') ? '&' : '?') . 'inline=1';
                                    }
                                    if (!str_contains($src, 'readonly=1')) {
                                        $src .= '&readonly=1';
                                    }
                                    $iframeId = 'inline-form-view-' . $id;

                                    return new HtmlString(
                                        '<style>
                                            .fi-modal-body { padding: 0 !important; background: #0b0b0b !important; }
                                            .fi-modal-content { background: #0b0b0b !important; box-shadow: none !important; }
                                            .fi-modal-window { background: transparent !important; }
                                            .fi-modal-footer { display: none !important; }
                                        </style>' .
                                        '<div style="background:#0b0b0b;overflow:hidden;border-radius:8px;">'
                                            . '<iframe id="' . e($iframeId) . '" src="' . e($src) . '" '
                                            . 'style="display:block;width:100%;height:75vh;border:0;background:#0b0b0b;color:#fff;" '
                                            . 'loading="eager" referrerpolicy="no-referrer"></iframe>'
                                        . '</div>'
                                        . '<script>
    (function(){
        function lockDocument(d){
            try {
                // Make backgrounds consistent, remove extra chrome
                d.documentElement.style.background = \'#0b0b0b\';
                d.body.style.background = \'#0b0b0b\';
                d.body.style.margin = \'0\';
                d.body.style.padding = \'0\';

                // Disable inputs and make text fields readonly
                d.querySelectorAll(\'input, textarea\').forEach(function(el){
                    el.setAttribute(\'readonly\', \'\');
                    el.setAttribute(\'aria-readonly\', \'true\');
                    el.style.pointerEvents = \'none\';
                });
                d.querySelectorAll(\'select, button, [type=submit], [role="button"]\').forEach(function(el){
                    el.setAttribute(\'disabled\', \'\');
                    el.setAttribute(\'aria-disabled\', \'true\');
                    el.style.pointerEvents = \'none\';
                });

                // Disable contenteditable regions
                d.querySelectorAll(\'[contenteditable]\').forEach(function(el){
                    el.setAttribute(\'contenteditable\', \'false\');
                    el.style.pointerEvents = \'none\';
                });

                // Prevent form submissions and inputs
                d.querySelectorAll(\'form\').forEach(function(f){
                    f.addEventListener(\'submit\', function(e){ e.preventDefault(); e.stopImmediatePropagation(); }, true);
                    f.addEventListener(\'change\', function(e){ e.preventDefault(); e.stopImmediatePropagation(); }, true);
                    f.addEventListener(\'input\', function(e){ e.preventDefault(); e.stopImmediatePropagation(); }, true);
                });

                // Hide Filament action bars / buttons
                d.querySelectorAll(\'.fi-form-actions, .fi-global-actions, .fi-modal-footer\').forEach(function(el){
                    el.style.display = \'none\';
                });

                // Belt & braces: block key presses and clicks
                d.addEventListener(\'keydown\', function(e){ e.preventDefault(); e.stopImmediatePropagation(); }, true);
                d.addEventListener(\'click\', function(e){
                    var t = e.target;
                    if (t && (t.matches(\'input, textarea, select, button, [role="button"]\') || t.closest(\'.fi-form-actions, .fi-global-actions\'))) {
                        e.preventDefault(); e.stopImmediatePropagation();
                    }
                }, true);
            } catch(e) {}
        }

        var i = document.getElementById(\'' . e($iframeId) . '\');
        if (i) {
            i.addEventListener(\'load\', function(){
                var d = i.contentDocument || i.contentWindow && i.contentWindow.document;
                if (!d) return;
                lockDocument(d);
                try {
                    // Re-lock if content changes dynamically
                    new MutationObserver(function(){ lockDocument(d); }).observe(d.body, { childList: true, subtree: true });
                } catch(e) {}
            });
        }
    })();
</script>'
                                    );
                                }),
                            Action::make("edit_{$id}")
                                ->label('Edit')
                                ->icon('heroicon-o-pencil-square')
                                ->modalHeading($title . ' – Edit')
                                ->modalWidth('7xl')
                                ->modalSubmitAction(false)
                                ->modalFooterActions([])      // ⬅ removes the white footer area (Cancel row)
                                ->modalHeading($title . ' — Edit')
                                ->closeModalByClickingAway(false)
                                ->modalContent(function () use ($sessionId, $id, $formUrl) {
                                    $src = $formUrl($sessionId, $id, 'edit');
                                    if (!str_contains($src, 'inline=1')) {
                                        $src .= (str_contains($src, '?') ? '&' : '?') . 'inline=1';
                                    }
                                    return new HtmlString(
                                        '<style>
                                            .fi-modal-body { padding: 0 !important; background: #0b0b0b !important; }
                                            .fi-modal-content { background: #0b0b0b !important; box-shadow: none !important; }
                                            .fi-modal-window { background: transparent !important; }
                                            .fi-modal-footer { display: none !important; }
                                        </style>' .
                                        '<div style="background:#0b0b0b;overflow:hidden;border-radius:8px;">'
                                            . '<iframe src="' . e($src) . '" '
                                            . 'style="display:block;width:100%;height:80vh;border:0;background:#0b0b0b;color:#fff;" '
                                            . 'loading="eager" referrerpolicy="no-referrer"></iframe>'
                                        . '</div>'
                                    );
                                }),
                            Action::make("history_{$id}")
                                ->label('History')  
                                ->icon('heroicon-o-clock')
                                ->modalHeading($title . ' – History')
                                ->modalWidth('5xl')
                                ->modalSubmitAction(false)
                                ->modalFooterActions([])      // ⬅ removes the white footer area (Cancel row)
                                ->modalHeading($title . ' — Submit')
                                ->closeModalByClickingAway(true)
                                ->modalContent(function () use ($sessionId, $id, $formUrl) {
                                    $src = $formUrl($sessionId, $id, 'history');
                                    if (!str_contains($src, 'inline=1')) {
                                        $src .= (str_contains($src, '?') ? '&' : '?') . 'inline=1';
                                    }
                                    return new HtmlString(
                                        '<style>
                                            .fi-modal-body { padding: 0 !important; background: #0b0b0b !important; }
                                            .fi-modal-content { background: #0b0b0b !important; box-shadow: none !important; }
                                            .fi-modal-window { background: transparent !important; }
                                            .fi-modal-footer { display: none !important; }
                                        </style>' .
                                        '<div style="background:#0b0b0b;overflow:hidden;border-radius:8px;">'
                                            . '<iframe src="' . e($src) . '" '
                                            . 'style="display:block;width:100%;height:65vh;border:0;background:#0b0b0b;color:#fff;" '
                                            . 'loading="eager" referrerpolicy="no-referrer"></iframe>'
                                        . '</div>'
                                    );
                                }),
                        ])
                            ->label($title)
                            ->icon('heroicon-o-document-text')
                            ->color('warning')
                            ->extraAttributes(['class' => 'text-white'])
                            ->button();
                    }, $forms ?? []),
                ])
                ->columnSpanFull(),
        ]);
    }


    protected function sendPdfEmail(string $which, int|string $sessionId): void
    {
        $rec  = $this->record;
        $meta = is_array($rec->meta) ? $rec->meta : (json_decode($rec->meta ?? '[]', true) ?: []);
        $email = data_get($meta, 'email') ?? optional($rec->user)->email;

        $defaultMailer = config('mail.default');
        if ($defaultMailer === 'log' || $defaultMailer === 'array') {
            Notification::make()->warning()->title('Mailer is set to log so no emails are sent')->send();
        }

        if (!$email) {
            Notification::make()->danger()->title('No patient email on record.')->send();
            return;
        }

        // Resolve label and route/path for the requested document
        $routeName = null;
        $fallbackPath = null;

        switch ($which) {
            case 'full':
                $label = 'Full Consultation Record';
                $routeName = \Illuminate\Support\Facades\Route::has('admin.consultations.pdf.full')
                    ? 'admin.consultations.pdf.full'
                    : null;
                $fallbackPath = "/admin/consultations/{$sessionId}/pdf/full";
                break;
            case 'pre':
                $label = 'Private Prescription';
                $routeName = \Illuminate\Support\Facades\Route::has('admin.consultations.pdf.pre')
                    ? 'admin.consultations.pdf.pre'
                    : null;
                $fallbackPath = "/admin/consultations/{$sessionId}/pdf/private-prescription";
                break;
            case 'pre_patient':
                $label = 'Private Prescription Patient';
                $routeName = \Illuminate\Support\Facades\Route::has('admin.consultations.pdf.pre.patient')
                    ? 'admin.consultations.pdf.pre.patient'
                    : null;
                $fallbackPath = "/admin/consultations/{$sessionId}/pdf/private-prescription-patient";
                break;
            case 'notification':
                $label = 'Notification of Treatment Issued';
                $routeName = \Illuminate\Support\Facades\Route::has('admin.consultations.pdf.notification')
                    ? 'admin.consultations.pdf.notification'
                    : null;
                $fallbackPath = "/admin/consultations/{$sessionId}/pdf/notification-of-treatment-issued";
                break;
            case 'ros':
                $label = 'Record of Supply';
                $routeName = \Illuminate\Support\Facades\Route::has('admin.consultations.pdf.ros')
                    ? 'admin.consultations.pdf.ros'
                    : null;
                $fallbackPath = "/admin/consultations/{$sessionId}/pdf/record-of-supply";
                break;
            case 'invoice':
                $label = 'Invoice';
                $routeName = \Illuminate\Support\Facades\Route::has('admin.consultations.pdf.invoice')
                    ? 'admin.consultations.pdf.invoice'
                    : null;
                $fallbackPath = "/admin/consultations/{$sessionId}/pdf/invoice";
                break;
            default:
                Notification::make()->danger()->title('Unknown document type.')->send();
                return;
        }

        // Try to generate the PDF by dispatching an internal request to the existing PDF route
        $pdfContent = null;
        try {
            if ($routeName) {
                $url = route($routeName, ['session' => $sessionId]);
            } else {
                $url = url($fallbackPath);
            }

            $request = Request::create($url, 'GET');
            $response = \Illuminate\Support\Facades\Route::dispatch($request);

            if ($response->getStatusCode() === 200) {
                $pdfContent = $response->getContent();
            }
        } catch (\Throwable $e) {
            // If anything goes wrong, we'll fall back to sending the old-style link email
            report($e);
        }

        $first = data_get($meta, 'firstName') ?? data_get($meta, 'first_name') ?? optional($rec->user)->first_name ?? '';
        $name = is_string($first) ? trim($first) : '';
        $ref  = $rec->reference ?? $rec->getKey();

        try {
            $fromAddress = config('mail.from.address') ?: 'info@safescript.co.uk';
            $fromName    = config('mail.from.name') ?: 'Safescript Pharmacy';

            if ($pdfContent !== null) {
                // Preferred path: send a clean email with the PDF attached, no admin/localhost link
                $subject = $label;
                $body = "Hello {$name}\n\n"
                    . "Here is your {$label} for order {$ref}.\n"
                    . "Your PDF is attached to this email.\n\n"
                    . "If you did not request this please contact the pharmacy";

                \Illuminate\Support\Facades\Mail::send([], [], function ($m) use ($email, $subject, $body, $pdfContent, $label, $ref, $fromAddress, $fromName) {
                    $m->from($fromAddress, $fromName)
                        ->to($email)
                        ->subject($subject)
                        ->text($body)
                        ->attachData(
                            $pdfContent,
                            $label . '-' . $ref . '.pdf',
                            ['mime' => 'application/pdf']
                        );
                });

                Notification::make()->success()->title($label . ' email with PDF attachment sent to ' . $email . '.')->send();
            } else {
                // Fallback: preserve previous behaviour and send a link if we could not generate the PDF
                $subject = $label;
                $body = "Hello {$name}\n\nHere is your {$label} for order {$ref}\nOpen the link below to view or download your PDF\n{$url}\n\nIf you did not request this please contact the pharmacy";

                \Illuminate\Support\Facades\Mail::raw($body, function ($m) use ($email, $subject, $fromAddress, $fromName) {
                    $m->from($fromAddress, $fromName)
                        ->to($email)
                        ->subject($subject);
                });

                Notification::make()->warning()->title($label . ' email sent with link only because PDF could not be generated.')->send();
            }
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Could not send email right now')->body(substr($e->getMessage(), 0, 200))->send();
            report($e);
        }
    }
}