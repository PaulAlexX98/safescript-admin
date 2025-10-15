<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\CompletedOrderResource;
use App\Models\ConsultationFormResponse;
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

class CompletedOrderDetails extends ViewRecord
{
    protected static string $resource = CompletedOrderResource::class;

    public function getTitle(): string
    {
        return 'Order ' . ($this->record->reference ?? $this->record->getKey());
    }

    protected function getHeaderActions(): array
    {
        $rec = $this->record;
        $sessionId = data_get($rec->meta ?? [], 'consultation_session_id');
        $hasSession = !empty($sessionId);

        return [
            Actions\ActionGroup::make([
                Actions\Action::make('pdf_full')
                    ->label('Full Consultation Record')
                    ->icon('heroicon-o-document-text')
                    ->url(function () use ($sessionId) {
                        if (!$sessionId) return null;
                        return Route::has('admin.consultations.pdf.full')
                            ? route('admin.consultations.pdf.full', ['session' => $sessionId])
                            : url("/admin/consultations/{$sessionId}/pdf/full");
                    })
                    ->openUrlInNewTab(),
                Actions\Action::make('pdf_pre')
                    ->label('Private Prescription')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->url(function () use ($sessionId) {
                        if (!$sessionId) return null;
                        return Route::has('admin.consultations.pdf.pre')
                            ? route('admin.consultations.pdf.pre', ['session' => $sessionId])
                            : url("/admin/consultations/{$sessionId}/pdf/private-prescription");
                    })
                    ->openUrlInNewTab(),
                Actions\Action::make('pdf_ros')
                    ->label('Record of Supply')
                    ->icon('heroicon-o-clipboard-document')
                    ->url(function () use ($sessionId) {
                        if (!$sessionId) return null;
                        return Route::has('admin.consultations.pdf.ros')
                            ? route('admin.consultations.pdf.ros', ['session' => $sessionId])
                            : url("/admin/consultations/{$sessionId}/pdf/record-of-supply");
                    })
                    ->openUrlInNewTab(),
                Actions\Action::make('pdf_invoice')
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

            Actions\Action::make('email_patient')
                ->label('Send Email')
                ->icon('heroicon-o-paper-airplane')
                ->action(function () {
                    $this->notify('success', 'Email queued to patient.');
                })
                ->visible($hasSession),

            Actions\Action::make('follow_up')
                ->label('Create Follow-up')
                ->icon('heroicon-o-plus-circle')
                ->url(fn () => $hasSession ? url("/admin/follow-ups/create?order={$this->record->getKey()}") : null)
                ->openUrlInNewTab()
                ->visible($hasSession),

            Actions\Action::make('archive')
                ->label('Archive')
                ->icon('heroicon-o-archive-box')
                ->requiresConfirmation()
                ->color('gray')
                ->action(function () {
                    $this->record->update(['archived_at' => now()]);
                    $this->notify('success', 'Order archived.');
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $rec  = $this->record;
        $meta = is_array($rec->meta) ? $rec->meta : (json_decode($rec->meta ?? '[]', true) ?: []);
        $sessionId = data_get($meta, 'consultation_session_id');

        // Submitted forms
        $forms = [];
        $formQuickActions = [];
        if ($sessionId) {
            // Helper to resolve correct URL for form actions (plural or singular)
            $formUrl = function (int|string $sessionId, int|string $formId, string $action = 'view'): string {
                $action = in_array($action, ['view', 'edit', 'history'], true) ? $action : 'view';
                $candidates = [
                    "admin.consultations.forms.$action",
                    "admin.consultations.form.$action",
                    "consultations.forms.$action",
                    "consultations.form.$action",
                ];
                foreach ($candidates as $name) {
                    if (\Illuminate\Support\Facades\Route::has($name)) {
                        try {
                            return route($name, ['session' => $sessionId, 'form' => $formId]);
                        } catch (\Throwable $e) {}
                    }
                }
                return "/admin/consultations/{$sessionId}/forms/{$formId}/{$action}";
            };
            $forms = ConsultationFormResponse::query()
                ->where('consultation_session_id', $sessionId)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($f) {
                    // Normalise a friendly consistent title and assign a display order
                    $rawType = strtolower((string) ($f->form_type ?? ''));
                    $rawTitle = strtolower((string) ($f->title ?? ''));

                    // heuristics flags
                    $metaArr = is_array($f->meta) ? $f->meta : (json_decode($f->meta ?? '[]', true) ?: []);
                    $hasPharmacist = str_contains($rawTitle, 'pharmacist') || str_contains($rawType, 'pharmacist') || str_contains(strtolower((string) data_get($metaArr, 'role', '')), 'pharmacist');
                    $hasPatient    = str_contains($rawTitle, 'patient') || str_contains($rawType, 'patient');
                    $hasDecl       = str_contains($rawTitle, 'declaration') || $rawTitle === 'declaration' || str_contains($rawType, 'declaration');
                    $isROS         = $rawType === 'ros' || str_contains($rawType, 'record_of_supply') || str_contains($rawType, 'record-of-supply') || str_contains($rawTitle, 'record of supply');
                    $isAdvice      = str_contains($rawTitle, 'advice') || str_contains($rawTitle, 'consultation') || str_contains($rawType, 'advice');
                    $isRisk        = (str_contains($rawTitle, 'risk') && str_contains($rawTitle, 'assessment')) || (str_contains($rawType, 'risk') && str_contains($rawType, 'assessment'));

                    if ($isROS) {
                        $label = 'Record of Supply';
                        $order = 30;
                    } elseif ($isAdvice) {
                        $label = 'Pharmacist Advice';
                        $order = 10;
                    } elseif ($isRisk) {
                        $label = 'Risk Assessment';
                        $order = 50;
                    } elseif ($hasDecl) {
                        // Default ambiguous "Declaration" to Pharmacist unless explicitly Patient
                        if ($hasPatient && !$hasPharmacist) {
                            $label = 'Patient Declaration';
                            $order = 40;
                        } else {
                            $label = 'Pharmacist Declaration';
                            $order = 20;
                        }
                    } else {
                        $label = $f->title ? (string) $f->title : ucwords(str_replace(['_', '-'], ' ', (string) ($f->form_type ?? '')));
                        $order = 99;
                    }

                    // Item/product name best-effort
                    $meta = is_array($f->meta) ? $f->meta : (json_decode($f->meta ?? '[]', true) ?: []);
                    $item = data_get($meta, 'product_name')
                         ?? data_get($meta, 'treatment')
                         ?? data_get($meta, 'item.name')
                         ?? data_get($meta, 'selectedProduct.name')
                         ?? '—';

                    return [
                        'id'      => $f->id,
                        'title'   => $label,
                        'type'    => $rawType ? ucfirst($rawType) : $label,
                        'item'    => $item,
                        'created' => optional($f->created_at)->format('d-m-Y H:i'),
                        'order'   => $order,
                    ];
                })->toArray();

            usort($forms, function ($a, $b) {
                $cmp = ($a['order'] ?? 99) <=> ($b['order'] ?? 99);
                if ($cmp !== 0) return $cmp;
                return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
            });

            // Build quick actions for each form to render as dropdown buttons
            $formQuickActions = [];
            foreach ($forms as $f) {
                $id    = $f['id'];
                $title = $f['title'];

                $viewUrl    = url("/admin/consultations/{$sessionId}/forms/{$id}/view");
                $editUrl    = url("/admin/consultations/{$sessionId}/forms/{$id}/edit");
                $historyUrl = url("/admin/consultations/{$sessionId}/forms/{$id}/history");

                $formQuickActions[] = Actions\ActionGroup::make([
                    Actions\Action::make("view_{$id}")
                        ->label('View')
                        ->url($viewUrl . (str_contains($viewUrl, '?') ? '&' : '?') . 'inline=1')
                        ->extraAttributes(['data-inline-modal' => true, 'data-title' => $title . ' – View']),
                    Actions\Action::make("edit_{$id}")
                        ->label('Edit')
                        ->url($editUrl . (str_contains($editUrl, '?') ? '&' : '?') . 'inline=1')
                        ->extraAttributes(['data-inline-modal' => true, 'data-title' => $title . ' – Edit']),
                    Actions\Action::make("history_{$id}")
                        ->label('History')
                        ->url($historyUrl . (str_contains($historyUrl, '?') ? '&' : '?') . 'inline=1')
                        ->extraAttributes(['data-inline-modal' => true, 'data-title' => $title . ' – History']),
                ])
                    ->label($title)
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->button();
            }
            if (empty($formQuickActions)) {
                $formQuickActions = [];
            }
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

        return $schema->schema([
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
                                        try { return \Carbon\Carbon::parse($state)->format('d-m-Y'); } catch (\Throwable) { return (string)$state; }
                                    }),
                                TextEntry::make('email')->label('Email')
                                    ->state(fn () => data_get($meta,'email') ?? $rec->user?->email),
                                TextEntry::make('phone')->label('Phone')
                                    ->state(fn () => data_get($meta,'phone') ?? $rec->user?->phone),
                                TextEntry::make('created_at')->label('Created')->dateTime('d-m-Y H:i'),
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

            // Row 3: Patient Notes
            Section::make('Patient Notes')
                ->extraAttributes(['class' => 'bg-transparent shadow-none ring-0 border-0'])
                ->schema([
                TextEntry::make('patient_notes')->hiddenLabel()
                    ->state(fn () => (string)(data_get($meta, 'patient_notes') ?: 'No patient notes provided'))
                    ->formatStateUsing(fn($s) => nl2br(e($s)))->html(),
            ])->columnSpanFull(),

            // Row 4: Admin Notes
            Section::make('Admin Notes')
                ->extraAttributes(['class' => 'bg-transparent shadow-none ring-0 border-0'])
                ->schema([
                TextEntry::make('meta.admin_notes')
                    ->hiddenLabel()
                    ->state(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        return (string) (data_get($meta, 'admin_notes') ?: '—');
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
                        return \Filament\Actions\ActionGroup::make([
                            \Filament\Actions\Action::make("view_{$id}")
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

                                    return new \Illuminate\Support\HtmlString(
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
                            \Filament\Actions\Action::make("edit_{$id}")
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
                                    return new \Illuminate\Support\HtmlString(
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
                            \Filament\Actions\Action::make("history_{$id}")
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
                                    return new \Illuminate\Support\HtmlString(
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

}