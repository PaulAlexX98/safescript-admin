<?php

namespace App\Filament\Resources\UnpaidOrders\Pages;

use App\Filament\Resources\UnpaidOrders\UnpaidOrderResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use App\Models\PendingOrder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema as DBSchema;

class ListUnpaidOrders extends ListRecords
{
    protected static string $resource = UnpaidOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('emailPendingPatients')
                ->label('Email patients')
                ->icon('heroicon-o-envelope')
                ->color('primary')
                ->form([
                    TextInput::make('subject')
                        ->label('Subject')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Payment reminder for your pending order'),
                    Textarea::make('message')
                        ->label('Message')
                        ->required()
                        ->rows(12)
                        ->placeholder("Dear {name},\n\nWe noticed that your order {reference} is still awaiting payment.\n\nPlease return to complete your order at your earliest convenience.\n\nKind regards,\nPharmacy Express"),
                ])
                ->action(function (array $data) {
                    $q = PendingOrder::query();

                    try {
                        $table = $q->getModel()->getTable();

                        if (DBSchema::hasColumn($table, 'status')) {
                            $q->whereIn('status', ['pending', 'awaiting', 'waiting']);
                        } elseif (DBSchema::hasColumn($table, 'pending_status')) {
                            $q->whereIn('pending_status', ['pending', 'awaiting', 'waiting']);
                        } elseif (DBSchema::hasColumn($table, 'state')) {
                            $q->whereIn('state', ['pending', 'awaiting', 'waiting']);
                        }

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

                    $q = $q->where(function ($w) {
                        $w->where(function ($x) {
                            $x->whereNull('reference')
                                ->orWhere('reference', 'not like', 'PNHS%');
                        })
                        ->whereRaw(
                            "COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))), '') <> ?",
                            ['nhs']
                        )
                        ->whereRaw(
                            "COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service_slug'))), '') <> ?",
                            ['pharmacy-first']
                        );
                    });

                    try {
                        $table = $q->getModel()->getTable();

                        $q->where(function ($w) use ($table) {
                            $hasCol = false;
                            try {
                                $hasCol = DBSchema::hasColumn($table, 'payment_status');
                            } catch (\Throwable $e) {
                                $hasCol = false;
                            }

                            if ($hasCol) {
                                $w->orWhereRaw('LOWER(payment_status) = ?', ['unpaid']);
                            }

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

                    $rows = $q->with('user')->latest('created_at')->get();

                    $sent = 0;
                    $skipped = 0;
                    $sentTo = [];

                    foreach ($rows as $record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                        $email = data_get($meta, 'email')
                            ?: data_get($meta, 'patient.email')
                            ?: optional($record->user)->email
                            ?: null;

                        if (!is_string($email) || trim($email) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $skipped++;
                            continue;
                        }

                        $email = strtolower(trim($email));
                        if (isset($sentTo[$email])) {
                            continue;
                        }

                        $customer = trim(
                            trim((string) (data_get($meta, 'firstName') ?: data_get($meta, 'first_name') ?: data_get($meta, 'patient.firstName') ?: data_get($meta, 'patient.first_name') ?: optional($record->user)->first_name))
                            . ' ' .
                            trim((string) (data_get($meta, 'lastName') ?: data_get($meta, 'last_name') ?: data_get($meta, 'patient.lastName') ?: data_get($meta, 'patient.last_name') ?: optional($record->user)->last_name))
                        );
                        if ($customer === '') {
                            $customer = data_get($meta, 'name') ?: data_get($meta, 'full_name') ?: optional($record->user)->name ?: 'Patient';
                        }

                        $message = str_replace(['{name}', '{reference}'], [
                            $customer,
                            (string) ($record->reference ?? ''),
                        ], (string) $data['message']);

                        Mail::raw($message, function ($mail) use ($email, $customer, $data) {
                            $mail->to($email, $customer)
                                ->subject((string) $data['subject']);
                        });

                        $sentTo[$email] = true;
                        $sent++;
                    }

                    Notification::make()
                        ->title('Bulk email completed')
                        ->body("Sent to {$sent} patient(s). Skipped {$skipped} record(s) without a valid email.")
                        ->success()
                        ->send();
                }),

            Action::make('downloadPendingCsv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->action(function () {
                    $q = PendingOrder::query();

                    try {
                        $table = $q->getModel()->getTable();

                        if (DBSchema::hasColumn($table, 'status')) {
                            $q->whereIn('status', ['pending', 'awaiting', 'waiting']);
                        } elseif (DBSchema::hasColumn($table, 'pending_status')) {
                            $q->whereIn('pending_status', ['pending', 'awaiting', 'waiting']);
                        } elseif (DBSchema::hasColumn($table, 'state')) {
                            $q->whereIn('state', ['pending', 'awaiting', 'waiting']);
                        }

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

                    $q = $q->where(function ($w) {
                        $w->where(function ($x) {
                            $x->whereNull('reference')
                            ->orWhere('reference', 'not like', 'PNHS%');
                        })
                        ->whereRaw(
                            "COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))), '') <> ?",
                            ['nhs']
                        )
                        ->whereRaw(
                            "COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service_slug'))), '') <> ?",
                            ['pharmacy-first']
                        );
                    });

                    try {
                        $table = $q->getModel()->getTable();

                        $q->where(function ($w) use ($table) {
                            $hasCol = false;
                            try {
                                $hasCol = DBSchema::hasColumn($table, 'payment_status');
                            } catch (\Throwable $e) {
                                $hasCol = false;
                            }

                            if ($hasCol) {
                                $w->orWhereRaw('LOWER(payment_status) = ?', ['unpaid']);
                            }

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

                    $rows = $q->with('user')->latest('created_at')->get();

                    $handle = fopen('php://temp', 'r+');
                    fputcsv($handle, ['Priority', 'Reference', 'Order Created', 'Order Service', 'Order Item', 'Type', 'Customer', 'Email', 'Phone']);

                    foreach ($rows as $record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                        $priority = optional($record->user)->priority;
                        if (!is_string($priority) || trim($priority) === '') {
                            $priority = data_get($meta, 'priority');
                        }
                        $priority = is_string($priority) ? ucfirst(strtolower(trim($priority))) : 'Green';
                        if (!in_array(strtolower($priority), ['red', 'yellow', 'green'], true)) {
                            $priority = 'Green';
                        }

                        $service = data_get($meta, 'service')
                            ?: data_get($meta, 'serviceName')
                            ?: data_get($meta, 'treatment')
                            ?: data_get($meta, 'title')
                            ?: 'Service';

                        $item = null;
                        $candidates = [
                            data_get($meta, 'items'),
                            data_get($meta, 'products'),
                            data_get($meta, 'lines'),
                            data_get($meta, 'line_items'),
                            data_get($meta, 'cart.items'),
                        ];
                        foreach ($candidates as $cand) {
                            if (is_array($cand) && count($cand)) {
                                $first = $cand[0] ?? null;
                                if (is_array($first)) {
                                    $qty = (int) ($first['qty'] ?? $first['quantity'] ?? 1);
                                    if ($qty < 1) $qty = 1;
                                    $name = $first['name'] ?? $first['title'] ?? $first['product_name'] ?? 'Item';
                                    $opt = data_get($first, 'variations') ?? data_get($first, 'variation') ?? data_get($first, 'optionLabel')
                                        ?? data_get($first, 'variant') ?? data_get($first, 'dose') ?? data_get($first, 'strength') ?? data_get($first, 'option');
                                    if (is_array($opt)) $opt = $opt['label'] ?? $opt['value'] ?? implode(' ', array_filter(array_map('strval', $opt)));
                                    $item = trim($qty . ' × ' . $name . ($opt ? ' ' . $opt : ''));
                                }
                                break;
                            }
                        }
                        if (!$item) {
                            $qty = (int) (data_get($meta, 'qty') ?? data_get($meta, 'quantity') ?? 1);
                            if ($qty < 1) $qty = 1;
                            $name = data_get($meta, 'product_name')
                                ?: data_get($meta, 'product')
                                ?: data_get($meta, 'selectedProduct.name')
                                ?: data_get($meta, 'selected_product.name');
                            $opt = data_get($meta, 'selectedProduct.variations')
                                ?: data_get($meta, 'selectedProduct.optionLabel')
                                ?: data_get($meta, 'variant')
                                ?: data_get($meta, 'dose')
                                ?: data_get($meta, 'strength');
                            if (is_array($opt)) $opt = $opt['label'] ?? $opt['value'] ?? '';
                            $item = $name ? trim($qty . ' × ' . $name . ($opt ? ' ' . $opt : '')) : '—';
                        }

                        $type = null;
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
                        $isNew = ($raw === 'new') || preg_match('/^PTC[A-Z]*N\d{6}$/', $ref);

                        if ($isReorder) $type = 'Reorder';
                        elseif ($isNhs) $type = 'NHS';
                        elseif ($isNew) $type = 'New';
                        else $type = '—';

                        $customer = trim(
                            trim((string) (data_get($meta, 'firstName') ?: data_get($meta, 'first_name') ?: data_get($meta, 'patient.firstName') ?: data_get($meta, 'patient.first_name') ?: optional($record->user)->first_name))
                            . ' ' .
                            trim((string) (data_get($meta, 'lastName') ?: data_get($meta, 'last_name') ?: data_get($meta, 'patient.lastName') ?: data_get($meta, 'patient.last_name') ?: optional($record->user)->last_name))
                        );
                        if ($customer === '') {
                            $customer = data_get($meta, 'name') ?: data_get($meta, 'full_name') ?: optional($record->user)->name ?: '—';
                        }

                        $email = data_get($meta, 'email')
                            ?: data_get($meta, 'patient.email')
                            ?: optional($record->user)->email
                            ?: '—';

                        $phone = data_get($meta, 'phone')
                            ?: data_get($meta, 'telephone')
                            ?: data_get($meta, 'mobile')
                            ?: data_get($meta, 'patient.phone')
                            ?: data_get($meta, 'patient.telephone')
                            ?: data_get($meta, 'patient.mobile')
                            ?: optional($record->user)->phone
                            ?: optional($record->user)->telephone
                            ?: optional($record->user)->mobile
                            ?: '—';

                        fputcsv($handle, [
                            $priority,
                            (string) ($record->reference ?? ''),
                            optional($record->created_at)->format('d M Y, H:i') ?? '',
                            (string) $service,
                            (string) $item,
                            (string) $type,
                            (string) $customer,
                            (string) $email,
                            (string) $phone,
                        ]);
                    }

                    rewind($handle);
                    $csv = stream_get_contents($handle) ?: '';
                    fclose($handle);

                    return response()->streamDownload(function () use ($csv) {
                        echo $csv;
                    }, 'pending-approval.csv', [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                    ]);
                }),

            Actions\Action::make('ncrs')
                ->label('NCRS portal')
                ->url('https://digital.nhs.uk/services/national-care-records-service')
                ->openUrlInNewTab()
                ->button(),
        ];
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        $query = parent::getTableQuery();
        $status = request()->query('status');

        if ($status === 'nhs_pending') {
            if ($query instanceof Builder) {
                return $query->pendingNhs();
            }
            if ($query instanceof Relation) {
                return $query->getQuery()->pendingNhs();
            }
            return $query; // null
        }

        if ($status === 'awaiting_approval') {
            if ($query instanceof Builder) {
                return $query->pendingApproval();
            }
            if ($query instanceof Relation) {
                return $query->getQuery()->pendingApproval();
            }
            return $query; // null
        }

        return $query;
    }
}
