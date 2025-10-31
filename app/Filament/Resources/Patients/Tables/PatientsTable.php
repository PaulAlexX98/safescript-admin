<?php

namespace App\Filament\Resources\Patients\Tables;

use Filament\Tables\Columns\TextColumn;
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

class PatientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

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
                TextColumn::make('dob')->label('DOB')->date()->toggleable(),
                TextColumn::make('address1')->label('Street')->toggleable(),
                TextColumn::make('city')->label('City')->toggleable(),
                TextColumn::make('postcode')->label('Postcode')->toggleable(),
                TextColumn::make('country')->label('Country')->toggleable(),
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
                    ->modalHeading(fn ($record) => 'Orders for ' . ($record->first_name . ' ' . $record->last_name))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('7xl')
                    ->modalDescription(function ($record) {
                        // Try to load orders related to the patient.
                        // Priority: explicit relationship -> user_id match -> email in orders table or meta.email
                        try {
                            if (method_exists($record, 'orders')) {
                                $orders = $record->orders()->latest('created_at')->take(150)->get(['id','reference','status','payment_status','created_at','meta','user_id']);
                            } else {
                                $query = Order::query();
                                $query->where(function ($w) use ($record) {
                                    $hasAny = false;
                                    if (!empty($record->user_id)) {
                                        $w->orWhere('user_id', $record->user_id);
                                        $hasAny = true;
                                    }
                                    if (!empty($record->email)) {
                                        $email = strtolower(trim((string) $record->email));
                                        $w->orWhereRaw('LOWER(email) = ?', [$email]);
                                        $w->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email'))) = ?", [$email]);
                                        $w->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.email'))) = ?", [$email]);
                                        $hasAny = true;
                                    }
                                    if (!$hasAny) {
                                        $w->orWhereRaw('1 = 0');
                                    }
                                });
                                $orders = $query->latest('created_at')->take(150)->get(['id','reference','status','payment_status','created_at','meta','user_id']);
                            }
                        } catch (Throwable $e) {
                            $orders = collect();
                        }
  
                        if ($orders->isEmpty()) {
                            return new HtmlString('<p class="text-sm text-gray-500">No orders found for this patient</p>');
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

                        // Build rows (no Tailwind classes)
                        $rows = $orders->map(function ($o) use ($formatDate, $money, $normalizeItems, $lineToString, $statusBadge, $paymentBadge) {
                            $meta = is_array($o->meta) ? $o->meta : (json_decode($o->meta ?? '[]', true) ?: []);
                            $ref = e($o->reference ?? ('#' . $o->id));
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

                            return "<tr>
                                <td class='cell ref'>{$ref}</td>
                                <td class='cell service'>{$service}</td>
                                <td class='cell items' title='" . e($itemsTextFull) . "'>{$itemsHtml}</td>
                                <td class='cell date'>{$created}</td>
                                <td class='cell status'>{$status}</td>
                                <td class='cell payment'>{$payment}</td>
                                <td class='cell total'>{$total}</td>
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
                        .cell.ref{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#d1d5db;width:9ch}
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
                                <col style=\"width:14ch\">
                                <col style=\"width:12rem\">
                                <col style=\"width:30rem\">
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
                    })
            ])

            ->toolbarActions([]);
    }
}