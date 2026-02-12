<?php

namespace App\Filament\Resources\PendingOrders;

use Carbon\Carbon;
use Throwable;
use Illuminate\Support\Collection;
use Filament\Tables\Filters\SelectFilter;
use App\Filament\Resources\PendingOrders\Pages\CreatePendingOrder;
use App\Filament\Resources\PendingOrders\Pages\EditPendingOrder;
use App\Filament\Resources\PendingOrders\Pages\ListPendingOrders;             // Forms components
use App\Filament\Resources\PendingOrders\Schemas\PendingOrderForm;
use App\Filament\Resources\PendingOrders\Tables\PendingOrdersTable;
use Filament\Actions\Action;
use App\Models\PendingOrder;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Schema as DBSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema as FilamentSchema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderApprovedMail;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Notifications\Notification;


class PendingOrderResource extends Resource
{
    protected static ?string $model = PendingOrder::class;

    protected static function normEmail($v): string
    {
        return strtolower(trim((string) ($v ?? '')));
    }

    protected static function normPhone($v): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($v ?? ''));
        return is_string($digits) ? $digits : '';
    }

    protected static function normName($v): string
    {
        return strtolower(trim((string) ($v ?? '')));
    }

    protected static function normPostcode($v): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) ($v ?? ''))));
    }

    /**
     * Find up to 3 similar users for this pending order (email, phone, name+dob, postcode+lastname, address1+postcode).
     */
    protected static function findSimilarUsersForPending($record): \Illuminate\Support\Collection
    {
        $u = $record->user ?? null;
        if (! $u) return collect();

        $email = static::normEmail($u->email ?? null);
        $phone = static::normPhone($u->phone ?? ($u->phone_number ?? null));
        $first = static::normName($u->first_name ?? null);
        $last  = static::normName($u->last_name ?? null);

        $dob = null;
        if (!empty($u->dob)) {
            try {
                $dob = \Carbon\Carbon::parse($u->dob)->toDateString();
            } catch (\Throwable $e) {
                $dob = null;
            }
        }

        $postcode = static::normPostcode($u->postcode ?? ($u->post_code ?? ($u->postal_code ?? null)));
        $addr1 = static::normName($u->address1 ?? ($u->address_1 ?? ($u->address_line1 ?? null)));

        return \App\Models\User::query()
            ->whereKeyNot($u->getKey())
            ->where(function ($q) use ($email, $phone, $first, $last, $dob, $postcode, $addr1) {
                if ($email !== '') {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }

                if ($phone !== '') {
                    $q->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$phone]);
                }

                if ($first !== '' && $last !== '' && $dob) {
                    $q->orWhere(function ($q2) use ($first, $last, $dob) {
                        $q2->whereRaw('LOWER(first_name) = ?', [$first])
                            ->whereRaw('LOWER(last_name) = ?', [$last])
                            ->whereDate('dob', $dob);
                    });
                }

                if ($postcode !== '' && $last !== '') {
                    $q->orWhere(function ($q3) use ($postcode, $last) {
                        $q3->whereRaw('REPLACE(UPPER(postcode), " ", "") = ?', [$postcode])
                            ->whereRaw('LOWER(last_name) = ?', [$last]);
                    });
                }

                if ($addr1 !== '' && $postcode !== '') {
                    $q->orWhere(function ($q4) use ($addr1, $postcode) {
                        $q4->whereRaw('LOWER(address1) = ?', [$addr1])
                            ->whereRaw('REPLACE(UPPER(postcode), " ", "") = ?', [$postcode]);
                    });
                }
            })
            ->limit(3)
            ->get();
    }

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Pending Approval';
    protected static ?string $pluralLabel = 'Pending Approval';
    protected static ?string $modelLabel = 'Pending Approval';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        // Only show records that are still pending.
        // Be defensive because deployments may have slightly different column names.
        try {
            $table = $q->getModel()->getTable();

            if (DBSchema::hasColumn($table, 'status')) {
                $q->whereIn('status', ['pending', 'awaiting', 'waiting']);
            } elseif (DBSchema::hasColumn($table, 'pending_status')) {
                $q->whereIn('pending_status', ['pending', 'awaiting', 'waiting']);
            } elseif (DBSchema::hasColumn($table, 'state')) {
                $q->whereIn('state', ['pending', 'awaiting', 'waiting']);
            }

            // If the table stores decision timestamps, treat “pending” as not yet decided.
            if (DBSchema::hasColumn($table, 'approved_at')) {
                $q->whereNull('approved_at');
            }
            if (DBSchema::hasColumn($table, 'rejected_at')) {
                $q->whereNull('rejected_at');
            }
            if (DBSchema::hasColumn($table, 'decision_at')) {
                $q->whereNull('decision_at');
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Hide NHS Pharmacy First bookings from Pending Approval.
        // NHS refs are PNHSxxxxxx and we also mark meta.type/service_slug.
        return $q->where(function (Builder $w) {
            $w->where(function (Builder $x) {
                // 1) Exclude PNHS references
                $x->whereNull('reference')
                  ->orWhere('reference', 'not like', 'PNHS%');
            })
            // 2) Exclude meta.type == nhs
            ->whereRaw(
                "COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))), '') <> ?",
                ['nhs']
            )
            // 3) Exclude pharmacy-first service slug
            ->whereRaw(
                "COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service_slug'))), '') <> ?",
                ['pharmacy-first']
            );
        });
    }

    public static function form(FilamentSchema $filamentSchema): FilamentSchema
    {
        return PendingOrderForm::configure($filamentSchema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('patient_priority_dot')
                    ->label('Priority')
                    ->getStateUsing(function ($record) {
                        // 1) Try patient-level priority
                        $p = optional($record->user)->priority ?? null;

                        // 2) Fall back to this pending order's meta.priority
                        if (!is_string($p) || trim($p) === '') {
                            $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                            $p = data_get($meta, 'priority');
                        }

                        // 3) Fall back to the real order's meta.priority by reference
                        if (!is_string($p) || trim($p) === '') {
                            try {
                                $order = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                if ($order) {
                                    $om = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                    $p = data_get($om, 'priority');
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
                        $p = optional($record->user)->priority;
                        if (!is_string($p) || trim($p) === '') {
                            $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                            $p = data_get($meta, 'priority');
                        }
                        if (!is_string($p) || trim($p) === '') {
                            try {
                                $order = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                if ($order) {
                                    $om = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                    $p = data_get($om, 'priority');
                                }
                            } catch (\Throwable $e) {}
                        }
                        $p = is_string($p) ? strtolower(trim($p)) : null;
                        $p = in_array($p, ['red','yellow','green'], true) ? $p : 'green';
                        return ucfirst($p);
                    })
                    ->html()
                    ->extraAttributes(['style' => 'text-align:center; width:5rem']),
                
                TextColumn::make('created_at')
                    ->label('Order Created')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->toggleable(),

                
                TextColumn::make('service_name')
                    ->label('Order Service')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $name = data_get($meta, 'service')
                            ?: data_get($meta, 'serviceName')
                            ?: data_get($meta, 'treatment')
                            ?: data_get($meta, 'title');

                        // fallback or override from consultation session slug
                        $sid = data_get($meta, 'consultation_session_id')
                            ?? data_get($meta, 'consultation.sessionId');

                        if ($sid) {
                            try {
                                $slug = DB::table('consultation_sessions')
                                    ->where('id', $sid)
                                    ->value('service_slug');

                                if ($slug) {
                                    $fromSlug = ucwords(str_replace(['-', '_'], ' ', $slug));
                                    // prefer the slug source if meta name is missing or looks generic
                                    if (!$name || stripos($name, 'weight management') !== false || stripos($name, 'service') !== false) {
                                        $name = $fromSlug;
                                    }
                                }
                            } catch (Throwable $e) {
                                // ignore
                            }
                        }

                        return $name ?: 'Service';
                    }),

                TextColumn::make('order_item')
                    ->label('Order Item')
                    ->getStateUsing(function ($record) {
                        // Helper: normalize collections / JSON strings / keyed containers into a plain array
                        $normalize = function ($value) {
                            if (is_string($value)) {
                                $decoded = json_decode($value, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $value = $decoded;
                                }
                            }
                            if ($value instanceof Collection) {
                                $value = $value->toArray();
                            }
                            if (is_array($value)) {
                                if (isset($value['items']) && is_array($value['items'])) return $value['items'];
                                if (isset($value['lines']) && is_array($value['lines'])) return $value['lines'];
                                if (isset($value['products']) && is_array($value['products'])) return $value['products'];
                                if (isset($value['data']) && is_array($value['data'])) return $value['data'];
                                // Wrap a single associative product into a list so foreach treats it as one line item
                                $isList = array_keys($value) === range(0, count($value) - 1);
                                if (
                                    !$isList &&
                                    (isset($value['name']) || isset($value['title']) || isset($value['product_name']))
                                ) {
                                    return [$value];
                                }
                            }
                            return is_array($value) ? $value : [];
                        };

                        // Helper: turn a line item into "2 × Name Option", using only structured fields, including nested keys and arrays
                        $lineToString = function ($it) {
                            if (is_string($it)) {
                                return '1 × ' . $it;
                            }
                            if (!is_array($it)) return null;
                            $qty  = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                            if ($qty < 1) $qty = 1;
                            $name = $it['name'] ?? $it['title'] ?? $it['product_name'] ?? null;
                            // Resolve variation strictly from structured fields, allow nested keys and arrays
                            $resolveOpt = function ($row) {
                                $keys = [
                                    'variations', 'variation', 'optionLabel', 'variant', 'dose', 'strength', 'option',
                                    'meta.variations', 'meta.variation', 'meta.optionLabel', 'meta.variant', 'meta.dose', 'meta.strength', 'meta.option',
                                ];
                                foreach ($keys as $k) {
                                    $v = data_get($row, $k);
                                    if ($v !== null && $v !== '') {
                                        if (is_array($v)) {
                                            // If it's an associative array with label/value, prefer label
                                            if (array_key_exists('label', $v)) return (string) $v['label'];
                                            if (array_key_exists('value', $v)) return (string) $v['value'];
                                            // Otherwise join scalar parts with space
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
                                // options[] or attributes[]: join first or all labels
                                if (is_array($row['options'] ?? null)) {
                                    $optParts = [];
                                    foreach ($row['options'] as $op) {
                                        if (is_array($op)) {
                                            $optParts[] = $op['label'] ?? $op['value'] ?? null;
                                        }
                                    }
                                    $opt = trim(implode(' ', array_filter(array_map('strval', array_filter($optParts)))));
                                    if ($opt !== '') return $opt;
                                }
                                if (is_array($row['attributes'] ?? null)) {
                                    $optParts = [];
                                    foreach ($row['attributes'] as $op) {
                                        if (is_array($op)) {
                                            $optParts[] = $op['label'] ?? $op['value'] ?? null;
                                        }
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

                        // Collect items from most common locations
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $candidates = [
                            data_get($meta, 'items'),
                            data_get($meta, 'products'),
                            data_get($meta, 'lines'),
                            data_get($meta, 'line_items'),
                            data_get($meta, 'cart.items'),
                            $record->products ?? null,
                            $record->items ?? null,
                            $record->lines ?? null,
                            $record->line_items ?? null
                        ];

                        $items = [];
                        foreach ($candidates as $cand) {
                            $arr = $normalize($cand);
                            if (!empty($arr)) {
                                $items = $arr;
                                break;
                            }
                        }

                        // Build labels for all items
                        $labels = [];
                        foreach ($items as $it) {
                            $label = $lineToString($it);
                            if ($label) $labels[] = $label;
                        }

                        // If nothing found, try single-product scattered keys
                        if (empty($labels)) {
                            $qty  = data_get($meta, 'qty')
                                ?? data_get($meta, 'quantity')
                                ?? data_get($meta, 'selectedProduct.qty')
                                ?? data_get($meta, 'selected_product.qty')
                                ?? data_get($meta, 'order.qty')
                                ?? data_get($meta, 'order.quantity')
                                ?? 1;

                            $name = data_get($meta, 'product_name')
                                ?? data_get($meta, 'product')
                                ?? data_get($meta, 'selectedProduct.name')
                                ?? data_get($meta, 'selected_product.name')
                                ?? data_get($meta, 'medication.name')
                                ?? data_get($meta, 'drug.name')
                                ?? data_get($meta, 'product.name')
                                ?? data_get($meta, 'item.name')
                                ?? data_get($meta, 'line.name')
                                ?? data_get($meta, 'cart.item.name');

                            $opt  = data_get($meta, 'selectedProduct.variations')
                                ?? data_get($meta, 'selected_product.variations')
                                ?? data_get($meta, 'selectedProduct.optionLabel')
                                ?? data_get($meta, 'selected_product.optionLabel')
                                ?? data_get($meta, 'selectedProduct.variant')
                                ?? data_get($meta, 'selected_product.variant')
                                ?? data_get($meta, 'selectedProduct.dose')
                                ?? data_get($meta, 'selected_product.dose')
                                ?? data_get($meta, 'selectedProduct.strength')
                                ?? data_get($meta, 'selected_product.strength')
                                ?? data_get($meta, 'strength')
                                ?? data_get($meta, 'dose')
                                ?? data_get($meta, 'variant')
                                ?? data_get($meta, 'option')
                                ?? null;

                            if ($name) {
                                $labels[] = trim(((int)$qty ?: 1) . ' × ' . $name . ($opt ? ' ' . $opt : ''));
                            }
                        }

                        // Join all labels with line breaks so the table cell shows multiple lines
                        if (!empty($labels)) {
                            return implode("\n", $labels);
                        }

                        return null;
                    })
                    ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : null)
                    ->html()
                    ->wrap()
                    ->toggleable(),

                // Appointment Type (New / Transfer / Reorder)
                TextColumn::make('type')
                    ->label('Type')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $norm = fn ($v) => strtolower(trim((string) $v));

                        $raw  = $norm(data_get($meta, 'type'));
                        $mode = $norm(data_get($meta, 'mode') ?? data_get($meta, 'flow'));
                        $path = $norm(data_get($meta, 'path') ?? data_get($meta, 'source_url') ?? data_get($meta, 'referer'));
                        $svc  = $norm(data_get($meta, 'service') ?? data_get($meta, 'serviceName') ?? data_get($meta, 'title') ?? '');
                        $ref  = strtoupper((string) ($record->reference ?? ''));

                        $isReorder = in_array($raw, ['reorder','repeat','re-order','repeat-order'], true)
                            || in_array($mode, ['reorder','repeat'], true)
                            || str_contains($path, '/reorder')
                            || preg_match('/^PTC[A-Z]*R\d{6}$/', $ref)
                            || str_contains($svc, 'reorder') || str_contains($svc, 'repeat') || str_contains($svc, 're-order');

                        $isNhs = ($raw === 'nhs')
                            || preg_match('/^PTC[A-Z]*H\d{6}$/', $ref)
                            || str_contains($svc, 'nhs');

                        $isNew = ($raw === 'new')
                            || preg_match('/^PTC[A-Z]*N\d{6}$/', $ref);

                        if ($isReorder) return 'Reorder';
                        if ($isNhs)     return 'NHS';
                        if ($isNew)     return 'New';
                        return null;
                    })
                    ->toggleable(),

                TextColumn::make('customer')
                    ->label('Customer')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                        $pick = function ($arr, array $keys) {
                            foreach ($keys as $k) {
                                $v = data_get($arr, $k);
                                if (is_string($v) && trim($v) !== '') {
                                    return trim($v);
                                }
                            }
                            return null;
                        };

                        // Try structured first and last names across many common shapes
                        $first = $pick($meta, [
                            'firstName', 'first_name', 'patient.firstName', 'patient.first_name',
                            'customer.first_name', 'customer.given_name', 'contact.first_name', 'answers.first_name',
                            'personal.first_name', 'personal.firstName', 'form.personal.first_name', 'form.personal.firstName',
                            'billingAddress.first_name', 'shippingAddress.first_name', 'patient.first',
                        ]);

                        $last = $pick($meta, [
                            'lastName', 'last_name', 'patient.lastName', 'patient.last_name',
                            'customer.last_name', 'customer.family_name', 'contact.last_name', 'answers.last_name',
                            'personal.last_name', 'personal.lastName', 'form.personal.last_name', 'form.personal.lastName',
                            'billingAddress.last_name', 'shippingAddress.last_name', 'patient.last',
                        ]);

                        // Full name fields if present
                        $full = $pick($meta, [
                            'name', 'full_name', 'fullName',
                            'patient.name', 'patient.full_name',
                            'customer.name', 'contact.name', 'answers.name',
                        ]);

                        if (!$full) {
                            $combined = trim(trim((string) $first) . ' ' . trim((string) $last));
                            if ($combined !== '') {
                                $full = $combined;
                            }
                        }

                        // Fall back to the related user, if any
                        if (!$full && isset($record->user)) {
                            $userCombined = trim(trim((string) ($record->user->first_name ?? '')) . ' ' . trim((string) ($record->user->last_name ?? '')));
                            $full = $userCombined !== '' ? $userCombined : ($record->user->name ?? null);
                        }

                        return $full ?: '—';
                    })
                    ->searchable(true, function (Builder $query, string $search): Builder {
                        $like = '%' . $search . '%';

                        return $query->where(function (Builder $q) use ($like) {
                            // Search common name/email shapes inside meta JSON
                            $q->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.firstName')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.first_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.lastName')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.last_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.full_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')) LIKE ?", [$like])

                              // Patient/customer nested shapes
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.first_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.last_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.customer.first_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.customer.last_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.customer.name')) LIKE ?", [$like])

                              // Related user model (first_name/last_name/email)
                              ->orWhereHas('user', function ($uq) use ($like) {
                                  $uq->where('first_name', 'like', $like)
                                     ->orWhere('last_name', 'like', $like)
                                     ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", [$like])
                                     ->orWhere('email', 'like', $like);
                              })

                              // Direct order reference search
                              ->orWhere('reference', 'like', $like);
                        });
                    })
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'new' => 'New',
                        'reorder' => 'Reorder',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $val = strtolower((string)($data['value'] ?? ''));
                        if ($val === '') return $query;

                        return $query->where(function (Builder $q) use ($val) {
                            // meta.type exact
                            $q->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))) = ?", [$val])
                            ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.\"type\"'))) = ?", [$val]);

                            // reference patterns
                            if ($val === 'reorder') {
                                $q->orWhere('reference', 'REGEXP', '^PTC[A-Z]*R[0-9]{6}$');
                            } elseif ($val === 'new') {
                                $q->orWhere('reference', 'REGEXP', '^PTC[A-Z]*N[0-9]{6}$');
                            }

                            // service name hints
                            if ($val === 'reorder') {
                                $q->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE '%reorder%'")
                                ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE '%repeat%'")
                                ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE '%reorder%'")
                                ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE '%repeat%'")
                                ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE '%reorder%'")
                                ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE '%repeat%'");
                            }
                        });
                    }),
            ])
            ->recordActions([
                Action::make('viewOrder')
                    ->label('View Order')
                    ->button()
                    ->color('primary')
                    ->modalHeading(function ($record) {
                        if (! $record) {
                            return 'Order Details';
                        }
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $name = data_get($meta, 'service')
                            ?: data_get($meta, 'serviceName')
                            ?: data_get($meta, 'treatment')
                            ?: data_get($meta, 'title')
                            ?: 'Weight Management Service';
                        return $name ?: 'Order Details';
                    })
                    ->modalDescription(function ($record) {
                        $ref = e(optional($record)->reference ?? '—');

                        $html = '<div class="space-y-1">'
                            . '<div><span class="text-xs text-gray-400">Order Ref: ' . $ref . '</span></div>';

                        try {
                            $matches = static::findSimilarUsersForPending($record);
                            if ($matches->isNotEmpty()) {
                                $labels = [];
                                foreach ($matches as $m) {
                                    $label = trim(trim((string) ($m->first_name ?? '')) . ' ' . trim((string) ($m->last_name ?? '')));
                                    if ($label === '') {
                                        $label = (string) ($m->email ?? ('User #' . $m->getKey()));
                                    }
                                    if ($label !== '') {
                                        $labels[] = $label;
                                    }
                                }

                                $labels = array_values(array_unique($labels));
                                $shown = array_slice($labels, 0, 3);
                                $text = implode(', ', array_map('e', $shown));

                                $suffix = '';
                                $remaining = max(0, count($labels) - count($shown));
                                if ($remaining > 0) {
                                    $suffix = ' and ' . $remaining . ' more';
                                }

                                $html .= '<div><span class="text-xs text-rose-400">This patient has similar attributes as ' . $text . $suffix . '.</span></div>';
                            }
                        } catch (\Throwable $e) {
                            // ignore
                        }

                        $html .= '</div>';

                        return new \Illuminate\Support\HtmlString($html);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->schema([
                        // Top two-column layout to "squeeze" content
                        Grid::make(12)->schema([
                            // LEFT COLUMN: Patient first
                            Section::make('Patient Details')
                                ->columnSpan(8)
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextEntry::make('patient_first_name')
                                            ->label('First Name')
                                            ->getStateUsing(function ($record) {
                                                if (! $record) { return null; }
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                foreach (['firstName', 'first_name', 'patient.firstName', 'patient.first_name'] as $key) {
                                                    $v = data_get($meta, $key);
                                                    if ($v) return $v;
                                                }
                                                return $record->user->first_name ?? null;
                                            }),
                                        TextEntry::make('patient_last_name')
                                            ->label('Last Name')
                                            ->getStateUsing(function ($record) {
                                                if (! $record) { return null; }
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                foreach (['lastName', 'last_name', 'patient.lastName', 'patient.last_name'] as $key) {
                                                    $v = data_get($meta, $key);
                                                    if ($v) return $v;
                                                }
                                                return $record->user->last_name ?? null;
                                            }),
                                        TextEntry::make('dob')
                                            ->label('DOB')
                                            ->getStateUsing(function ($record) {
                                                if (! $record) { return null; }
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                foreach (['dob','dateOfBirth','patient.dob','patient.dateOfBirth'] as $k) {
                                                    $v = data_get($meta, $k);
                                                    if ($v) return $v;
                                                }
                                                return $record->user->dob ?? null;
                                            })
                                            ->formatStateUsing(function ($state) {
                                                if (empty($state)) return null;
                                                try { return Carbon::parse($state)->format('d-m-Y'); } catch (Throwable) { return $state; }
                                            }),
                                        TextEntry::make('meta.email')
                                            ->label('Email')
                                            ->getStateUsing(function ($record) {
                                                if (! $record) { return null; }
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'email') ?: ($record->user->email ?? null);
                                            }),
                                        TextEntry::make('meta.phone')
                                            ->label('Phone')
                                            ->getStateUsing(function ($record) {
                                                if (! $record) { return null; }
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'phone') ?: ($record->user->phone ?? null);
                                            }),
                                        TextEntry::make('created_at')
                                            ->label('Created')
                                            ->dateTime('d-m-Y H:i'),
                                        TextEntry::make('patient_address_block')
                                            ->label('Home address')
                                            ->columnSpan(1)
                                            ->getStateUsing(function ($record) {
                                                if (! $record) { return null; }
                                                $pick = function (array $arr, array $keys) {
                                                    foreach ($keys as $k) {
                                                        $v = data_get($arr, $k);
                                                        if (is_string($v) && trim($v) !== '') return trim($v);
                                                    }
                                                    return null;
                                                };

                                                // 1) Meta-first lookup across common shapes
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                $a1 = $pick($meta, [
                                                    'address1','address_1','patient.address1','patient.address_1',
                                                    'billingAddress.address1','billingAddress.address_1','shippingAddress.address1','shippingAddress.address_1',
                                                    'address.line1','address.line_1','line1','line_1',
                                                ]);
                                                $a2 = $pick($meta, [
                                                    'address2','address_2','patient.address2','patient.address_2',
                                                    'billingAddress.address2','billingAddress.address_2','shippingAddress.address2','shippingAddress.address_2',
                                                    'address.line2','address.line_2','line2','line_2',
                                                ]);
                                                $city = $pick($meta, [
                                                    'city','town','patient.city','patient.town',
                                                    'billingAddress.city','shippingAddress.city',
                                                    'address.city',
                                                ]);
                                                $pc = $pick($meta, [
                                                    'postcode','post_code','zip','zip_code','patient.postcode','patient.post_code',
                                                    'billingAddress.postcode','shippingAddress.postcode',
                                                    'address.postcode',
                                                ]);
                                                $country = $pick($meta, [
                                                    'country','patient.country','billingAddress.country','shippingAddress.country','address.country',
                                                ]);

                                                // 2) If mostly empty, fall back to related user's address fields if present
                                                if (!$a1 && optional($record->user)) {
                                                    $u = $record->user;
                                                    $a1 = $a1 ?: ($u->address1 ?? $u->address_1 ?? null);
                                                    $a2 = $a2 ?: ($u->address2 ?? $u->address_2 ?? null);
                                                    $city = $city ?: ($u->city ?? $u->town ?? null);
                                                    $pc = $pc ?: ($u->postcode ?? $u->post_code ?? $u->zip ?? null);
                                                    $country = $country ?: ($u->country ?? null);
                                                }

                                                $lines = array_values(array_filter([
                                                    $a1,
                                                    $a2,
                                                    trim(trim((string)$city) . ' ' . trim((string)$pc)) ?: null,
                                                    $country,
                                                ], fn($v) => is_string($v) && $v !== ''));

                                                if (empty($lines)) {
                                                    return '—';
                                                }

                                                return implode("\n", $lines);
                                            })
                                            ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : '—')
                                            ->html(),
                                        TextEntry::make('patient_shipping_address_block')
                                            ->label('Shipping address')
                                            ->columnSpan(1)
                                            ->getStateUsing(function ($record) {
                                                if (! $record) { return null; }
                                                $pick = function (array $arr, array $keys) {
                                                    foreach ($keys as $k) {
                                                        $v = data_get($arr, $k);
                                                        if (is_string($v) && trim($v) !== '') return trim($v);
                                                    }
                                                    return null;
                                                };

                                                // Prefer explicit shipping or delivery shapes
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                $a1 = $pick($meta, [
                                                    'shippingAddress.address1','shippingAddress.address_1','deliveryAddress.address1','deliveryAddress.address_1',
                                                    'shipping.address1','shipping.address_1','delivery.address1','delivery.address_1',
                                                    'address.shipping.line1','address.delivery.line1','shipping.line1','delivery.line1',
                                                ]);
                                                $a2 = $pick($meta, [
                                                    'shippingAddress.address2','shippingAddress.address_2','deliveryAddress.address2','deliveryAddress.address_2',
                                                    'shipping.address2','shipping.address_2','delivery.address2','delivery.address_2',
                                                    'address.shipping.line2','address.delivery.line2','shipping.line2','delivery.line2',
                                                ]);
                                                $city = $pick($meta, [
                                                    'shippingAddress.city','deliveryAddress.city','shipping.city','delivery.city',
                                                    'address.shipping.city','address.delivery.city',
                                                ]);
                                                $pc = $pick($meta, [
                                                    'shippingAddress.postcode','shippingAddress.post_code','deliveryAddress.postcode','deliveryAddress.post_code',
                                                    'shipping.postcode','delivery.postcode','shipping.zip','delivery.zip',
                                                    'address.shipping.postcode','address.delivery.postcode',
                                                ]);
                                                $country = $pick($meta, [
                                                    'shippingAddress.country','deliveryAddress.country','shipping.country','delivery.country',
                                                    'address.shipping.country','address.delivery.country',
                                                ]);

                                                // If not present, fall back to generic meta keys or user shipping fields
                                                if (!$a1) {
                                                    $a1 = $a1 ?: $pick($meta, ['address.shipping.line1','address.line1','line1','address1','address_1']);
                                                    $a2 = $a2 ?: $pick($meta, ['address.shipping.line2','address.line2','line2','address2','address_2']);
                                                    $city = $city ?: $pick($meta, ['address.shipping.city','address.city','city','town']);
                                                    $pc = $pc ?: $pick($meta, ['address.shipping.postcode','address.postcode','postcode','post_code','zip','zip_code']);
                                                    $country = $country ?: $pick($meta, ['address.shipping.country','address.country','country']);
                                                }

                                                // User fallbacks if available
                                                if (!$a1 && optional($record->user)) {
                                                    $u = $record->user;
                                                    // try common custom columns for shipping
                                                    $a1 = $a1 ?: ($u->shipping_address1 ?? $u->shipping_address_1 ?? null);
                                                    $a2 = $a2 ?: ($u->shipping_address2 ?? $u->shipping_address_2 ?? null);
                                                    $city = $city ?: ($u->shipping_city ?? null);
                                                    $pc = $pc ?: ($u->shipping_postcode ?? $u->shipping_post_code ?? $u->shipping_zip ?? null);
                                                    $country = $country ?: ($u->shipping_country ?? null);
                                                    // fall back to home if shipping not set
                                                    if (!$a1) {
                                                        $a1 = $u->address1 ?? $u->address_1 ?? null;
                                                        $a2 = $a2 ?: ($u->address2 ?? $u->address_2 ?? null);
                                                        $city = $city ?: ($u->city ?? $u->town ?? null);
                                                        $pc = $pc ?: ($u->postcode ?? $u->post_code ?? $u->zip ?? null);
                                                        $country = $country ?: ($u->country ?? null);
                                                    }
                                                }

                                                $lines = array_values(array_filter([
                                                    $a1,
                                                    $a2,
                                                    trim(trim((string)$city) . ' ' . trim((string)$pc)) ?: null,
                                                    $country,
                                                ], fn($v) => is_string($v) && $v !== ''));

                                                if (empty($lines)) {
                                                    return '—';
                                                }

                                                return implode("\n", $lines);
                                            })
                                            ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : '—')
                                            ->html(),
                                    ]),
                                ]),
                            // RIGHT COLUMN: stack Payment Details over Appointment Date & Time
                            Grid::make(1)
                                ->columnSpan(4)
                                ->schema([
                                    Section::make('Payment Details')
                                        ->schema([
                                            TextEntry::make('payment_status')
                                                ->label('Payment')
                                                ->formatStateUsing(function ($state) {
                                                    $s = strtolower((string) $state);
                                                    return $s === 'paid' ? 'Paid' : ($s === 'unpaid' ? 'Unpaid' : ($s === 'refunded' ? 'Refunded' : ucfirst((string) $state)));
                                                })
                                                ->badge()
                                                ->color(function ($state) {
                                                    $s = strtolower((string) $state);
                                                    return match ($s) {
                                                        'paid' => 'success',
                                                        'unpaid' => 'warning',
                                                        'refunded' => 'danger',
                                                        default => 'gray',
                                                    };
                                                }),
                                        ]),
                                    Section::make('Verification')
                                        ->schema([
                                            \Filament\Schemas\Components\Grid::make(2)->schema([
                                                TextEntry::make('scr_verified_badge')
                                                    ->label('SCR')
                                                    ->getStateUsing(function ($record) {
                                                        if (! $record) { return '—'; }

                                                        $norm = function ($v) {
                                                            if (in_array($v, [true, 1, '1', 'true', 'yes', 'YES', 'Yes'], true)) return 'Yes';
                                                            if (in_array($v, [false, 0, '0', 'false', 'no', 'NO', 'No'], true)) return 'No';
                                                            return null;
                                                        };

                                                        // 1) Pending order meta
                                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                        $v = $norm(data_get($meta, 'scr_verified'));
                                                        if ($v !== null) return $v;

                                                        // 2) User meta / column
                                                        try {
                                                            $u = $record->user ?? null;
                                                            if ($u) {
                                                                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                                                    $um = is_array($u->meta) ? $u->meta : (json_decode($u->meta ?? '[]', true) ?: []);
                                                                    $uv = $norm(data_get($um, 'scr_verified'));
                                                                    if ($uv !== null) return $uv;
                                                                }
                                                                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'scr_verified')) {
                                                                    $flat = $norm($u->scr_verified ?? null);
                                                                    if ($flat !== null) return $flat;
                                                                }
                                                            }
                                                        } catch (\Throwable $e) {}

                                                        // 3) Linked order by reference
                                                        try {
                                                            $ord = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                                            if ($ord) {
                                                                $om = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);
                                                                $ov = $norm(data_get($om, 'scr_verified'));
                                                                if ($ov !== null) return $ov;
                                                            }
                                                        } catch (\Throwable $e) {}

                                                        return '—';
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

                                                TextEntry::make('id_verified_badge')
                                                    ->label('ID')
                                                    ->getStateUsing(function ($record) {
                                                        if (! $record) { return '—'; }

                                                        $norm = function ($v) {
                                                            // Treat any "yes" style value as Yes
                                                            if (in_array($v, [true, 1, '1', 'true', 'yes', 'YES', 'Yes'], true)) {
                                                                return 'Yes';
                                                            }

                                                            // Only treat an explicit string "no" as No (not generic falsy like 0 / false)
                                                            if (in_array($v, ['no', 'NO', 'No'], true)) {
                                                                return 'No';
                                                            }

                                                            // Anything else is treated as unknown and will fall back to "—"
                                                            return null;
                                                        };

                                                        // 1) Pending order meta
                                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                        $v = $norm(data_get($meta, 'id_verified'));
                                                        if ($v !== null) return $v;

                                                        // 2) User meta / column
                                                        try {
                                                            $u = $record->user ?? null;
                                                            if ($u) {
                                                                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                                                    $um = is_array($u->meta) ? $u->meta : (json_decode($u->meta ?? '[]', true) ?: []);
                                                                    $uv = $norm(data_get($um, 'id_verified'));
                                                                    if ($uv !== null) return $uv;
                                                                }
                                                                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'id_verified')) {
                                                                    $flat = $norm($u->id_verified ?? null);
                                                                    if ($flat !== null) return $flat;
                                                                }
                                                            }
                                                        } catch (\Throwable $e) {}

                                                        // 3) Linked order by reference
                                                        try {
                                                            $ord = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                                            if ($ord) {
                                                                $om = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);
                                                                $ov = $norm(data_get($om, 'id_verified'));
                                                                if ($ov !== null) return $ov;
                                                            }
                                                        } catch (\Throwable $e) {}

                                                        return '—';
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
                                    Section::make('Appointment Date & Time')
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('appointment_datetime')->hiddenLabel(),
                                    ]),
                                ]),
                        ]),
                        Section::make('Admin notes')
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('meta.admin_notes')
                                    ->hiddenLabel()
                                    ->getStateUsing(function ($record) {
                                        if (! $record) {
                                            return '—';
                                        }
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        return (string) (data_get($meta, 'admin_notes') ?: '—');
                                    })
                                    ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                    ->extraAttributes(function ($record) {
                                        $ts = optional(optional($record)->updated_at)->timestamp ?? time();
                                        $key = optional($record)->getKey() ?? '0';
                                        return ['wire:key' => 'pending-admin-notes-' . $key . '-' . $ts];
                                    })
                                    ->html(),
                            ]),

                        // Order history (collapsible section like Products and Assessment Answers)
                        Section::make('Order history')
                            ->collapsible()
                            ->collapsed(false)
                            ->schema([
                                ViewEntry::make('order_history_view')
                                    ->hiddenLabel()
                                    ->view('filament.partials.order-history-table')
                                    ->getStateUsing(function ($record) {
                                        if (! $record) return ['rows' => []];

                                        $uid = $record->user_id ?: optional($record->user)->id;
                                        $pid = $record->patient_id;

                                        $q = \App\Models\Order::query()
                                            ->where('status', 'completed')
                                            ->where(function ($w) use ($uid, $pid) {
                                                if ($uid) {
                                                    $w->orWhere('user_id', $uid)
                                                    ->orWhereRaw("JSON_EXTRACT(meta, '$.user_id') = ?", [$uid])
                                                    ->orWhereRaw("JSON_EXTRACT(meta, '$.user.id') = ?", [$uid]);
                                                }
                                                if ($pid && \Schema::hasColumn('orders', 'patient_id')) {
                                                    $w->orWhere('patient_id', $pid)
                                                    ->orWhereRaw("JSON_EXTRACT(meta, '$.patient_id') = ?", [$pid])
                                                    ->orWhereRaw("JSON_EXTRACT(meta, '$.patient.id') = ?", [$pid]);
                                                }
                                            });

                                        $orders = $q->latest('id')->limit(25)->get();

                                        $money = function ($o) {
                                            if (isset($o->products_total_minor) && is_numeric($o->products_total_minor)) {
                                                return '£' . number_format(((int) $o->products_total_minor) / 100, 2);
                                            }
                                            $m = is_array($o->meta) ? $o->meta : (json_decode($o->meta ?? '[]', true) ?: []);
                                            $val = data_get($m, 'products_total_minor') ?? data_get($m, 'totalMinor') ?? data_get($m, 'amountMinor');
                                            if (is_numeric($val)) return '£' . number_format(((int) $val) / 100, 2);
                                            $sum = 0;
                                            foreach ((array) (data_get($m, 'items') ?? data_get($m, 'lines') ?? []) as $it) {
                                                if (!is_array($it)) continue;
                                                $qty   = (int) ($it['qty'] ?? $it['quantity'] ?? 1) ?: 1;
                                                $minor = $it['totalMinor'] ?? $it['lineTotalMinor'] ?? $it['amountMinor'] ?? null;
                                                if ($minor === null && isset($it['unitMinor'])) $minor = (int) $it['unitMinor'] * $qty;
                                                if (is_numeric($minor)) $sum += (int) $minor;
                                            }
                                            return $sum > 0 ? '£' . number_format($sum / 100, 2) : '—';
                                        };

                                        $fmtDate = fn($dt) => tap($dt, function (&$x) {
                                            try { $x = \Carbon\Carbon::parse($x)->format('d-m-Y H:i'); } catch (\Throwable) {}
                                        });

                                        $itemsSummary = function ($o) {
                                            $m = is_array($o->meta) ? $o->meta : (json_decode($o->meta ?? '[]', true) ?: []);
                                            $cands = [data_get($m, 'items'), data_get($m, 'lines'), data_get($m, 'products'), data_get($m, 'line_items')];
                                            $items = collect($cands)->first(fn($c) => is_array($c) && count($c)) ?? [];

                                            if (empty($items)) {
                                                $name = data_get($m, 'product_name') ?? data_get($m, 'selectedProduct.name');
                                                if (!$name) return '—';
                                                $qty = (int) (data_get($m, 'qty') ?? data_get($m, 'quantity') ?? 1) ?: 1;
                                                $opt = data_get($m, 'selectedProduct.variations') ?? data_get($m, 'selectedProduct.optionLabel')
                                                    ?? data_get($m, 'variant') ?? data_get($m, 'dose') ?? data_get($m, 'strength');
                                                if (is_array($opt)) $opt = ($opt['label'] ?? $opt['value'] ?? '');
                                                return e(trim("$qty × $name" . ($opt ? " $opt" : '')));
                                            }

                                            $labels = [];
                                            foreach ($items as $it) {
                                                $qty = (int) ($it['qty'] ?? $it['quantity'] ?? 1) ?: 1;
                                                $name = $it['name'] ?? $it['title'] ?? $it['product_name'] ?? 'Item';
                                                $opt = data_get($it, 'variations') ?? data_get($it, 'variation') ?? data_get($it, 'optionLabel')
                                                    ?? data_get($it, 'variant') ?? data_get($it, 'dose') ?? data_get($it, 'strength') ?? data_get($it, 'option');
                                                if (is_array($opt)) $opt = $opt['label'] ?? $opt['value'] ?? implode(' ', array_filter(array_map('strval', $opt)));
                                                $labels[] = e(trim("$qty × $name" . ($opt ? " $opt" : '')));
                                                if (count($labels) >= 2) break;
                                            }
                                            $html = implode('<br>', $labels);
                                            $more = max(0, count($items) - 2);
                                            if ($more) $html .= "<br><span class=\"oh-more\">+{$more} more</span>";
                                            return $html ?: '—';
                                        };
                                        
                                        $weightFromOrder = function ($o) {
                                            $m = is_array($o->meta) ? $o->meta : (json_decode($o->meta ?? '[]', true) ?: []);

                                            $direct = [
                                                data_get($m, 'weight'),
                                                data_get($m, 'weight_kg'),
                                                data_get($m, 'current_weight'),
                                                data_get($m, 'body_weight'),
                                                data_get($m, 'patient_weight'),
                                                data_get($m, 'raf.weight'),
                                                data_get($m, 'raf.weight_kg'),
                                                data_get($m, 'riskAssessment.weight'),
                                                data_get($m, 'riskAssessment.weight_kg'),
                                            ];

                                            foreach ($direct as $v) {
                                                if ($v === null) continue;
                                                $s = trim((string) $v);
                                                if ($s !== '') return e($s);
                                            }

                                            $qa = data_get($m, 'formsQA.raf.qa');
                                            if (is_array($qa)) {
                                                foreach ($qa as $row) {
                                                    $k = strtolower(trim((string) ($row['key'] ?? '')));
                                                    $q = strtolower(trim((string) ($row['question'] ?? '')));
                                                    $looksWeight =
                                                        (str_contains($k, 'weight') || str_contains($q, 'weight')) &&
                                                        !str_contains($k, 'target') &&
                                                        !str_contains($k, 'goal') &&
                                                        !str_contains($q, 'target') &&
                                                        !str_contains($q, 'goal');

                                                    if (! $looksWeight) continue;

                                                    $a = $row['answer'] ?? $row['raw'] ?? null;
                                                    if ($a === null) continue;

                                                    $out = is_array($a)
                                                        ? trim(implode(', ', array_filter(array_map('strval', $a))))
                                                        : trim((string) $a);

                                                    if ($out !== '') return e($out);
                                                }
                                            }

                                            $ra = data_get($m, 'riskAssessment') ?? data_get($m, 'raf');
                                            if (is_array($ra)) {
                                                foreach ($ra as $it) {
                                                    if (!is_array($it)) continue;
                                                    $key = strtolower(trim((string) ($it['key'] ?? $it['label'] ?? $it['question'] ?? '')));
                                                    if (!str_contains($key, 'weight')) continue;
                                                    if (str_contains($key, 'target') || str_contains($key, 'goal')) continue;

                                                    $val = $it['value'] ?? $it['answer'] ?? $it['raw'] ?? $it['response'] ?? null;
                                                    if ($val === null) continue;

                                                    $out = is_array($val)
                                                        ? trim(implode(', ', array_filter(array_map('strval', $val))))
                                                        : trim((string) $val);

                                                    if ($out !== '') return e($out);
                                                }
                                            }

                                            return '—';
                                        };

                                        $rows = [];
                                        foreach ($orders as $o) {
                                            $rows[] = [
                                                'ref'     => e($o->reference ?? ('#' . $o->id)),
                                                'created' => $fmtDate($o->created_at) ?? '',
                                                'items'   => $itemsSummary($o), // already escaped with <br>
                                                'weight'  => $weightFromOrder($o),
                                                'total'   => $money($o),
                                                'url'     => "/admin/orders/completed-orders/{$o->id}/details",
                                            ];
                                        }

                                        return ['rows' => $rows];
                                    }),
                            ]),
                        // Products (from order meta or booking relation)
                        Section::make(function ($record) {
                            if (! $record) {
                                return 'Products';
                            }
                            // Build normalized products array to derive a dynamic title like: Products (N)
                            $items = [];
                            $candidates = [
                                'meta.items',
                                'meta.lines',
                                'meta.products',
                                'meta.line_items',
                                'meta.cart.items',
                            ];
                            foreach ($candidates as $path) {
                                $arr = data_get($record, $path);
                                if (is_array($arr) && count($arr)) { $items = $arr; break; }
                            }
                            $count = is_countable($items) ? count($items) : 0;
                            return $count ? 'Products (' . $count . ')' : 'Products';
                        })
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                RepeatableEntry::make('products')
                                    ->hiddenLabel()
                                    ->getStateUsing(function ($record) {
                                        if (! $record) {
                                            return [];
                                        }
                                        // Normalize various meta shapes into a uniform array of { name, qty, priceMinor }
                                        $items = null;
                                        $candidates = [
                                            'meta.items',
                                            'meta.lines',
                                            'meta.products',
                                            'meta.line_items',
                                            'meta.cart.items',
                                        ];
                                        foreach ($candidates as $path) {
                                            $arr = data_get($record, $path);
                                            if (is_array($arr) && count($arr)) { $items = $arr; break; }
                                        }
                                        // If we have a single associative product object, wrap it so we treat it as one item
                                        if (is_array($items)) {
                                            $isList = array_keys($items) === range(0, count($items) - 1);
                                            if (
                                                !$isList &&
                                                (isset($items['name']) || isset($items['title']) || isset($items['product_name']))
                                            ) {
                                                $items = [$items];
                                            }
                                        }
                                        // Cache item count for downstream fallbacks
                                        $__itemsCount = is_array($items) ? count($items) : 0;
                                        if (!is_array($items) || empty($items)) return [];

                                        $out = [];
                                        foreach ($items as $it) {
                                            if (is_string($it)) {
                                                $out[] = [
                                                    'name'       => (string) $it,
                                                    'variation'  => '',
                                                    'qty'        => 1,
                                                    'priceMinor' => null,
                                                    'price'      => null,
                                                ];
                                                continue;
                                            }
                                            if (!is_array($it)) continue;

                                            $name = $it['name'] ?? ($it['title'] ?? 'Item');
                                            $qty = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                                            if ($qty < 1) $qty = 1;

                                            // Enhanced variation resolution, supports nested keys and arrays
                                            $resolveVar = function ($row) {
                                                $keys = [
                                                    'variations', 'variation', 'optionLabel', 'variant', 'dose', 'strength', 'option',
                                                    'meta.variations', 'meta.variation', 'meta.optionLabel', 'meta.variant', 'meta.dose', 'meta.strength', 'meta.option',
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
                                                return '';
                                            };
                                            $variation = $resolveVar($it);
                                            if (($variation === '' || $variation === null) && $__itemsCount === 1) {
                                                $metaRoot = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                $variation = data_get($metaRoot, 'selectedProduct.variations')
                                                    ?? data_get($metaRoot, 'selected_product.variations')
                                                    ?? data_get($metaRoot, 'selectedProduct.variation')
                                                    ?? data_get($metaRoot, 'selected_product.variation')
                                                    ?? data_get($metaRoot, 'selectedProduct.optionLabel')
                                                    ?? data_get($metaRoot, 'selected_product.optionLabel')
                                                    ?? data_get($metaRoot, 'selectedProduct.variant')
                                                    ?? data_get($metaRoot, 'selected_product.variant')
                                                    ?? data_get($metaRoot, 'selectedProduct.dose')
                                                    ?? data_get($metaRoot, 'selected_product.dose')
                                                    ?? data_get($metaRoot, 'selectedProduct.strength')
                                                    ?? data_get($metaRoot, 'selected_product.strength')
                                                    ?? data_get($metaRoot, 'variant')
                                                    ?? data_get($metaRoot, 'dose')
                                                    ?? data_get($metaRoot, 'strength')
                                                    ?? data_get($metaRoot, 'variation')
                                                    ?? data_get($metaRoot, 'option')
                                                    ?? '';
                                                if (is_array($variation)) {
                                                    // normalize array/assoc to string
                                                    $variation = is_string($variation['label'] ?? null) ? $variation['label']
                                                        : (is_string($variation['value'] ?? null) ? $variation['value'] : trim(implode(' ', array_map('strval', $variation))));
                                                }
                                            }

                                            // Helper to parse a money string like "£24.99", "24.99", "24", "24,99" into minor units
                                            $parseMoneyToMinor = function ($value) {
                                                if ($value === null || $value === '') return null;
                                                if (is_int($value)) return $value; // assume already in minor
                                                if (is_float($value)) return (int) round($value * 100);
                                                if (is_string($value)) {
                                                    $s = trim($value);
                                                    // remove currency symbols and spaces
                                                    $s = preg_replace('/[^\d\.\,\-]/', '', $s);
                                                    // convert comma decimal to dot if needed
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

                                            // Try common minor-unit keys first
                                            $minorCandidates = [
                                                // common line totals
                                                'lineTotalMinor', 'line_total_minor', 'line_total_pennies', 'lineTotalPennies',
                                                // generic totals
                                                'totalMinor', 'total_minor', 'totalPennies', 'total_pennies',
                                                'amountMinor', 'amount_minor',
                                                'subtotalMinor', 'subtotal_minor',
                                                // explicit price minor keys
                                                'priceMinor', 'price_minor', 'priceInMinor', 'priceInPence', 'price_in_minor', 'price_in_pence',
                                                // unit minors that need multiplying by qty
                                                'unitMinor', 'unit_minor', 'unitPriceMinor', 'unit_price_minor', 'unitPricePennies', 'unit_price_pennies',
                                                // other variations we have seen
                                                'minor', 'pennies', 'value_minor', 'valueMinor',
                                            ];
                                            $unitMinor = null;
                                            $priceMinor = null;
                                            foreach ($minorCandidates as $key) {
                                                if (array_key_exists($key, $it) && $it[$key] !== null && $it[$key] !== '') {
                                                    $val = $it[$key];
                                                    // unit* keys are per-unit prices
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

                                            // If still null, try major-unit keys and strings
                                            if ($priceMinor === null) {
                                                $majorCandidates = [
                                                    // line totals first
                                                    'lineTotal', 'line_total', 'linePrice', 'line_price', 'line_total_price',
                                                    // generic totals
                                                    'total', 'amount', 'subtotal',
                                                    // price values
                                                    'price', 'cost',
                                                    // unit prices that must be multiplied
                                                    'unitPrice', 'unit_price', 'unitCost', 'unit_cost',
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

                                            // Final fallback: look for nested money fields
                                            if ($priceMinor === null) {
                                                $maybe = data_get($it, 'money.totalMinor')
                                                     ?? data_get($it, 'money.amountMinor')
                                                     ?? data_get($it, 'money.subtotalMinor')
                                                     ?? data_get($it, 'money.lineTotalMinor')
                                                     ?? data_get($it, 'pricing.totalMinor')
                                                     ?? data_get($it, 'pricing.amountMinor')
                                                     ?? data_get($it, 'pricing.subtotalMinor')
                                                     ?? data_get($it, 'price.minor')
                                                     ?? data_get($it, 'total.minor')
                                                     ?? data_get($it, 'subtotal.minor')
                                                     ?? data_get($it, 'price.pennies')
                                                     ?? data_get($it, 'total.pennies')
                                                     ?? data_get($it, 'subtotal.pennies');
                                                if ($priceMinor === null && is_numeric($maybe)) {
                                                    $priceMinor = (int) $maybe;
                                                }
                                                // If only a major nested value exists, parse and multiply if unit price
                                                if ($priceMinor === null) {
                                                    $maybeMajor = data_get($it, 'money.total')
                                                               ?? data_get($it, 'pricing.total')
                                                               ?? data_get($it, 'price.value')
                                                               ?? data_get($it, 'total.value')
                                                               ?? data_get($it, 'subtotal.value');
                                                    $unitMaybeMajor = data_get($it, 'money.unit')
                                                                  ?? data_get($it, 'pricing.unit')
                                                                  ?? data_get($it, 'price.unit');
                                                    $parsed = $parseMoneyToMinor($maybeMajor);
                                                    if ($parsed !== null) $priceMinor = $parsed;
                                                    if ($priceMinor === null) {
                                                        $unitParsed = $parseMoneyToMinor($unitMaybeMajor);
                                                        if ($unitParsed !== null) $priceMinor = (int) $unitParsed * $qty;
                                                    }
                                                }
                                            }

                                            // Allow string numeric values for totalMinor/unitMinor
                                            if ($priceMinor === null && array_key_exists('totalMinor', $it) && $it['totalMinor'] !== null && $it['totalMinor'] !== '') {
                                                $priceMinor = (int) $it['totalMinor'];
                                            }

                                            // As a very last resort, if we still don't have a per-line price, but the item has an 'amountMinor'
                                            // or 'amount' key that looks like the per-line price, try those:
                                            if ($priceMinor === null) {
                                                if (array_key_exists('amountMinor', $it) && $it['amountMinor'] !== null && $it['amountMinor'] !== '') {
                                                    $priceMinor = (int) $it['amountMinor'] * $qty;
                                                } elseif (array_key_exists('amount', $it) && $it['amount'] !== null && $it['amount'] !== '') {
                                                    $maybe = $parseMoneyToMinor($it['amount']);
                                                    if ($maybe !== null) $priceMinor = (int) $maybe * $qty;
                                                }
                                            }

                                            if ($priceMinor === null && $unitMinor !== null) {
                                                $priceMinor = (int) $unitMinor * $qty;
                                            }

                                            // FINAL SINGLE-ITEM FALLBACK: if no per-line price could be derived, but the order has a total and there is exactly one item, use the order total
                                            if ($priceMinor === null && $__itemsCount === 1) {
                                                $orderTotalMinor = data_get($record, 'meta.totalMinor')
                                                    ?? data_get($record, 'meta.amountMinor')
                                                    ?? data_get($record, 'meta.total_minor')
                                                    ?? data_get($record, 'meta.amount_minor');
                                                if (is_numeric($orderTotalMinor)) {
                                                    $priceMinor = (int) $orderTotalMinor;
                                                }
                                            }

                                            $displayPrice = (is_numeric($priceMinor) ? ('£' . number_format(((int) $priceMinor) / 100, 2)) : '—');

                                            $out[] = [
                                                'name'           => (string) $name,
                                                'variation'      => (string) $variation,
                                                'qty'            => $qty,
                                                'priceMinor'     => $priceMinor,
                                                'price'          => $priceMinor, // alias retained for compatibility
                                                'priceFormatted' => $displayPrice,
                                            ];
                                        }
                                        return $out;
                                    })
                                    ->schema([
                                        Grid::make(12)->schema([
                                            TextEntry::make('name')
                                                ->label('Product')
                                                ->columnSpan(6),

                                            TextEntry::make('variation')
                                                ->label('Variation')
                                                ->formatStateUsing(fn ($state) => $state ?: '—')
                                                ->columnSpan(3),

                                            TextEntry::make('qty')
                                                ->label('Qty')
                                                ->formatStateUsing(fn ($state) => (string) $state)
                                                ->columnSpan(1),

                                            TextEntry::make('priceFormatted')
                                                ->label('Price')
                                                ->columnSpan(2)
                                                ->formatStateUsing(fn ($state) => (string) $state)
                                                ->extraAttributes(['class' => 'text-right whitespace-nowrap']),
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
                                            ->label('') // no header over value cell
                                            ->hiddenLabel()
                                            ->columnSpan(2)
                                            ->getStateUsing(function ($record) {
                                                if (! $record) {
                                                    return 0;
                                                }
                                                return (int) ($record->products_total_minor ?? 0);
                                            })
                                            ->formatStateUsing(fn ($state) => '£' . number_format(((int) $state) / 100, 2))
                                            ->placeholder('£0.00')
                                            ->extraAttributes(['class' => 'text-right tabular-nums']),
                                    ]),
                            ]),

                        // Consultation QA snapshot (RAF only — rendered via Blade)
                        Section::make('Assessment Answers')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                ViewEntry::make('consultation_qa')
                                    ->label(false)
                                    ->getStateUsing(function ($record) {
                                        if (! $record) {
                                            return [];
                                        }
                                        // First try formsQA from the PendingOrder meta
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        $forms = data_get($meta, 'formsQA', []);

                                        // If empty, fall back to the real Order row by reference
                                        if (empty($forms)) {
                                            try {
                                                $order = Order::where('reference', $record->reference)->first();
                                                if ($order) {
                                                    $ometa = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                                    $forms = data_get($ometa, 'formsQA', []);
                                                }
                                            } catch (Throwable $e) {
                                                // ignore
                                            }
                                        }

                                        return $forms;
                                    })
                                    ->view('filament.pending-orders.consultation-qa')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->extraModalFooterActions([
                        Action::make('set_priority')
                            ->label('Priority')
                            ->icon('heroicon-o-flag')
                            ->color('warning')
                            ->modalHeading('Set patient priority')
                            ->form([
                                Select::make('priority')
                                    ->options([
                                        'red' => 'Red',
                                        'yellow' => 'Yellow',
                                        'green' => 'Green',
                                    ])
                                    ->required()
                                    ->default(function (\App\Models\PendingOrder $record) {
                                        $u = optional($record->user);
                                        return $u?->priority ?: 'green';
                                    })
    
                            ])
                            ->action(function (\App\Models\PendingOrder $record, \Filament\Actions\Action $action, array $data) {
                                $prio = $data['priority'] ?? null;

                                if (!in_array($prio, ['red','yellow','green'], true)) {
                                    \Filament\Notifications\Notification::make()
                                        ->danger()
                                        ->title('Choose a valid priority')
                                        ->send();
                                    return;
                                }

                                // Persist on the patient
                                try {
                                    if ($record->user) {
                                        $record->user->forceFill(['priority' => $prio])->save();
                                    }
                                } catch (\Throwable $e) {
                                    // ignore
                                }

                                // Also copy onto this pending order so the UI reflects immediately
                                try {
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                    $meta['priority'] = $prio;
                                    $record->forceFill(['meta' => $meta])->save();
                                } catch (\Throwable $e) {
                                    // ignore
                                }

                                \Filament\Notifications\Notification::make()
                                    ->success()
                                    ->title('Priority updated')
                                    ->send();

                                // Refresh the table/modal
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                            }),
                        Action::make('setScrVerified')
                            ->label('SCR Verified')
                            ->color('gray')
                            ->icon('heroicon-o-check-badge')
                            ->visible(function ($record) {
                                try {
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                    $slug = data_get($meta, 'service_slug')
                                        ?? data_get($meta, 'service.slug')
                                        ?? data_get($meta, 'consultation.service_slug');
                                    if (!is_string($slug) || strtolower($slug) !== 'weight-management') return false;

                                    $rawType = strtolower((string) (data_get($meta, 'type') ?? data_get($meta, 'mode') ?? data_get($meta, 'flow') ?? ''));
                                    $path    = strtolower((string) (data_get($meta, 'path') ?? data_get($meta, 'source_url') ?? data_get($meta, 'referer') ?? ''));
                                    $ref     = strtoupper((string) ($record->reference ?? ''));

                                    $isReorder = in_array($rawType, ['reorder','repeat','re-order','repeat-order'], true)
                                        || str_contains($path, '/reorder')
                                        || preg_match('/^PTC[A-Z]*R\d{6}$/', $ref);
                                    $isNew = ($rawType === 'new') || preg_match('/^PTC[A-Z]*N\d{6}$/', $ref);

                                    return $isNew && !$isReorder;
                                } catch (\Throwable $e) { return false; }
                            })
                            ->form([
                        Select::make('value')
                            ->label('Set SCR status')
                            ->options([
                                'yes' => 'Yes',
                                'no'  => 'No',
                            ])
                            ->required()
                            ->default(function ($record) {
                                $norm = function ($v) {
                                    if (in_array($v, [true, 1, '1', 'true', 'yes', 'YES', 'Yes'], true)) return 'yes';
                                    if (in_array($v, [false, 0, '0', 'false', 'no', 'NO', 'No'], true)) return 'no';
                                    return null;
                                };

                                // 1) Pending order meta
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                $v = $norm(data_get($meta, 'scr_verified'));
                                if ($v !== null) return $v;

                                // 2) User meta
                                try {
                                    $u = $record->user ?? null;
                                    if ($u) {
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                            $um = is_array($u->meta) ? $u->meta : (json_decode($u->meta ?? '[]', true) ?: []);
                                            $uv = $norm(data_get($um, 'scr_verified'));
                                            if ($uv !== null) return $uv;
                                        }
                                        // 3) User flat column
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'scr_verified')) {
                                            $flat = $norm($u->scr_verified ?? null);
                                            if ($flat !== null) return $flat;
                                        }
                                    }
                                } catch (\Throwable $e) {}

                                // 4) Linked order by reference
                                try {
                                    $ord = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                    if ($ord) {
                                        $om = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);
                                        $ov = $norm(data_get($om, 'scr_verified'));
                                        if ($ov !== null) return $ov;
                                    }
                                } catch (\Throwable $e) {}

                                return null; // no preselection
                            })
                            ])
                            ->action(function (PendingOrder $record, Action $action, array $data) {
                                $setYes = ($data['value'] ?? null) === 'yes';

                                // Update on PendingOrder
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                data_set($meta, 'scr_verified', $setYes);
                                if ($setYes) {
                                    data_set($meta, 'scr_verified_at', now()->toIso8601String());
                                } else {
                                    data_forget($meta, 'scr_verified_at');
                                }
                                $record->meta = $meta;
                                $record->save();
                                
                                // propagate to all of this patient's orders and pending orders
                                try {
                                    $userId = $record->user_id ?? null;
                                    if ($userId) {
                                        $flag   = 'scr_verified';   // change to 'id_verified' inside the ID action
                                        $setYes = ($data['value'] ?? null) === 'yes';
                                        $ts     = now()->toIso8601String();

                                        // Update all PendingOrders for this user
                                        \App\Models\PendingOrder::query()
                                            ->where('user_id', $userId)
                                            ->chunkById(200, function ($rows) use ($flag, $setYes, $ts) {
                                                foreach ($rows as $row) {
                                                    $m = is_array($row->meta) ? $row->meta : (json_decode($row->meta ?? '[]', true) ?: []);
                                                    data_set($m, $flag, $setYes);
                                                    if ($setYes) {
                                                        data_set($m, "{$flag}_at", $ts);
                                                    } else {
                                                        data_forget($m, "{$flag}_at");
                                                    }
                                                    $row->meta = $m;
                                                    $row->save();
                                                }
                                            });

                                        // Update all Orders for this user
                                        \App\Models\Order::query()
                                            ->where('user_id', $userId)
                                            ->chunkById(200, function ($rows) use ($flag, $setYes, $ts) {
                                                foreach ($rows as $row) {
                                                    $m = is_array($row->meta) ? $row->meta : (json_decode($row->meta ?? '[]', true) ?: []);
                                                    data_set($m, $flag, $setYes);
                                                    if ($setYes) {
                                                        data_set($m, "{$flag}_at", $ts);
                                                    } else {
                                                        data_forget($m, "{$flag}_at");
                                                    }
                                                    $row->meta = $m;
                                                    $row->save();
                                                }
                                            });
                                    }
                                } catch (\Throwable $e) {
                                    // keep UX smooth
                                }

                                // Mirror to the corresponding Order, if found by reference
                                try {
                                    $order = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                    if ($order) {
                                        $om = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                        data_set($om, 'scr_verified', $setYes);
                                        if ($setYes) {
                                            data_set($om, 'scr_verified_at', now()->toIso8601String());
                                        } else {
                                            data_forget($om, 'scr_verified_at');
                                        }
                                        $order->meta = $om;
                                        $order->save();
                                    }
                                } catch (\Throwable $e) {
                                    // swallow to keep UX smooth
                                }

                                // Persist on patient profile so future orders auto-populate
                                try {
                                    $user = $record->user ?? null;
                                    if ($user) {
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                            $um = is_array($user->meta) ? $user->meta : (json_decode($user->meta ?? '[]', true) ?: []);
                                            data_set($um, 'scr_verified', $setYes);
                                            if ($setYes) {
                                                data_set($um, 'scr_verified_at', now()->toIso8601String());
                                            } else {
                                                data_forget($um, 'scr_verified_at');
                                            }
                                            $user->meta = $um;
                                            $user->save();
                                        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('users', 'scr_verified')) {
                                            $user->scr_verified = $setYes ? 1 : 0;
                                            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'scr_verified_at')) {
                                                $user->scr_verified_at = $setYes ? now() : null;
                                            }
                                            $user->save();
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    // swallow
                                }

                                $action->success();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                            }),
                        Action::make('setIdVerified')
                            ->label('ID Verified')
                            ->color('gray')
                            ->icon('heroicon-o-identification')
                            ->visible(function ($record) {
                                try {
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                    $slug = data_get($meta, 'service_slug')
                                        ?? data_get($meta, 'service.slug')
                                        ?? data_get($meta, 'consultation.service_slug');
                                    if (!is_string($slug) || strtolower($slug) !== 'weight-management') return false;

                                    $rawType = strtolower((string) (data_get($meta, 'type') ?? data_get($meta, 'mode') ?? data_get($meta, 'flow') ?? ''));
                                    $path    = strtolower((string) (data_get($meta, 'path') ?? data_get($meta, 'source_url') ?? data_get($meta, 'referer') ?? ''));
                                    $ref     = strtoupper((string) ($record->reference ?? ''));

                                    $isReorder = in_array($rawType, ['reorder','repeat','re-order','repeat-order'], true)
                                        || str_contains($path, '/reorder')
                                        || preg_match('/^PTC[A-Z]*R\d{6}$/', $ref);
                                    $isNew = ($rawType === 'new') || preg_match('/^PTC[A-Z]*N\d{6}$/', $ref);

                                    return $isNew && !$isReorder;
                                } catch (\Throwable $e) { return false; }
                            })
                            ->form([
                                Select::make('value')
                                    ->label('Set ID status')
                                    ->options([
                                        'yes' => 'Yes',
                                        'no'  => 'No',
                                    ])
                                    ->required()
                                    ->default(function ($record) {
                                        $norm = function ($v) {
                                            if (in_array($v, [true, 1, '1', 'true', 'yes', 'YES', 'Yes'], true)) return 'yes';
                                            if (in_array($v, [false, 0, '0', 'false', 'no', 'NO', 'No'], true)) return 'no';
                                            return null;
                                        };

                                        // 1 pending order meta
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        $v = $norm(data_get($meta, 'id_verified'));
                                        if ($v !== null) return $v;

                                        // 2 attached user
                                        try {
                                            $u = $record->user ?? null;
                                            if ($u) {
                                                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                                    $um = is_array($u->meta) ? $u->meta : (json_decode($u->meta ?? '[]', true) ?: []);
                                                    $uv = $norm(data_get($um, 'id_verified'));
                                                    if ($uv !== null) return $uv;
                                                }
                                                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'id_verified')) {
                                                    $flat = $norm($u->id_verified ?? null);
                                                    if ($flat !== null) return $flat;
                                                }
                                            }
                                        } catch (\Throwable $e) {}

                                        // 3 resolve user by email in meta
                                        try {
                                            $email = null;
                                            foreach (['email','patient.email','customer.email','contact.email'] as $k) {
                                                $email = $email ?: data_get($meta, $k);
                                            }
                                            if (is_string($email) && trim($email) !== '') {
                                                $guess = \App\Models\User::where('email', trim($email))->first();
                                                if ($guess) {
                                                    if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                                        $gm = is_array($guess->meta) ? $guess->meta : (json_decode($guess->meta ?? '[]', true) ?: []);
                                                        $gv = $norm(data_get($gm, 'id_verified'));
                                                        if ($gv !== null) return $gv;
                                                    }
                                                    if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'id_verified')) {
                                                        $flat = $norm($guess->id_verified ?? null);
                                                        if ($flat !== null) return $flat;
                                                    }
                                                }
                                            }
                                        } catch (\Throwable $e) {}

                                        // 4 linked order by reference
                                        try {
                                            $ord = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                            if ($ord) {
                                                $om = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);
                                                $ov = $norm(data_get($om, 'id_verified'));
                                                if ($ov !== null) return $ov;
                                            }
                                        } catch (\Throwable $e) {}

                                        return null;
                                    }),
                            ])
                            ->action(function (\App\Models\PendingOrder $record, \Filament\Actions\Action $action, array $data) {
                                $setYes = ($data['value'] ?? null) === 'yes';

                                // pending order
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                data_set($meta, 'id_verified', $setYes);
                                if ($setYes) {
                                    data_set($meta, 'id_verified_at', now()->toIso8601String());
                                } else {
                                    data_forget($meta, 'id_verified_at');
                                }
                                $record->meta = $meta;
                                $record->save();

                                // propagate to all of this patient's orders and pending orders by user_id
                                try {
                                    $userId = $record->user_id ?? null;
                                    if ($userId) {
                                        $flag   = 'id_verified';
                                        $setYes = ($data['value'] ?? null) === 'yes';
                                        $ts     = now()->toIso8601String();

                                        \App\Models\PendingOrder::query()
                                            ->where('user_id', $userId)
                                            ->chunkById(200, function ($rows) use ($flag, $setYes, $ts) {
                                                foreach ($rows as $row) {
                                                    $m = is_array($row->meta) ? $row->meta : (json_decode($row->meta ?? '[]', true) ?: []);
                                                    data_set($m, $flag, $setYes);
                                                    if ($setYes) data_set($m, "{$flag}_at", $ts); else data_forget($m, "{$flag}_at");
                                                    $row->meta = $m;
                                                    $row->save();
                                                }
                                            });

                                        \App\Models\Order::query()
                                            ->where('user_id', $userId)
                                            ->chunkById(200, function ($rows) use ($flag, $setYes, $ts) {
                                                foreach ($rows as $row) {
                                                    $m = is_array($row->meta) ? $row->meta : (json_decode($row->meta ?? '[]', true) ?: []);
                                                    data_set($m, $flag, $setYes);
                                                    if ($setYes) data_set($m, "{$flag}_at", $ts); else data_forget($m, "{$flag}_at");
                                                    $row->meta = $m;
                                                    $row->save();
                                                }
                                            });
                                    }
                                } catch (\Throwable $e) {}

                                // also propagate by email for records without user_id
                                try {
                                    $metaForEmail = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                    $email = data_get($metaForEmail, 'email')
                                        ?? data_get($metaForEmail, 'patient.email')
                                        ?? data_get($metaForEmail, 'customer.email')
                                        ?? data_get($metaForEmail, 'contact.email')
                                        ?? optional($record->user)->email;

                                    if (is_string($email) && trim($email) !== '') {
                                        $email = trim($email);
                                        $flag   = 'id_verified';
                                        $setYes = ($data['value'] ?? null) === 'yes';
                                        $ts     = now()->toIso8601String();

                                        \App\Models\PendingOrder::query()
                                            ->where(function ($q) use ($email) {
                                                $q->where('email', $email)
                                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')) = ?", [$email])
                                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.email')) = ?", [$email])
                                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.customer.email')) = ?", [$email])
                                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.contact.email')) = ?", [$email]);
                                            })
                                            ->chunkById(200, function ($rows) use ($flag, $setYes, $ts) {
                                                foreach ($rows as $row) {
                                                    $m = is_array($row->meta) ? $row->meta : (json_decode($row->meta ?? '[]', true) ?: []);
                                                    data_set($m, $flag, $setYes);
                                                    if ($setYes) data_set($m, "{$flag}_at", $ts); else data_forget($m, "{$flag}_at");
                                                    $row->meta = $m;
                                                    $row->save();
                                                }
                                            });

                                        \App\Models\Order::query()
                                            ->where(function ($q) use ($email) {
                                                $q->where('email', $email)
                                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')) = ?", [$email])
                                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.email')) = ?", [$email])
                                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.customer.email')) = ?", [$email])
                                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.contact.email')) = ?", [$email]);
                                            })
                                            ->chunkById(200, function ($rows) use ($flag, $setYes, $ts) {
                                                foreach ($rows as $row) {
                                                    $m = is_array($row->meta) ? $row->meta : (json_decode($row->meta ?? '[]', true) ?: []);
                                                    data_set($m, $flag, $setYes);
                                                    if ($setYes) data_set($m, "{$flag}_at", $ts); else data_forget($m, "{$flag}_at");
                                                    $row->meta = $m;
                                                    $row->save();
                                                }
                                            });
                                    }
                                } catch (\Throwable $e) {}

                                // order mirror by reference
                                try {
                                    $order = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                    if ($order) {
                                        $om = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                        data_set($om, 'id_verified', $setYes);
                                        if ($setYes) {
                                            data_set($om, 'id_verified_at', now()->toIso8601String());
                                        } else {
                                            data_forget($om, 'id_verified_at');
                                        }
                                        $order->meta = $om;
                                        $order->save();
                                    }
                                } catch (\Throwable $e) {}

                                // persist on patient profile
                                try {
                                    $user = $record->user ?? null;
                                    if ($user) {
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                            $um = is_array($user->meta) ? $user->meta : (json_decode($user->meta ?? '[]', true) ?: []);
                                            data_set($um, 'id_verified', $setYes);
                                            if ($setYes) {
                                                data_set($um, 'id_verified_at', now()->toIso8601String());
                                            } else {
                                                data_forget($um, 'id_verified_at');
                                            }
                                            $user->meta = $um;
                                            $user->save();
                                        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('users', 'id_verified')) {
                                            $user->id_verified = $setYes ? 1 : 0;
                                            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'id_verified_at')) {
                                                $user->id_verified_at = $setYes ? now() : null;
                                            }
                                            $user->save();
                                        }
                                    }
                                } catch (\Throwable $e) {}

                                $action->success();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                            }),
                        Action::make('approve')
                            ->label('Approve')
                            ->color('success')
                            ->icon('heroicon-o-check')
                            ->action(function (PendingOrder $record, Action $action) {
                                // Gate approval for Weight Management NEW orders: require both SCR and ID choices
                                try {
                                    /** @var \App\Models\PendingOrder $record */
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                                    $slug = data_get($meta, 'service_slug')
                                        ?? data_get($meta, 'service.slug')
                                        ?? data_get($meta, 'consultation.service_slug');

                                    $rawType = strtolower((string) (data_get($meta, 'type') ?? data_get($meta, 'mode') ?? data_get($meta, 'flow') ?? ''));
                                    $path    = strtolower((string) (data_get($meta, 'path') ?? data_get($meta, 'source_url') ?? data_get($meta, 'referer') ?? ''));
                                    $ref     = strtoupper((string) ($record->reference ?? ''));

                                    $isReorder = in_array($rawType, ['reorder','repeat','re-order','repeat-order'], true)
                                        || str_contains($path, '/reorder')
                                        || preg_match('/^PTC[A-Z]*R\d{6}$/', $ref);
                                    $isNew = ($rawType === 'new') || preg_match('/^PTC[A-Z]*N\d{6}$/', $ref);

                                    if (is_string($slug) && strtolower($slug) === 'weight-management' && $isNew && !$isReorder) {
                                        $norm = function ($v) {
                                            if (in_array($v, [true, 1, '1', 'true', 'yes', 'YES', 'Yes'], true)) return 'yes';
                                            if (in_array($v, [false, 0, '0', 'false', 'no', 'NO', 'No'], true)) return 'no';
                                            return null;
                                        };

                                        // Resolve SCR
                                        $scr = $norm(data_get($meta, 'scr_verified'));
                                        if ($scr === null) {
                                            try {
                                                $ord = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                                if ($ord) {
                                                    $om = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);
                                                    $scr = $norm(data_get($om, 'scr_verified'));
                                                }
                                            } catch (\Throwable $e) { /* ignore */ }
                                        }
                                        if ($scr === null && $record->user) {
                                            try {
                                                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                                    $um = is_array($record->user->meta) ? $record->user->meta : (json_decode($record->user->meta ?? '[]', true) ?: []);
                                                    $scr = $norm(data_get($um, 'scr_verified'));
                                                }
                                                if ($scr === null && \Illuminate\Support\Facades\Schema::hasColumn('users', 'scr_verified')) {
                                                    $scr = $norm($record->user->scr_verified ?? null);
                                                }
                                            } catch (\Throwable $e) { /* ignore */ }
                                        }

                                        // Resolve ID
                                        $idv = $norm(data_get($meta, 'id_verified'));
                                        if ($idv === null) {
                                            try {
                                                $ord2 = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                                if ($ord2) {
                                                    $om2 = is_array($ord2->meta) ? $ord2->meta : (json_decode($ord2->meta ?? '[]', true) ?: []);
                                                    $idv = $norm(data_get($om2, 'id_verified'));
                                                }
                                            } catch (\Throwable $e) { /* ignore */ }
                                        }
                                        if ($idv === null && $record->user) {
                                            try {
                                                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                                    $um2 = is_array($record->user->meta) ? $record->user->meta : (json_decode($record->user->meta ?? '[]', true) ?: []);
                                                    $idv = $norm(data_get($um2, 'id_verified'));
                                                }
                                                if ($idv === null && \Illuminate\Support\Facades\Schema::hasColumn('users', 'id_verified')) {
                                                    $idv = $norm($record->user->id_verified ?? null);
                                                }
                                            } catch (\Throwable $e) { /* ignore */ }
                                        }

                                        $missing = [];
                                        if ($scr === null) $missing[] = 'SCR Verified';
                                        if ($idv === null) $missing[] = 'ID Verified';

                                        if (!empty($missing)) {
                                            \Filament\Notifications\Notification::make()
                                                ->danger()
                                                ->title('Please choose Yes or No for ' . implode(' and ', $missing) . ' before approving this order.')
                                                ->send();
                                            try { $action->getLivewire()->dispatch('$refresh'); $action->getLivewire()->dispatch('refreshTable'); } catch (\Throwable $e) {}
                                            return; // stop approval
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    \Filament\Notifications\Notification::make()
                                        ->danger()
                                        ->title('Verification check failed')
                                        ->body('Could not confirm SCR or ID selection. Please choose Yes or No and try again.')
                                        ->send();
                                    try { $action->getLivewire()->dispatch('$refresh'); $action->getLivewire()->dispatch('refreshTable'); } catch (\Throwable $ex) {}
                                    return;
                                }

                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                data_set($meta, 'approved_at', now()->toISOString());
                                $record->status = 'approved';
                                $record->meta = $meta;
                                $record->booking_status = 'approved';
                                $record->save();
                                // Also sync the real Order by reference so the frontend reflects the change
                                try {
                                    $order = Order::where('reference', $record->reference)->first();
                                    if ($order) {
                                        $orderMeta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                        // carry approved_at into order meta as well
                                        data_set($orderMeta, 'approved_at', now()->toISOString());

                                        $order->forceFill([
                                            'status'         => 'approved',
                                            'booking_status' => 'approved',
                                            'approved_at'    => now(),
                                            'meta'           => $orderMeta,
                                        ])->save();
                                    }
                                } catch (Throwable $e) {
                                    // swallow to avoid breaking the UI; consider logging if needed
                                }
                                // Email the patient that the order is approved once
                                try {
                                    $metaForEmail = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                    if (!data_get($metaForEmail, 'emailed.order_approved_at')) {
                                        // Find the related Order row
                                        $orderForEmail = \App\Models\Order::where('reference', $record->reference)->latest()->first();

                                        // Resolve recipient email from Order first, then meta, then user
                                        $orderMetaArr = [];
                                        if ($orderForEmail) {
                                            $orderMetaArr = is_array($orderForEmail->meta) ? $orderForEmail->meta : (json_decode($orderForEmail->meta ?? '[]', true) ?: []);
                                        }

                                        $to = $orderForEmail->email
                                            ?? data_get($orderMetaArr, 'customer.email')
                                            ?? data_get($metaForEmail, 'email')
                                            ?? data_get($metaForEmail, 'customer.email')
                                            ?? optional($record->user)->email;

                                        if ($to && $orderForEmail) {
                                            Mail::to($to)->queue(new OrderApprovedMail($orderForEmail));

                                            // Flag on both PendingOrder and Order to prevent duplicate sends
                                            data_set($metaForEmail, 'emailed.order_approved_at', now()->toIso8601String());
                                            $record->meta = $metaForEmail;
                                            $record->save();

                                            $om = $orderMetaArr;
                                            data_set($om, 'emailed.order_approved_at', now()->toIso8601String());
                                            $orderForEmail->meta = $om;
                                            $orderForEmail->save();
                                        }
                                    }
                                } catch (Throwable $e) {
                                    // swallow
                                }
                                // After syncing the Order, approve or create the matching appointment so it only shows once approved
                                try {
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                                    // Extract appointment window from meta
                                    $start = data_get($meta, 'appointment_start_at')
                                        ?? data_get($meta, 'appointment.start_at')
                                        ?? data_get($meta, 'appointment_at')
                                        ?? data_get($meta, 'booking.start_at');

                                    $end = data_get($meta, 'appointment_end_at')
                                        ?? data_get($meta, 'appointment.end_at')
                                        ?? data_get($meta, 'booking.end_at');

                                    // Build candidate service slugs
                                    $serviceCandidates = [];
                                    $sid = data_get($meta, 'consultation_session_id')
                                        ?? data_get($meta, 'consultation.sessionId');
                                    if ($sid) {
                                        $slug = DB::table('consultation_sessions')
                                            ->where('id', $sid)
                                            ->value('service_slug');
                                        if (is_string($slug) && $slug !== '') {
                                            $serviceCandidates[] = $slug;
                                        }
                                    }
                                    foreach (['service_slug','service','serviceName','title','treatment'] as $k) {
                                        $v = data_get($meta, $k);
                                        if (is_string($v) && $v !== '') {
                                            $serviceCandidates[] = \Illuminate\Support\Str::slug($v);
                                        }
                                    }
                                    $serviceCandidates = array_values(array_unique(array_filter($serviceCandidates)));

                                    $startAt = null;
                                    $endAt = null;
                                    try { if ($start) { $startAt = Carbon::parse($start); } } catch (Throwable $e) { $startAt = null; }
                                    try { if ($end)   { $endAt   = Carbon::parse($end);   } } catch (Throwable $e) { $endAt = null; }

                                    // First try a direct match via order_reference if the column exists
                                    $updatedAny = false;
                                    if (DBSchema::hasColumn('appointments', 'order_reference')) {
                                        $rows = DB::table('appointments')->where('order_reference', $record->reference)->get();
                                        if ($rows->count() > 0) {
                                            $update = [];
                                            if (DBSchema::hasColumn('appointments', 'status'))         $update['status'] = 'approved';
                                            if (DBSchema::hasColumn('appointments', 'booking_status')) $update['booking_status'] = 'approved';
                                            if (DBSchema::hasColumn('appointments', 'is_visible'))     $update['is_visible'] = 1;
                                            if (DBSchema::hasColumn('appointments', 'visible'))        $update['visible'] = 1;
                                            if (DBSchema::hasColumn('appointments', 'updated_at'))     $update['updated_at'] = now();

                                            DB::table('appointments')->where('order_reference', $record->reference)->update($update);
                                            $updatedAny = true;
                                        }
                                    }

                                    // Otherwise, match by service and near start time
                                    if (!$updatedAny && !empty($serviceCandidates) && $startAt) {
                                        $q = DB::table('appointments')->whereIn('service', $serviceCandidates);
                                        // Match a 30-minute window to be resilient to seconds
                                        $q->whereBetween('start_at', [
                                            $startAt->copy()->subMinutes(30),
                                            $startAt->copy()->addMinutes(30),
                                        ]);
                                        $rows = $q->get();
                                        if ($rows->count() > 0) {
                                            $update = [];
                                            if (DBSchema::hasColumn('appointments', 'status'))         $update['status'] = 'approved';
                                            if (DBSchema::hasColumn('appointments', 'booking_status')) $update['booking_status'] = 'approved';
                                            if (DBSchema::hasColumn('appointments', 'is_visible'))     $update['is_visible'] = 1;
                                            if (DBSchema::hasColumn('appointments', 'visible'))        $update['visible'] = 1;
                                            if (DBSchema::hasColumn('appointments', 'order_reference')) $update['order_reference'] = $record->reference;
                                            if (DBSchema::hasColumn('appointments', 'user_id') && isset($record->user_id)) $update['user_id'] = $record->user_id;
                                            if (DBSchema::hasColumn('appointments', 'updated_at'))     $update['updated_at'] = now();

                                            DB::table('appointments')
                                                ->whereIn('service', $serviceCandidates)
                                                ->whereBetween('start_at', [
                                                    $startAt->copy()->subMinutes(30),
                                                    $startAt->copy()->addMinutes(30),
                                                ])
                                                ->update($update);
                                            $updatedAny = true;
                                        }
                                    }

                                    // If nothing matched, create a row so it appears only post-approval
                                    if (!$updatedAny && ($startAt || !empty($serviceCandidates))) {
                                        $insert = [];
                                        $insert['service']  = $serviceCandidates[0] ?? (\Illuminate\Support\Str::slug((string) (data_get($meta, 'service') ?? data_get($meta, 'serviceName') ?? data_get($meta, 'title') ?? data_get($meta, 'treatment') ?? 'service')));
                                        if ($startAt) $insert['start_at'] = $startAt->toDateTimeString();
                                        if ($endAt)   $insert['end_at']   = $endAt->toDateTimeString();
                                        if (DBSchema::hasColumn('appointments', 'status'))         $insert['status'] = 'approved';
                                        if (DBSchema::hasColumn('appointments', 'booking_status')) $insert['booking_status'] = 'approved';
                                        if (DBSchema::hasColumn('appointments', 'is_visible'))     $insert['is_visible'] = 1;
                                        if (DBSchema::hasColumn('appointments', 'visible'))        $insert['visible'] = 1;
                                        if (DBSchema::hasColumn('appointments', 'order_reference')) $insert['order_reference'] = $record->reference;
                                        if (DBSchema::hasColumn('appointments', 'user_id') && isset($record->user_id)) $insert['user_id'] = $record->user_id;
                                        if (DBSchema::hasColumn('appointments', 'created_at'))     $insert['created_at'] = now();
                                        if (DBSchema::hasColumn('appointments', 'updated_at'))     $insert['updated_at'] = now();

                                        DB::table('appointments')->insert($insert);
                                    }
                                } catch (Throwable $e) {
                                    // swallow
                                }
                                $action->success();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                                return redirect(ListPendingOrders::getUrl());
                            }),
                        Action::make('reject')
                            ->label('Reject')
                            ->color('danger')
                            ->icon('heroicon-o-x-mark')
                            ->modalHeading('Reject Order')
                            ->modalDescription('Please provide a reason. This note will be saved with the order.')
                            ->modalSubmitActionLabel('Reject')
                            ->requiresConfirmation(false)
                            ->schema([
                                Textarea::make('rejection_note')
                                    ->label('Rejection Note')
                                    ->rows(4)
                                    ->required()
                                    ->columnSpanFull(),
                            ])
                            ->action(function (PendingOrder $record, array $data, Action $action) {
                                $note = trim((string)($data['rejection_note'] ?? ''));
                                $timestamp = now()->format('d-m-Y H:i');
                                $noteLine = $timestamp . ': ' . ($note !== '' ? $note : 'Rejected');

                                // Load & update meta with note + timestamp (de-duplicate identical lines)
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                $existing = (string) (data_get($meta, 'rejection_notes', '') ?? '');
                                $lines = preg_split("/\r\n|\n|\r/", $existing, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                                if (!in_array($noteLine, $lines, true)) {
                                    $lines[] = $noteLine;
                                }
                                data_set($meta, 'rejection_notes', implode("\n", $lines));
                                data_set($meta, 'rejected_at', now()->toISOString());
                                $record->meta = $meta;

                                // Ensure the order leaves Pending view and shows in Rejected
                                $record->status = 'rejected';
                                $record->booking_status = 'rejected';

                                // Flip payment to refunded if it had been paid
                                $currentPayment = strtolower((string) $record->payment_status);
                                if ($currentPayment === 'paid') {
                                    $record->payment_status = 'refunded';
                                    // If you later add a refunded_at column, uncomment:
                                    // $record->refunded_at = now();
                                }

                                $record->save();

                                // Also sync the real Order by reference so the frontend reflects the change
                                try {
                                    $order = Order::where('reference', $record->reference)->first();
                                    if ($order) {
                                        $orderMeta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

                                        // Append the same rejection note and timestamps into the Order meta (de-duplicate identical lines)
                                        $existingOrderNotes = (string) (data_get($orderMeta, 'rejection_notes', '') ?? '');
                                        $orderLines = preg_split("/\r\n|\n|\r/", $existingOrderNotes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                                        $orderNoteLine = $noteLine; // same line as in PendingOrder meta
                                        if (!in_array($orderNoteLine, $orderLines, true)) {
                                            $orderLines[] = $orderNoteLine;
                                        }
                                        data_set($orderMeta, 'rejection_notes', implode("\n", $orderLines));
                                        data_set($orderMeta, 'rejected_at', now()->toISOString());

                                        // Determine payment status
                                        $orderPaid = strtolower((string) $order->payment_status) === 'paid';
                                        $newPayment = $orderPaid ? 'refunded' : $order->payment_status;

                                        $order->forceFill([
                                            'status'         => 'rejected',
                                            'booking_status' => 'rejected',
                                            'payment_status' => $newPayment,
                                            'meta'           => $orderMeta,
                                        ])->save();
                                    }
                                } catch (Throwable $e) {
                                    // swallow to avoid breaking the UI; consider logging if needed
                                }

                                // On rejection, remove or hide any matching appointment so it disappears from the list
                                // On rejection, mark any matching appointment as rejected (don't delete)
                                try {
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                                    $start = data_get($meta, 'appointment_start_at')
                                        ?? data_get($meta, 'appointment.start_at')
                                        ?? data_get($meta, 'appointment_at')
                                        ?? data_get($meta, 'booking.start_at');

                                    // Build candidate service slugs
                                    $serviceCandidates = [];
                                    $sid = data_get($meta, 'consultation_session_id')
                                        ?? data_get($meta, 'consultation.sessionId');
                                    if ($sid) {
                                        $slug = DB::table('consultation_sessions')
                                            ->where('id', $sid)
                                            ->value('service_slug');
                                        if (is_string($slug) && $slug !== '') {
                                            $serviceCandidates[] = $slug;
                                        }
                                    }
                                    foreach (['service_slug','service','serviceName','title','treatment'] as $k) {
                                        $v = data_get($meta, $k);
                                        if (is_string($v) && $v !== '') {
                                            $serviceCandidates[] = \Illuminate\Support\Str::slug($v);
                                        }
                                    }
                                    $serviceCandidates = array_values(array_unique(array_filter($serviceCandidates)));

                                    $startAt = null;
                                    try { if ($start) { $startAt = Carbon::parse($start); } } catch (Throwable $e) { $startAt = null; }

                                    // Build update payload
                                    $update = [];
                                    if (DBSchema::hasColumn('appointments', 'status'))         $update['status'] = 'rejected';
                                    if (DBSchema::hasColumn('appointments', 'booking_status')) $update['booking_status'] = 'rejected';
                                    if (DBSchema::hasColumn('appointments', 'is_visible'))     $update['is_visible'] = 1; // keep visible so you can see it’s rejected
                                    if (DBSchema::hasColumn('appointments', 'visible'))        $update['visible'] = 1;
                                    if (DBSchema::hasColumn('appointments', 'updated_at'))     $update['updated_at'] = now();

                                    $updatedAny = false;

                                    // Prefer a direct match via order_reference if present
                                    if (DBSchema::hasColumn('appointments', 'order_reference')) {
                                        $affected = DB::table('appointments')
                                            ->where('order_reference', $record->reference)
                                            ->update($update);
                                        $updatedAny = $affected > 0;
                                    }

                                    // Otherwise match by service and near start time
                                    if (!$updatedAny && !empty($serviceCandidates) && $startAt) {
                                        $payload = $update;
                                        if (DBSchema::hasColumn('appointments', 'order_reference')) {
                                            $payload['order_reference'] = $record->reference;
                                        }

                                        DB::table('appointments')
                                            ->whereIn('service', $serviceCandidates)
                                            ->whereBetween('start_at', [
                                                $startAt->copy()->subMinutes(30),
                                                $startAt->copy()->addMinutes(30),
                                            ])
                                            ->update($payload);
                                    }
                                } catch (Throwable $e) {
                                    // swallow
                                }

                                $action->success();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');

                                return redirect(ListPendingOrders::getUrl());
                            }),
                            Action::make('addAdminNote')
                                ->label('Add Admin Note')
                                ->color('primary')
                                ->icon('heroicon-o-document-check')
                                ->form([
                                    Textarea::make('new_note')
                                        ->label('Add New Note')
                                        ->hiddenLabel()
                                        ->rows(4)
                                        ->columnSpanFull()
                                        ->extraAttributes(['id' => 'new_admin_note_textarea']),
                                    Placeholder::make('dictate_toolbar_new_note')
                                        ->hiddenLabel()
                                        ->label(false)
                                        ->content(function () {
                                            return new \Illuminate\Support\HtmlString(<<<'HTMLX'
                                    <div
                                        class="flex items-center gap-3 mt-2"
                                        wire:ignore
                                        wire:ignore.self
                                        x-data="{
                                            listening:false, rec:null, userStopped:false, status:'Mic off',

                                            root(){ return $el.closest('[data-dialog]') || $el.closest('.fi-modal') || document },
                                            ta(){
                                                return this.root().querySelector('textarea#new_admin_note_textarea')
                                                    || this.root().querySelector('textarea[name=&quot;data[new_note]&quot;]')
                                                    || this.root().querySelector('textarea[name=&quot;new_note&quot;], textarea[name$=&quot;[new_note]&quot;]')
                                                    || this.root().querySelector('textarea');
                                            },
                                            push(txt){
                                                const el=this.ta(); if(!el){ this.status='Textarea not found'; return }
                                                const chunk=String(txt||'').trim(); if(!chunk) return
                                                const space = el.value && !/\\s$/.test(el.value) ? ' ' : ''
                                                el.value += space + chunk
                                                el.dispatchEvent(new Event('input', {bubbles:true}))
                                                el.dispatchEvent(new Event('change', {bubbles:true}))
                                            },
                                            async ensurePerm(){
                                                try{ const s=await navigator.mediaDevices.getUserMedia({audio:true}); (s.getTracks?.()||[]).forEach(t=>t.stop()); return true }
                                                catch(e){ this.status='Mic blocked'; return false }
                                            },
                                            start(){
                                                const SR = window.SpeechRecognition || window.webkitSpeechRecognition
                                                if(!SR){ this.status='Not supported'; return }
                                                this.userStopped=false
                                                this.rec=new SR(); this.rec.lang='en-GB'; this.rec.continuous=true; this.rec.interimResults=true
                                                this.rec.onresult = e => {
                                                    let fin='', tmp=''
                                                    for(let i=e.resultIndex;i<e.results.length;i++){
                                                        const r=e.results[i]
                                                        if(r.isFinal) fin += r[0].transcript
                                                        else tmp += r[0].transcript
                                                    }
                                                    if(fin) this.push(fin)
                                                    this.status = this.listening ? ('Listening… ' + tmp.trim()) : 'Mic off'
                                                }
                                                this.rec.onstart = ()=>{ this.listening=true; this.status='Listening…' }
                                                this.rec.onerror = e=>{ this.status='Error ' + (e?.error ?? '') }
                                                this.rec.onend   = ()=>{
                                                    this.listening=false; this.status='Mic off'
                                                    if(!this.userStopped){ try{ this.rec.start() }catch(_){} }
                                                }
                                                try{ this.rec.start() }catch(_){ this.status='Start failed' }
                                            },
                                            async toggle(){
                                                if(!this.listening){
                                                    if(await this.ensurePerm()) this.start()
                                                }else{
                                                    this.userStopped=true
                                                    try{ this.rec?.stop() }catch(_){}
                                                    this.listening=false; this.status='Mic off'
                                                }
                                            }
                                        }"
                                        >
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-3 px-3 py-1.5 rounded-md text-sm font-medium
                                                bg-green-600/20 hover:bg-green-600/30 transition"
                                            x-bind:class="{ 'bg-red-600/20 hover:bg-red-600/30': listening }"
                                            x-on:click="toggle()"
                                        >
                                            <!-- mic icon -->
                                            <svg x-show="!listening" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                class="w-4 h-4" fill="currentColor" aria-hidden="true">
                                                <path d="M12 14a4 4 0 0 0 4-4V7a4 4 0 1 0-8 0v3a4 4 0 0 0 4 4Zm-7-4a1 1 0 0 1 2 0 5 5 0 1 0 10 0 1 1 0 1 1 2 0 7 7 0 0 1-6 6.93V20h3a1 1 0 1 1 0 2H10a1 1 0 1 1 0-2h3v-3.07A7 7 0 0 1 5 10Z"/>
                                            </svg>

                                            <!-- stop icon -->
                                            <svg x-show="listening" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                class="w-4 h-4" fill="currentColor" aria-hidden="true">
                                                <path d="M6 6h12v12H6z"/>
                                            </svg>

                                            <!-- labels -->
                                            <span x-show="!listening">Start dictation</span>
                                            <span x-show="listening">Stop dictation</span>
                                        </button>

                                        <!-- status (spaced away from button) -->
                                        <span class="inline-flex items-center gap-2 text-xs opacity-80 ml-3">
                                            <span class="inline-block w-2 h-2 rounded-full bg-gray-400"
                                                x-bind:class="listening ? 'bg-emerald-400 animate-pulse' : 'bg-gray-400'"></span>

                                            <span x-text="status"></span>
                                        </span>
                                        </div>
                                    HTMLX);
                                        })
                                        ->columnSpanFull(),
                                    
                            ])
                            ->action(function (PendingOrder $record, array $data, Action $action) {
                                $newNote = trim($data['new_note'] ?? '');
                                if ($newNote === '') {
                                    return;
                                }
                                $timestamp = now()->format('d-m-Y H:i');
                                $toAppend = $timestamp . ': ' . $newNote;
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                $existing = data_get($meta, 'admin_notes', '');
                                data_set($meta, 'admin_notes', $existing ? ($existing . "\n" . $toAppend) : $toAppend);
                                $record->meta = $meta;
                                $record->save();
                                $record->refresh();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                                $action->success();
                            }),
                        Action::make('clearAdminNotes')
                            ->label('Clear Admin Notes')
                            ->color('warning')
                            ->icon('heroicon-o-trash')
                            ->requiresConfirmation()
                            ->action(function (PendingOrder $record, Action $action) {
                                $meta = $record->meta ?? [];
                                if (is_array($meta)) {
                                    unset($meta['admin_notes']);
                                }
                                $record->meta = $meta;
                                $record->save();
                                $record->refresh();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                                $action->success();
                            }),
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPendingOrders::route('/'),
      
        ];
    }

   

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = static::getEloquentQuery()->count();
        } catch (Throwable $e) {
            $count = 0;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        try {
            $count = static::getEloquentQuery()->count();
        } catch (Throwable $e) {
            $count = 0;
        }

        return $count > 0 ? 'warning' : 'gray';
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
