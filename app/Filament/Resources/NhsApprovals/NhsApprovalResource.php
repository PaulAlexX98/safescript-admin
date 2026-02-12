<?php

namespace App\Filament\Resources\NhsApprovals;

use App\Filament\Resources\NhsApprovals\Pages\ListNhsApprovals;
use App\Filament\Resources\NhsApprovals\Pages\ViewNhsApproval;
use App\Models\NhsApplication;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NhsApprovalResource extends Resource
{
    protected static ?string $model = NhsApplication::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Prescription Approvals';
    protected static ?int $navigationSort = 4;
    protected static ?string $pluralLabel = 'Prescription Approvals';
    protected static ?string $modelLabel = 'Prescription Approval';

    public static function getNavigationBadge(): ?string
    {
        $count = \App\Models\NhsApplication::query()
            ->where('status', 'pending')
            ->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        $count = \App\Models\NhsApplication::query()
            ->where('status', 'pending')
            ->count();

        return $count ? ($count . ' pending approval') : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->getStateUsing(fn (NhsApplication $r) => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: '—')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Phone')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('nhs_number')
                    ->label('NHS no')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('postcode')
                    ->label('Postcode')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'pending',
                    'approved' => 'approved',
                    'rejected' => 'rejected',
                ])->default('pending'),
            ])
            ->actionsColumnLabel('View')
            ->actions([
                Action::make('viewApplication')
                    ->label('View')
                    ->button()
                    ->color('primary')
                    ->modalHeading(fn (NhsApplication $r) => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Prescription Approvals')
                    ->modalDescription(fn (NhsApplication $r) => new HtmlString(
                        '<span class="text-xs text-gray-400">Received ' . e(optional($r->created_at)->format('d-m-Y H:i')) . '</span>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->schema([
                        Grid::make(12)->schema([
                            Section::make('Patient')
                                ->columnSpan(8)
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextEntry::make('first_name')->label('First name'),
                                        TextEntry::make('last_name')->label('Last name'),
                                        TextEntry::make('dob')->date('d M Y'),
                                        TextEntry::make('gender'),
                                        TextEntry::make('nhs_number')->label('NHS no'),
                                        TextEntry::make('email'),
                                        TextEntry::make('phone'),
                                    ]),
                                ]),
                            Section::make('Status')
                                ->columnSpan(4)
                                ->schema([
                                    TextEntry::make('status')
                                        ->badge()
                                        ->color(function ($state) {
                                            $s = strtolower((string) $state);
                                            return match ($s) {
                                                'approved' => 'success',
                                                'rejected' => 'danger',
                                                default => 'warning',
                                            };
                                        }),
                                    TextEntry::make('created_at')->label('Received')->dateTime('d-m-Y H:i'),
                                ]),
                        ]),
                        Section::make('Address')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextEntry::make('address')->label('Address')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->address ?? Arr::get($meta, 'address');
                                        }),
                                    TextEntry::make('address1')->label('Address 1')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->address1 ?? Arr::get($meta, 'address1');
                                        }),
                                    TextEntry::make('address2')->label('Address 2')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->address2 ?? Arr::get($meta, 'address2');
                                        }),
                                    TextEntry::make('city')->label('City')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->city ?? Arr::get($meta, 'city');
                                        }),
                                    TextEntry::make('postcode')->label('Postcode')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->postcode ?? Arr::get($meta, 'postcode');
                                        }),
                                    TextEntry::make('country')->label('Country')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->country ?? Arr::get($meta, 'country');
                                        }),
                                ]),
                            ])
                            ->columnSpanFull(),
                        Section::make('Delivery')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextEntry::make('delivery_address')->label('Delivery address')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_address ?? Arr::get($meta, 'delivery_address');
                                        }),
                                    TextEntry::make('delivery_address1')->label('Delivery address 1')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_address1 ?? Arr::get($meta, 'delivery_address1');
                                        }),
                                    TextEntry::make('delivery_address2')->label('Delivery address 2')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_address2 ?? Arr::get($meta, 'delivery_address2');
                                        }),
                                    TextEntry::make('delivery_city')->label('Delivery city')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_city ?? Arr::get($meta, 'delivery_city');
                                        }),
                                    TextEntry::make('delivery_postcode')->label('Delivery postcode')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_postcode ?? Arr::get($meta, 'delivery_postcode');
                                        }),
                                    TextEntry::make('delivery_country')->label('Delivery country')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->delivery_country ?? Arr::get($meta, 'delivery_country');
                                        }),
                                ]),
                            ])
                            ->columnSpanFull(),
                        Section::make('Exemption')
                            ->schema([
                                Grid::make(4)->schema([
                                    TextEntry::make('exemption')->label('Exemption')
                                        ->getStateUsing(function ($record) {
                                            $meta = self::metaArray($record->meta ?? []);
                                            return $record->exemption ?? Arr::get($meta, 'exemption');
                                        })
                                        ->formatStateUsing(fn ($state) => self::formatExemption($state)),
                                    TextEntry::make('exemption_number')->label('Exemption number'),
                                    TextEntry::make('exemption_expiry')->label('Exemption expiry')->date('d M Y'),
                                ]),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->extraModalFooterActions([
                        Action::make('approve')
                            ->label('Approve')
                            ->color('success')
                            ->icon('heroicon-o-check')
                            ->requiresConfirmation()
                            ->action(function (NhsApplication $record, Action $action) {
                                $record->forceFill([
                                    'status' => 'approved',
                                    'approved_at' => now(),
                                    'approved_by_id' => auth()->id(),
                                ])->save();

                                self::sendApprovedEmail($record);

                                $action->success();
                                try {
                                    $action->getLivewire()->dispatch('$refresh');
                                    $action->getLivewire()->dispatch('refreshTable');
                                } catch (\Throwable $e) {
                                }

                                return redirect(ListNhsApprovals::getUrl());
                            }),

                        Action::make('reject')
                            ->label('Reject')
                            ->color('danger')
                            ->icon('heroicon-o-x-mark')
                            ->form([
                                Textarea::make('reason')
                                    ->label('Reason')
                                    ->rows(4)
                                    ->required(),
                            ])
                            ->action(function (NhsApplication $record, array $data, Action $action) {
                                $reason = trim((string) ($data['reason'] ?? ''));

                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                $meta['rejection_note'] = $reason;
                                $meta['rejected_at'] = now()->toISOString();
                                $meta['rejected_by_id'] = auth()->id();

                                $record->forceFill([
                                    'status' => 'rejected',
                                    'meta' => $meta,
                                ])->save();

                                self::sendRejectedEmail($record, $reason);

                                $action->success();
                                try {
                                    $action->getLivewire()->dispatch('$refresh');
                                    $action->getLivewire()->dispatch('refreshTable');
                                } catch (\Throwable $e) {
                                }

                                return redirect(ListNhsApprovals::getUrl());
                            }),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNhsApprovals::route('/'),
            'view' => ViewNhsApproval::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    private static function sendApprovedEmail(NhsApplication $record): void
    {
        $to = trim((string) ($record->email ?? ''));
        if ($to === '') return;

        $name = trim((string) (($record->first_name ?? '') . ' ' . ($record->last_name ?? '')));
        if ($name === '') $name = 'there';

        $subject = 'Your NHS prescription sign up is approved';

        $body = implode("\n\n", [
            "Hello {$name},",
            "Thank you for signing up with Pharmacy Express for your NHS prescription service.",
            "You have now signed up to us, order your NHS prescription as normal, it’ll come to us for dispensing and delivery.",
            "If you need to update your details or have any questions, reply to this email and our team will help.",
            "Kind regards,\nPharmacy Express",
        ]);

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('Failed to send NHS approval email', [
                'email' => $to,
                'id' => $record->id ?? null,
                'err' => $e->getMessage(),
            ]);
        }
    }

    private static function sendRejectedEmail(NhsApplication $record, string $reason): void
    {
        $to = trim((string) ($record->email ?? ''));
        if ($to === '') return;

        $name = trim((string) (($record->first_name ?? '') . ' ' . ($record->last_name ?? '')));
        if ($name === '') $name = 'there';

        $subject = 'Update on your NHS prescription sign up';

        $reasonText = $reason !== '' ? $reason : 'A reason was not provided.';

        $body = implode("\n\n", [
            "Hello {$name},",
            "Thank you for signing up with Pharmacy Express for your NHS prescription service.",
            "We have reviewed your application and we are unable to approve it at this time.",
            "Reason",
            $reasonText,
            "If you believe this is a mistake or you would like to resubmit with updated information, reply to this email and we will help.",
            "Kind regards,\nPharmacy Express",
        ]);

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('Failed to send NHS rejection email', [
                'email' => $to,
                'id' => $record->id ?? null,
                'err' => $e->getMessage(),
            ]);
        }
    }

    private static function metaArray($raw): array
    {
        if (is_array($raw)) return $raw;
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($d)) return $d;
        }
        return [];
    }

    private static function boolish($v): ?bool
    {
        if ($v === null || $v === '') return null;
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int) $v) === 1;
        if (is_string($v)) {
            $lx = strtolower(trim($v));
            if (in_array($lx, ['1', 'true', 'yes', 'y'], true)) return true;
            if (in_array($lx, ['0', 'false', 'no', 'n'], true)) return false;
        }
        return null;
    }

    private static function pickFirstTruthy(array $candidates, $default = null)
    {
        foreach ($candidates as $x) {
            if ($x === null) continue;
            if (is_string($x) && trim($x) === '') continue;
            return $x;
        }
        return $default;
    }

    private static function resolveUseAltDelivery(NhsApplication $r): bool
    {
        $meta = self::metaArray($r->meta ?? []);
        $candidates = [
            $r->use_alt_delivery ?? null,
            Arr::get($meta, 'use_alt_delivery'),
            Arr::get($meta, 'use_alt_delivery_flag'),
            Arr::get($meta, 'use_alt_delivery.value'),
            Arr::get($meta, 'consents.flags.use_alt_delivery'),
        ];
        foreach ($candidates as $x) {
            $b = self::boolish($x);
            if ($b !== null) return $b;
        }
        return false;
    }

    private static function formatExemption($value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) $value = (string) $value;

        $map = [
            'pays' => 'The patient pays for their prescriptions',
            'over60_or_under16' => 'The patient is 60 years or over or under 16',
            '16to18_education' => 'The patient is 16, 17 or 18 and in full-time education',
            'maternity' => 'Maternity exemption certificate',
            'medical' => 'Medical exemption certificate',
            'ppc' => 'Prescription prepayment certificate',
            'hrt_ppc' => 'HRT only prescription prepayment certificate',
            'mod' => 'Ministry of Defence prescription exemption certificate',
            'hc2' => 'HC2 certificate',
            'income_support' => 'Income Support or Income related Employment and Support Allowance',
            'jobseekers' => 'Income based Jobseekers Allowance',
            'tax_credit' => 'Tax Credit exemption certificate',
            'pension_credit' => 'Pension Credit Guarantee Credit',
            'universal_credit' => 'Universal Credit and meets the eligibility criteria',
        ];

        $k = strtolower(trim($value));
        if (array_key_exists($k, $map)) return $map[$k];

        return ucwords(str_replace(['_', '-'], ' ', $k));
    }
}