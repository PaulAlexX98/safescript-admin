<?php

namespace App\Filament\Resources\UnpaidOrders;

use Carbon\Carbon;
use Throwable;
use Illuminate\Support\Collection;
use Filament\Tables\Filters\SelectFilter;
use App\Filament\Resources\UnpaidOrders\Pages\CreateUnpaidOrder;
use App\Filament\Resources\UnpaidOrders\Pages\EditUnpaidOrder;
use App\Filament\Resources\UnpaidOrders\Pages\ListUnpaidOrders;
use App\Filament\Resources\UnpaidOrders\Schemas\PendingOrderForm;
use App\Filament\Resources\UnpaidOrders\Tables\PendingOrdersTable;
use Filament\Actions\Action;
use App\Models\PendingOrder;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\User;
use App\Support\DatabaseSchema as DBSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use BackedEnum;
use UnitEnum;
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
use Illuminate\Support\Facades\Cache;


class UnpaidOrderResource extends Resource
{
    protected static ?string $model = PendingOrder::class;

    /** @var \WeakMap<object, array{value: ?Order}>|null */
    protected static ?\WeakMap $latestOrderCache = null;

    protected static function latestOrderForRecord($record): ?Order
    {
        if (! is_object($record)) {
            return null;
        }

        static::$latestOrderCache ??= new \WeakMap();

        if (isset(static::$latestOrderCache[$record])) {
            return static::$latestOrderCache[$record]['value'];
        }

        $order = static::resolveLatestOrderForRecord($record);

        static::$latestOrderCache[$record] = ['value' => $order];

        return $order;
    }

    protected static function resolveLatestOrderForRecord($record): ?Order
    {
        return Order::query()
            ->where('reference', $record->reference)
            ->latest()
            ->first();
    }

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

protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedBanknotes;
protected static string|UnitEnum|null $navigationGroup = 'Private Services';

    protected static ?string $navigationLabel = 'Unpaid Orders';
    protected static ?string $pluralLabel = 'Unpaid Orders';
    protected static ?string $modelLabel = 'Unpaid Order';

    protected static ?int    $navigationSort  = 6;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        // Prevent one user query per visible table row.
        $q->with('user');

        $baseTable = $q->getModel()->getTable();
        $outerTable = $baseTable;
        // Only show records that are still pending.

        // Only show records that are still pending.
        // Be defensive because deployments may have slightly different column names.
        try {
            $table = $baseTable;

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
        $q = $q->where(function (Builder $w) {
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

        // Only show UNPAID orders in this resource.
        try {
            $table = $baseTable;

            $q->where(function (Builder $w) use ($table) {
                $hasCol = false;
                try {
                    $hasCol = DBSchema::hasColumn($table, 'payment_status');
                } catch (\Throwable $e) {
                    $hasCol = false;
                }

                if ($hasCol) {
                    $w->orWhereRaw('LOWER(payment_status) = ?', ['unpaid']);
                }

                // Fall back to meta values (covers deployments where payment status is stored in JSON).
                $w->orWhereRaw(
                    "COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.payment_status'))), '') = ?",
                    ['unpaid']
                );

                $w->orWhereRaw(
                    "COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.payment_status_label'))), '') = ?",
                    ['unpaid']
                );
            });
        } catch (\Throwable $e) {
            // ignore
        }

        // Hide duplicate unpaid records when the same patient already has a paid/completed
        // order within 7 days of the pending order being created.
        // Keep this version cheap by matching on user_id only.
        try {
            $q->where(function (Builder $w) use ($outerTable) {
                $w->whereNull("{$outerTable}.user_id")
                  ->orWhereNotExists(function ($sub) use ($outerTable) {
                      $sub->selectRaw('1')
                          ->from('orders as paid_orders')
                          ->whereRaw("paid_orders.user_id = {$outerTable}.user_id")
                          ->whereRaw("paid_orders.id <> {$outerTable}.id")
                          ->whereRaw("paid_orders.created_at >= {$outerTable}.created_at")
                          ->whereRaw("paid_orders.created_at <= DATE_ADD({$outerTable}.created_at, INTERVAL 7 DAY)")
                          ->where(function ($paid) {
                              $paid->whereRaw("LOWER(COALESCE(paid_orders.payment_status, '')) = ?", ['paid'])
                                  ->orWhereRaw("LOWER(COALESCE(paid_orders.status, '')) = ?", ['completed'])
                                  ->orWhereRaw(
                                      "COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(paid_orders.meta, '$.payment_status'))), '') = ?",
                                      ['paid']
                                  )
                                  ->orWhereRaw(
                                      "COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(paid_orders.meta, '$.payment_status_label'))), '') = ?",
                                      ['paid']
                                  );
                          });
                  });
            });
        } catch (\Throwable $e) {
            // ignore
        }

        return $q;
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
                        $p = optional($record->user)->priority ?? null;

                        if (! is_string($p) || trim($p) === '') {
                            $meta = is_array($record->meta)
                                ? $record->meta
                                : (json_decode($record->meta ?? '[]', true) ?: []);
                            $p = data_get($meta, 'priority');
                        }

                        if (! is_string($p) || trim($p) === '') {
                            try {
                                $order = Order::query()
                                    ->select(['id', 'meta'])
                                    ->where('reference', $record->reference)
                                    ->latest('id')
                                    ->first();

                                if ($order) {
                                    $orderMeta = is_array($order->meta)
                                        ? $order->meta
                                        : (json_decode($order->meta ?? '[]', true) ?: []);
                                    $p = data_get($orderMeta, 'priority');
                                }
                            } catch (Throwable $e) {
                                // Preserve the existing green fallback.
                            }
                        }

                        $p = is_string($p) ? strtolower(trim($p)) : null;
                        $resolved = in_array($p, ['red', 'yellow', 'green'], true) ? $p : 'green';

                        $record->setAttribute('resolved_patient_priority', $resolved);

                        return $resolved;
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
                        $priority = $record->getAttribute('resolved_patient_priority');

                        if (! is_string($priority) || $priority === '') {
                            $priority = 'green';
                        }

                        return ucfirst($priority);
                    })
                    ->html()
                    ->extraAttributes(['style' => 'text-align:center; width:5rem']),

                TextColumn::make('reference')
                    ->label('Ref')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Reference copied')
                    ->toggleable(),
                
                TextColumn::make('created_at')
                    ->label('Order Created')
                    ->formatStateUsing(function ($state, $record) {
                        $createdAt = null;

                        try {
                            $createdAt = method_exists($record, 'getRawOriginal')
                                ? $record->getRawOriginal('created_at')
                                : null;
                        } catch (Throwable $e) {
                            $createdAt = null;
                        }

                        $createdAt = $createdAt ?: $state;

                        if (! $createdAt) {
                            return '—';
                        }

                        try {
                            return Carbon::parse($createdAt, 'UTC')
                                ->timezone('Europe/London')
                                ->format('d M Y, H:i');
                        } catch (Throwable $e) {
                            return (string) $createdAt;
                        }
                    })
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

                        // Only query the consultation session when the metadata does
                        // not already contain a useful service name.
                        $looksGeneric = ! $name || strtolower(trim((string) $name)) === 'service';

                        if ($looksGeneric) {
                            $sid = data_get($meta, 'consultation_session_id')
                                ?? data_get($meta, 'consultation.sessionId');

                            if ($sid) {
                                try {
                                    $slug = DB::table('consultation_sessions')
                                        ->where('id', $sid)
                                        ->value('service_slug');

                                    if ($slug) {
                                        $name = ucwords(str_replace(['-', '_'], ' ', $slug));
                                    }
                                } catch (Throwable $e) {
                                    // Preserve the existing metadata fallback.
                                }
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
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
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
                                            ->formatStateUsing(function ($state, $record) {
                                                $createdAt = null;

                                                try {
                                                    $createdAt = method_exists($record, 'getRawOriginal')
                                                        ? $record->getRawOriginal('created_at')
                                                        : null;
                                                } catch (Throwable $e) {
                                                    $createdAt = null;
                                                }

                                                $createdAt = $createdAt ?: $state;

                                                if (! $createdAt) {
                                                    return '—';
                                                }

                                                try {
                                                    return Carbon::parse($createdAt, 'UTC')
                                                        ->timezone('Europe/London')
                                                        ->format('d-m-Y H:i');
                                                } catch (Throwable $e) {
                                                    return (string) $createdAt;
                                                }
                                            }),
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
                                                                if (DBSchema::hasColumn('users', 'meta')) {
                                                                    $um = is_array($u->meta) ? $u->meta : (json_decode($u->meta ?? '[]', true) ?: []);
                                                                    $uv = $norm(data_get($um, 'scr_verified'));
                                                                    if ($uv !== null) return $uv;
                                                                }
                                                                if (DBSchema::hasColumn('users', 'scr_verified')) {
                                                                    $flat = $norm($u->scr_verified ?? null);
                                                                    if ($flat !== null) return $flat;
                                                                }
                                                            }
                                                        } catch (\Throwable $e) {}

                                                        // 3) Linked order by reference
                                                        try {
                                                            $ord = static::latestOrderForRecord($record);
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
                                                                if (DBSchema::hasColumn('users', 'meta')) {
                                                                    $um = is_array($u->meta) ? $u->meta : (json_decode($u->meta ?? '[]', true) ?: []);
                                                                    $uv = $norm(data_get($um, 'id_verified'));
                                                                    if ($uv !== null) return $uv;
                                                                }
                                                                if (DBSchema::hasColumn('users', 'id_verified')) {
                                                                    $flat = $norm($u->id_verified ?? null);
                                                                    if ($flat !== null) return $flat;
                                                                }
                                                            }
                                                        } catch (\Throwable $e) {}

                                                        // 3) Linked order by reference
                                                        try {
                                                            $ord = static::latestOrderForRecord($record);
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
                        Action::make('moveToPending')
                            ->label('Move to Pending')
                            ->color('success')
                            ->icon('heroicon-o-arrow-right-circle')
                            ->requiresConfirmation()
                            ->modalHeading('Move order to pending approval?')
                            ->modalDescription('This will mark the order as paid and move it into Pending Approval. It will not approve the order or send an approval email.')
                            ->modalSubmitActionLabel('Move to Pending')
                            ->action(function (PendingOrder $record, Action $action) {
                                try {
                                    DB::transaction(function () use ($record) {
                                        $now = now();
                                        $pendingTable = $record->getTable();

                                        $pendingMeta = is_array($record->meta)
                                            ? $record->meta
                                            : (json_decode($record->meta ?? '[]', true) ?: []);

                                        data_set($pendingMeta, 'payment_status', 'paid');
                                        data_set($pendingMeta, 'payment_status_label', 'Paid');
                                        data_set($pendingMeta, 'paid_at', $now->toIso8601String());

                                        data_forget($pendingMeta, 'approved_at');
                                        data_forget($pendingMeta, 'rejected_at');
                                        data_forget($pendingMeta, 'completed_at');

                                        $pendingUpdates = [
                                            'meta' => $pendingMeta,
                                        ];

                                        if (DBSchema::hasColumn($pendingTable, 'status')) {
                                            $pendingUpdates['status'] = 'pending';
                                        }

                                        if (DBSchema::hasColumn($pendingTable, 'booking_status')) {
                                            $pendingUpdates['booking_status'] = 'pending';
                                        }

                                        if (DBSchema::hasColumn($pendingTable, 'payment_status')) {
                                            $pendingUpdates['payment_status'] = 'paid';
                                        }

                                        if (DBSchema::hasColumn($pendingTable, 'paid_at')) {
                                            $pendingUpdates['paid_at'] = $record->paid_at ?: $now;
                                        }

                                        if (DBSchema::hasColumn($pendingTable, 'approved_at')) {
                                            $pendingUpdates['approved_at'] = null;
                                        }

                                        if (DBSchema::hasColumn($pendingTable, 'rejected_at')) {
                                            $pendingUpdates['rejected_at'] = null;
                                        }

                                        if (DBSchema::hasColumn($pendingTable, 'decision_at')) {
                                            $pendingUpdates['decision_at'] = null;
                                        }

                                        if (DBSchema::hasColumn($pendingTable, 'completed_at')) {
                                            $pendingUpdates['completed_at'] = null;
                                        }

                                        $record->forceFill($pendingUpdates)->save();

                                        $reference = trim((string) ($record->reference ?? ''));

                                        if ($reference === '') {
                                            return;
                                        }

                                        $order = Order::query()
                                            ->where('reference', $reference)
                                            ->latest('id')
                                            ->first();

                                        if (! $order) {
                                            return;
                                        }

                                        $orderTable = $order->getTable();

                                        $orderMeta = is_array($order->meta)
                                            ? $order->meta
                                            : (json_decode($order->meta ?? '[]', true) ?: []);

                                        $orderMeta = array_replace_recursive($orderMeta, $pendingMeta);

                                        data_set($orderMeta, 'payment_status', 'paid');
                                        data_set($orderMeta, 'payment_status_label', 'Paid');
                                        data_set($orderMeta, 'paid_at', $now->toIso8601String());

                                        data_forget($orderMeta, 'approved_at');
                                        data_forget($orderMeta, 'rejected_at');
                                        data_forget($orderMeta, 'completed_at');

                                        $orderUpdates = [
                                            'meta' => $orderMeta,
                                        ];

                                        if (DBSchema::hasColumn($orderTable, 'status')) {
                                            $orderUpdates['status'] = 'pending';
                                        }

                                        if (DBSchema::hasColumn($orderTable, 'booking_status')) {
                                            $orderUpdates['booking_status'] = 'pending';
                                        }

                                        if (DBSchema::hasColumn($orderTable, 'payment_status')) {
                                            $orderUpdates['payment_status'] = 'paid';
                                        }

                                        if (DBSchema::hasColumn($orderTable, 'paid_at')) {
                                            $orderUpdates['paid_at'] = $order->paid_at ?: $now;
                                        }

                                        if (DBSchema::hasColumn($orderTable, 'approved_at')) {
                                            $orderUpdates['approved_at'] = null;
                                        }

                                        if (DBSchema::hasColumn($orderTable, 'rejected_at')) {
                                            $orderUpdates['rejected_at'] = null;
                                        }

                                        if (DBSchema::hasColumn($orderTable, 'completed_at')) {
                                            $orderUpdates['completed_at'] = null;
                                        }

                                        $order->forceFill($orderUpdates)->save();
                                    });

                                    Notification::make()
                                        ->title('Order moved to Pending Approval')
                                        ->success()
                                        ->send();

                                    $action->getLivewire()->dispatch('$refresh');
                                    $action->getLivewire()->dispatch('refreshTable');

                                    return redirect(ListUnpaidOrders::getUrl());
                                } catch (Throwable $e) {
                                    Notification::make()
                                        ->title('Failed to move order to Pending Approval')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            }),
                        
                    ]),

                    Action::make('deleteOrder')
        ->label('Delete')
        ->button()
        ->color('danger')
        ->requiresConfirmation()
        ->modalHeading('Delete unpaid order')
        ->modalDescription(fn ($record) => 'Are you sure you want to delete unpaid order ' . ($record->reference ?? '—') . '? This cannot be undone.')
        ->modalSubmitActionLabel('Delete')
        ->action(function ($record) {
            if (! $record) {
                return;
            }

            try {
                $record->delete();

                Notification::make()
                    ->title('Unpaid order deleted')
                    ->success()
                    ->send();
            } catch (\Throwable $e) {
                Notification::make()
                    ->title('Failed to delete unpaid order')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();
            }
        }),
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
            'index' => Pages\ListUnpaidOrders::route('/'),
            'create' => Pages\CreateUnpaidOrder::route('/create'),
            'edit' => Pages\EditUnpaidOrder::route('/{record}/edit'),
        ];
    }

   

   public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember(
            'filament:navigation:unpaid-orders-count',
            now()->addMinutes(2),
            function (): int {
                try {
                    return static::getEloquentQuery()->count();
                } catch (Throwable $e) {
                    return 0;
                }
            }
        );

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $count = (int) Cache::get(
            'filament:navigation:unpaid-orders-count',
            0
        );

        return $count > 0 ? 'warning' : 'gray';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference'];
    }
}
