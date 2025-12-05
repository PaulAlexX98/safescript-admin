<?php

namespace App\Filament\Resources\Patients\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\SelectColumn;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Order;
use Throwable;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\Action;
use Illuminate\Support\HtmlString;
use Filament\Tables\Columns\ViewColumn;
use App\Models\Patient;

class PatientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                return $query->with('user');
            })
            ->columns([

                TextColumn::make('patient_priority_dot')
                    ->label('Priority')
                    ->getStateUsing(function ($record) {
                        // 1) Try patient-level related user priority
                        $p = optional($record->user)->priority ?? null;

                        // 2) Fall back to a User matched by the patient's email
                        if (!is_string($p) || trim($p) === '') {
                            try {
                                if (!empty($record->email)) {
                                    $email = strtolower(trim((string) $record->email));
                                    $p = \App\Models\User::query()
                                        ->whereRaw('LOWER(email) = ?', [$email])
                                        ->value('priority');
                                }
                            } catch (\Throwable $e) {
                                // ignore
                            }
                        }

                        // 3) Fall back to the latest order by this email
                        if (!is_string($p) || trim($p) === '') {
                            try {
                                if (!empty($record->email)) {
                                    $email = strtolower(trim((string) $record->email));
                                    $order = \App\Models\Order::query()
                                        ->whereRaw('LOWER(email) = ?', [$email])
                                        ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email'))) = ?", [$email])
                                        ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.email'))) = ?", [$email])
                                        ->latest('created_at')
                                        ->first();
                                    if ($order) {
                                        $om = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                        $p = data_get($om, 'priority');
                                    }
                                }
                            } catch (\Throwable $e) {
                                // ignore
                            }
                        }

                        $p = is_string($p) ? strtolower(trim($p)) : null;
                        return in_array($p, ['red','yellow','green'], true) ? $p : 'green';
                    })
                    ->formatStateUsing(function ($state) {
                        // Render a small coloured dot instead of emoji for cleaner UI
                        $colour = match ($state) {
                            'red' => '#ef4444',
                            'yellow' => '#eab308',
                            'green' => '#22c55e',
                            default => '#22c55e',
                        };
                        $label = ucfirst(is_string($state) ? $state : 'green');
                        return '<span title="' . e($label) . '" style="display:inline-block;width:12px;height:12px;border-radius:9999px;background:' . $colour . ';"></span>';
                    })
                    ->tooltip(function ($record) {
                        // 1) Related user
                        $p = optional($record->user)->priority ?? null;

                        // 2) User by patient email
                        if (!is_string($p) || trim($p) === '') {
                            try {
                                if (!empty($record->email)) {
                                    $email = strtolower(trim((string) $record->email));
                                    $p = \App\Models\User::query()
                                        ->whereRaw('LOWER(email) = ?', [$email])
                                        ->value('priority');
                                }
                            } catch (\Throwable $e) {}
                        }

                        // 3) Latest order by email
                        if (!is_string($p) || trim($p) === '') {
                            try {
                                if (!empty($record->email)) {
                                    $email = strtolower(trim((string) $record->email));
                                    $order = \App\Models\Order::query()
                                        ->whereRaw('LOWER(email) = ?', [$email])
                                        ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email'))) = ?", [$email])
                                        ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.email'))) = ?", [$email])
                                        ->latest('created_at')
                                        ->first();
                                    if ($order) {
                                        $om = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                        $p = data_get($om, 'priority');
                                    }
                                }
                            } catch (\Throwable $e) {}
                        }

                        $p = is_string($p) ? strtolower(trim($p)) : null;
                        $p = in_array($p, ['red','yellow','green'], true) ? $p : 'green';
                        return ucfirst($p);
                    })
                    ->html()
                    ->extraAttributes(['style' => 'text-align:center; width:5rem']),

                // Left-most: computed 4-digit internal id
                TextColumn::make('computed_internal_id')
                    ->label('Internal ID')
                    ->state(fn ($record) => str_pad((string)($record->id ?? 0), 4, '0', STR_PAD_LEFT))
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('id', $direction))
                    ->toggleable(),

                TextColumn::make('first_name')->label('First Name')->searchable()->toggleable(),
                TextColumn::make('last_name')->label('Last Name')->searchable()->toggleable(),
                TextColumn::make('gender')->label('Gender')->toggleable(),
                TextColumn::make('email')->label('Email')->searchable()->toggleable(),
                TextColumn::make('phone')->label('Phone')->searchable()->toggleable(),
                TextColumn::make('dob')
                    ->label('DOB')
                    ->getStateUsing(function ($record) {
                        $u = $record->user ?? null;
                        $raw = $u?->dob;
                        if (!$raw) return '—';
                        try {
                            return ($raw instanceof \Carbon\Carbon)
                                ? $raw->format('d-m-Y')
                                : \Carbon\Carbon::parse($raw)->format('d-m-Y');
                        } catch (\Throwable $e) {
                            return (string) $raw;
                        }
                    })
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('home_address')
                    ->label('Home address')
                    ->getStateUsing(function ($record) {
                        $u = $record->user ?? null;
                        if (!$u) return '—';
                        $line1 = $u->address1 ?? $u->address_1 ?? $u->address_line1 ?? null;
                        $line2 = $u->address2 ?? $u->address_2 ?? $u->address_line2 ?? null;
                        $city  = $u->city ?? $u->town ?? null;
                        $pc    = $u->postcode ?? $u->post_code ?? $u->postal_code ?? $u->zip ?? $u->zip_code ?? null;
                        $parts = [];
                        if (is_string($line1) && trim($line1) !== '') $parts[] = trim($line1);
                        if (is_string($line2) && trim($line2) !== '') $parts[] = trim($line2);
                        if (is_string($city)  && trim($city)  !== '') $parts[] = trim($city);
                        if (is_string($pc)    && trim($pc)    !== '') $parts[] = trim($pc);
                        return $parts ? implode(', ', $parts) : '—';
                    })
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('shipping_address')
                    ->label('Shipping address')
                    ->getStateUsing(function ($record) {
                        $u = $record->user ?? null;
                        if (!$u) return '—';
                        // prefer user shipping
                        $line1 = $u->shipping_address1 ?? $u->shipping_address_1 ?? $u->shipping_line1 ?? null;
                        $line2 = $u->shipping_address2 ?? $u->shipping_address_2 ?? $u->shipping_line2 ?? null;
                        $city  = $u->shipping_city ?? $u->shipping_town ?? null;
                        $pc    = $u->shipping_postcode ?? $u->shipping_post_code ?? $u->shipping_postal_code ?? $u->shipping_zip ?? $u->shipping_zip_code ?? null;
                        // fallback to user home
                        if (!$line1) $line1 = $u->address1 ?? $u->address_1 ?? $u->address_line1 ?? null;
                        if (!$line2) $line2 = $u->address2 ?? $u->address_2 ?? $u->address_line2 ?? null;
                        if (!$city)  $city  = $u->city ?? $u->town ?? null;
                        if (!$pc)    $pc    = $u->postcode ?? $u->post_code ?? $u->postal_code ?? $u->zip ?? $u->zip_code ?? null;
                        $parts = [];
                        if (is_string($line1) && trim($line1) !== '') $parts[] = trim($line1);
                        if (is_string($line2) && trim($line2) !== '') $parts[] = trim($line2);
                        if (is_string($city)  && trim($city)  !== '') $parts[] = trim($city);
                        if (is_string($pc)    && trim($pc)    !== '') $parts[] = trim($pc);
                        return $parts ? implode(', ', $parts) : '—';
                    })
                    ->searchable()
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')

            ->recordActions([
                // Edit action opens the resource edit page
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-m-pencil-square')
                    ->color('primary')
                    ->button()
                    ->url(fn ($record) => PatientResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(false),

                // Orders action opens a modal listing recent orders for the patient
                Action::make('orders')
                    ->label('Orders')
                    ->icon('heroicon-m-shopping-bag')
                    ->color('success')
                    ->button()
                    ->modalHeading(function ($record) {
                        if (!$record) return 'Orders';
                        $fn = trim((string) ($record->first_name ?? optional($record->user)->first_name ?? ''));
                        $ln = trim((string) ($record->last_name  ?? optional($record->user)->last_name  ?? ''));
                        $name = trim($fn . ' ' . $ln);
                        return $name !== '' ? ('Orders for ' . $name) : 'Orders';
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('7xl')
                    ->modalDescription(function ($record) {
                        if (!$record) {
                            return new \Illuminate\Support\HtmlString('<p class="text-sm text-gray-500">No record selected</p>');
                        }
                        // Try to load orders related to the patient.
                        // Priority: explicit relationship (if it returns rows) -> robust fallback lookup
                        try {
                            // Pre-compute IDs for strict matching and filtering
                            $uid = optional($record)->user_id ?: optional($record->user)->id;
                            $pid = optional($record)->id;
                            $orders = collect();

                            // 0) Try relationship first, but only trust it if it returns rows
                            if (method_exists($record, 'orders')) {
                                try {
                                    $relRows = $record->orders()
                                        ->latest('created_at')
                                        ->take(150)
                                        ->get(['id','reference','status','payment_status','created_at','meta','user_id']);
                                    if ($relRows && $relRows->count() > 0) {
                                        $orders = $relRows;
                                    }
                                } catch (\Throwable $e) {
                                    // ignore and fall through
                                }
                            }

                            if ($orders->isEmpty()) {
                                // Build a robust lookup using user_id / patient_id / emails / phone
                                $query = Order::query();

                                $hasEmailColumn = \Schema::hasColumn('orders', 'email');

                                $emails = [];

                                if (!empty($record->email)) {
                                    $emails[] = strtolower(trim((string) $record->email));
                                }
                                if (!empty(optional($record->user)->email)) {
                                    $emails[] = strtolower(trim((string) $record->user->email));
                                }
                                $emails = array_values(array_unique(array_filter($emails)));

                                $phone = trim((string) ($record->phone ?? optional($record->user)->phone ?? ''));
                                $__uid = $uid;
                                $__pid = $pid;

                                // Strict precedence:
                                // 1) If we have user_id or patient_id, match ONLY by those IDs (including JSON meta id paths).
                                // 2) Otherwise (no IDs available), fall back to emails.
                                // 3) Finally, fall back to phone if needed.
                                $haveId = !empty($uid) || !empty($pid);

                                if ($haveId) {
                                    $query->where(function ($w) use ($uid, $pid) {
                                        // user id matches (column or JSON)
                                        if (!empty($uid)) {
                                            $w->orWhere('user_id', $uid);
                                            $w->orWhereRaw("JSON_EXTRACT(meta, '$.user_id') = ?", [$uid]);
                                            $w->orWhereRaw("JSON_EXTRACT(meta, '$.user.id') = ?", [$uid]);
                                        }
                                        // patient id matches (column or JSON)
                                        if (!empty($pid)) {
                                            if (\Schema::hasColumn('orders', 'patient_id')) {
                                                $w->orWhere('patient_id', $pid);
                                            }
                                            $w->orWhereRaw("JSON_EXTRACT(meta, '$.patient_id') = ?", [$pid]);
                                            $w->orWhereRaw("JSON_EXTRACT(meta, '$.patient.id') = ?", [$pid]);
                                        }
                                    });
                                } else {
                                    // No IDs available — do not attempt fuzzy matching
                                    $query->whereRaw('1 = 0');
                                }

                                try {
                                    $columns = ['id','reference','status','payment_status','created_at','meta','user_id'];
                                    if ($hasEmailColumn) $columns[] = 'email';
                                    $orders = $query
                                        ->with([])
                                        ->latest('created_at')
                                        ->take(150)
                                        ->get($columns);
                                } catch (\Throwable $e) {
                                    \Log::warning('patients.orders.lookup_failed', ['err' => $e->getMessage()]);
                                    $orders = collect();
                                }
                            }
                            // Final guard: keep only orders that match by strict IDs
                            $orders = $orders->filter(function ($o) use ($uid, $pid) {
                                $meta = is_array($o->meta) ? $o->meta : (json_decode($o->meta ?? '[]', true) ?: []);
                                $match = false;
                                if (!empty($uid)) {
                                    if ((string)($o->user_id ?? '') === (string)$uid) $match = true;
                                    if ((string) data_get($meta, 'user_id') === (string)$uid) $match = true;
                                    if ((string) data_get($meta, 'user.id') === (string)$uid) $match = true;
                                }
                                if (!empty($pid)) {
                                    if (property_exists($o, 'patient_id') && (string)($o->patient_id ?? '') === (string)$pid) $match = true;
                                    if ((string) data_get($meta, 'patient_id') === (string)$pid) $match = true;
                                    if ((string) data_get($meta, 'patient.id') === (string)$pid) $match = true;
                                }
                                return $match;
                            })->values();
                        } catch (Throwable $e) {
                            $orders = collect();
                        }

                        if (!isset($emails) || !is_array($emails)) $emails = [];
                        \Log::info('patients.orders.lookup_summary', [
                            'mode' => (!empty($record->user_id) || !empty($record->id)) ? 'id-first' : 'fallback',
                            'patient_id' => $record->id,
                            'user_id' => $record->user_id,
                            'user_email' => optional($record->user)->email,
                            'patient_email' => $record->email,
                            'emails_used' => $emails,
                            'count' => $orders->count(),
                        ]);

                        if ($orders->isEmpty()) {
                            $diag = [
                                'patient_id' => $record->id,
                                'user_id' => $record->user_id,
                                'user_email' => optional($record->user)->email,
                                'patient_email' => $record->email,
                                'has_rel' => method_exists($record, 'orders') ? 'yes' : 'no',
                            ];
                            // Show a small grey diagnostics line in the modal to help debug
                            $hint = '<div style="margin-bottom:.5rem;color:#9ca3af;font-size:12px">Lookup used: '
                                . e(json_encode($diag)) . '</div>';
                            // Continue with empty message, but include the hint
                            return new HtmlString($hint . '<p class="text-sm text-gray-500">No orders found for this patient</p>');
                        }
  
                        // Helpers
                        $formatDate = function ($dt) {
                            try { return optional(Carbon::parse($dt))->format('D j M Y · H:i'); } catch (Throwable) { return (string) $dt; }
                        };
                        $money = function ($value) {
                            if ($value === null || $value === '') return null;
                            if (is_int($value)) return '£' . number_format($value / 100, 2);
                            if (is_float($value)) return '£' . number_format($value, 2);
                            if (is_string($value)) {
                                $s = preg_replace('/[^\d\.\,\-]/', '', trim($value));
                                if ($s === '') return null;
                                if (strpos($s, ',') !== false && strpos($s, '.') === false) $s = str_replace(',', '.', $s); else $s = str_replace(',', '', $s);
                                if (!is_numeric($s)) return null;
                                return '£' . number_format(((float) $s), 2);
                            }
                            return null;
                        };
                        $normalizeItems = function ($value) {
                            if (is_string($value)) {
                                $decoded = json_decode($value, true);
                                if (json_last_error() === JSON_ERROR_NONE) $value = $decoded;
                            }
                            if ($value instanceof Collection) $value = $value->toArray();
                            if (is_array($value)) {
                                if (isset($value['items']) && is_array($value['items'])) return $value['items'];
                                if (isset($value['lines']) && is_array($value['lines'])) return $value['lines'];
                                if (isset($value['products']) && is_array($value['products'])) return $value['products'];
                                if (isset($value['data']) && is_array($value['data'])) return $value['data'];
                                // single associative product
                                $isList = array_keys($value) === range(0, count($value) - 1);
                                if (!$isList && (isset($value['name']) || isset($value['title']) || isset($value['product_name']))) {
                                    return [$value];
                                }
                            }
                            return is_array($value) ? $value : [];
                        };
                        $lineToString = function ($it) {
                            if (is_string($it)) return '1 × ' . $it;
                            if (!is_array($it)) return null;
                            $qty  = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                            if ($qty < 1) $qty = 1;
                            $name = $it['name'] ?? $it['title'] ?? $it['product_name'] ?? null;
                            // Variation resolver (same as Pending/Orders)
                            $resolveOpt = function ($row) {
                                $keys = [
                                    'variations','variation','optionLabel','variant','dose','strength','option',
                                    'meta.variations','meta.variation','meta.optionLabel','meta.variant','meta.dose','meta.strength','meta.option',
                                    'selected.variations','selected.variation','selected.optionLabel','selected.variant','selected.dose','selected.strength','selected.option',
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
                                    $optParts = [];
                                    foreach ($row['options'] as $op) {
                                        if (is_array($op)) $optParts[] = $op['label'] ?? $op['value'] ?? null;
                                    }
                                    $opt = trim(implode(' ', array_filter(array_map('strval', array_filter($optParts)))));
                                    if ($opt !== '') return $opt;
                                }
                                if (is_array($row['attributes'] ?? null)) {
                                    $optParts = [];
                                    foreach ($row['attributes'] as $op) {
                                        if (is_array($op)) $optParts[] = $op['label'] ?? $op['value'] ?? null;
                                    }
                                    $opt = trim(implode(' ', array_filter(array_map('strval', array_filter($optParts)))));
                                    if ($opt !== '') return $opt;
                                }
                                return null;
                            };
                            $opt = $resolveOpt($it);
                            $parts = [];
                            $parts[] = $qty . ' ×';
                            if ($name) $parts[] = $name;
                            if ($opt)  $parts[] = $opt;
                            return trim(implode(' ', $parts)) ?: null;
                        };
  
                        // Badge helpers (pure CSS via inline styles)
                        $statusBadge = function ($status) {
                            $s = strtolower(trim((string) $status));
                            $map = [
                                'approved' => ['#22c55e', 'Approved'],
                                'completed' => ['#22c55e', 'Completed'],
                                'pending'  => ['#eab308', 'Pending'],
                                'awaiting_approval' => ['#eab308', 'Awaiting'],
                                'rejected' => ['#f43f5e', 'Rejected'],
                                'cancelled'=> ['#f43f5e', 'Cancelled'],
                            ];
                            $conf = $map[$s] ?? ['#6b7280', ucfirst($s ?: '—')];
                            $bg = $conf[0] . '20';
                            return "<span style=\"display:inline-flex;align-items:center;padding:.125rem .375rem;border-radius:.375rem;font-size:.75rem;font-weight:600;color:{$conf[0]};background-color:{$bg}\">{$conf[1]}</span>";
                        };
                        $paymentBadge = function ($status) {
                            $s = strtolower(trim((string) $status));
                            $map = [
                                'paid'     => ['#22c55e', 'Paid'],
                                'refunded' => ['#f43f5e', 'Refunded'],
                                'unpaid'   => ['#eab308', 'Unpaid'],
                                'pending'  => ['#eab308', 'Pending'],
                            ];
                            $conf = $map[$s] ?? ['#6b7280', ucfirst($s ?: '—')];
                            $bg = $conf[0] . '20';
                            return "<span style=\"display:inline-flex;align-items:center;padding:.125rem .375rem;border-radius:.375rem;font-size:.75rem;font-weight:600;color:{$conf[0]};background-color:{$bg}\">{$conf[1]}</span>";
                        };

                        // Ensure debug-scoping vars are always defined
                        if (!isset($__uid)) { $__uid = null; }
                        if (!isset($__pid)) { $__pid = null; }
                        if (!isset($emails) || !is_array($emails)) { $emails = []; }
                        if (!isset($phone)) { $phone = ''; }
                        // Build rows (no Tailwind classes)
                        $rows = $orders->map(function ($o) use ($formatDate, $money, $normalizeItems, $lineToString, $statusBadge, $paymentBadge, $__uid, $__pid, $emails, $phone) {
                            $meta = is_array($o->meta) ? $o->meta : (json_decode($o->meta ?? '[]', true) ?: []);
                            // Work out why this order matched (debug badge)
                            $mUserId   = data_get($meta, 'user_id') ?? data_get($meta, 'user.id');
                            $mPatientId= data_get($meta, 'patient_id') ?? data_get($meta, 'patient.id');
                            $src = null;
                            if ($__uid && ((string)$o->user_id === (string)$__uid)) $src = 'user_id';
                            elseif ($__uid && ((string)$mUserId === (string)$__uid)) $src = 'meta.user_id';
                            elseif ($__pid && (property_exists($o, 'patient_id') && (string)$o->patient_id === (string)$__pid)) $src = 'patient_id';
                            elseif ($__pid && ((string)$mPatientId === (string)$__pid)) $src = 'meta.patient_id';
                            else {
                                // No fallback – we already filtered by strict IDs only
                                $src = '';
                            }
                            $srcBadge = $src ? "<span style=\"margin-left:.35rem;font-size:.65rem;color:#9ca3af;border:1px solid rgba(156,163,175,.35);padding:.05rem .3rem;border-radius:.25rem\">{$src}</span>" : '';
                            $refRaw = (string)($o->reference ?? ('#' . $o->id));
                            $ref = e($refRaw) . $srcBadge;
                            $service = e(
                                data_get($meta, 'service')
                                ?? data_get($meta, 'serviceName')
                                ?? data_get($meta, 'treatment')
                                ?? data_get($meta, 'title')
                                ?? 'Order'
                            );
                            $items = $normalizeItems(
                                data_get($meta, 'items')
                                ?? data_get($meta, 'products')
                                ?? data_get($meta, 'lines')
                                ?? data_get($meta, 'line_items')
                                ?? data_get($meta, 'order.items')
                                ?? data_get($meta, 'cart.items')
                            );
                            $labels = [];
                            foreach ($items as $it) {
                                $label = $lineToString($it);
                                if ($label !== null && $label !== '') {
                                    $labels[] = e($label);
                                }
                            }
                            // Full text for the title attribute kept as a single line
                            $itemsTextFull = implode(', ', array_map(fn($s) => strip_tags($s), $labels));
                            // Render items one-per-line using <br>
                            $itemsHtml = implode('<br>', $labels);
                            $created = e($formatDate($o->created_at));
                            $status = $statusBadge($o->status ?? '');
                            $payment = $paymentBadge($o->payment_status ?? '');
                            $totalMinor = data_get($meta, 'totalMinor')
                                ?? data_get($meta, 'amountMinor')
                                ?? data_get($meta, 'total_minor')
                                ?? data_get($meta, 'amount_minor');
                            $total = is_numeric($totalMinor)
                                ? '£' . number_format(((int) $totalMinor) / 100, 2)
                                : ($money(data_get($meta, 'total') ?? data_get($meta, 'amount') ?? data_get($meta, 'subtotal')) ?? '—');

                            // Always open completed-orders details page
                            $detailsUrl = "/admin/orders/completed-orders/{$o->id}/details";
                            $viewBtn = "<a href='" . e($detailsUrl) . "' target='_blank' rel='noopener' style=\"display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .55rem;border-radius:.375rem;background:#ea580c;color:#fff;font-weight:600;text-decoration:none\">View</a>";

                            return "<tr>
                                <td class='cell ref' title='" . e($refRaw) . "'>{$ref}</td>
                                <td class='cell service'>{$service}</td>
                                <td class='cell items' title='" . e($itemsTextFull) . "'>{$itemsHtml}</td>
                                <td class='cell date'>{$created}</td>
                                <td class='cell status'>{$status}</td>
                                <td class='cell payment'>{$payment}</td>
                                <td class='cell total'>{$total}</td>
                                <td>{$viewBtn}</td>
                            </tr>";
                        })->implode('');

                        $summary = "<div class='orders-summary'>" . e($orders->count()) . " orders</div>";

                        // Self-contained CSS (no Tailwind) for a neat, readable table layout (updated for Filament dark and widened columns)
                        $css = <<<CSS
                        <style>
                        /* Container uses Filament dark surface tone */
                        .orders-wrap{max-height:70vh;overflow:auto;border:1px solid rgba(255,255,255,.06);border-radius:.75rem;background:rgba(0, 17, 17, 1)}
                        .orders-table{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;font-size:12.5px;line-height:1.35;color:#e5e7eb}
                        .orders-table thead{position:sticky;top:0;background:rgba(30,41,59,.85);backdrop-filter:saturate(150%) blur(6px);z-index:1}
                        .orders-table th{font-weight:600;text-align:left;color:#9ca3af;padding:.5rem .65rem;border-bottom:1px solid rgba(255,255,255,.06)}
                        .orders-table td{padding:.5rem .65rem;border-bottom:1px solid rgba(255,255,255,.05)}
                        .orders-table th:nth-child(5), .orders-table td:nth-child(5), .orders-table th:nth-child(6), .orders-table td:nth-child(6){text-align:center}
                        .orders-table tr:hover{background:rgba(255,255,255,.03)}
                        .orders-summary{margin-bottom:.4rem;font-size:11px;color:#9ca3af}

                        .cell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                        .cell.items{white-space:normal;line-height:1.35}
                        .cell.ref{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#d1d5db;width:18ch;white-space:nowrap;overflow:visible;text-overflow:clip}
                        /* Widened columns */
                        .cell.service{width:24rem}
                        .cell.items{width:44rem;color:#cbd5e1}
                        .cell.date{width:14rem;color:#9ca3af}
                        .cell.total{width:9rem;text-align:right;font-weight:700;color:#f1f5f9}
                        /* Force narrow widths for Status and Payment columns */
                        .orders-table th:nth-child(5), .orders-table td:nth-child(5){width:5rem !important}
                        .orders-table th:nth-child(6), .orders-table td:nth-child(6){width:5rem !important}

                        /* Slightly shrink on narrower viewports */
                        @media (max-width:1600px){
                          .cell.items{width:38rem}
                          .cell.service{width:22rem}
                        }
                        @media (max-width:1400px){
                          .cell.items{width:30rem}
                          .cell.service{width:20rem}
                        }
                        </style>
                        CSS;

                        $table = $summary . "<div class='orders-wrap'><table class='orders-table'>
                            <colgroup>
                                <col style=\"width:18ch\">
                                <col style=\"width:9rem\">
                                <col style=\"width:26rem\">
                                <col style=\"width:10rem\">
                                <col style=\"width:6rem\">
                                <col style=\"width:6rem\">
                                <col style=\"width:5rem\">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Ref</th>
                                    <th>Service</th>
                                    <th>Items</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th style='text-align:right'>Total</th>
                                </tr>
                            </thead>
                            <tbody>{$rows}</tbody>
                        </table></div>";

                        return new HtmlString($css . $table);
                    }),
            ])

            ->toolbarActions([]);
    }
}