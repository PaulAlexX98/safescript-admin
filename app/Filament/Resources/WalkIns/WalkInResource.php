<?php

namespace App\Filament\Resources\WalkIns;

use App\Filament\Resources\WalkIns\Pages\CreateWalkIn;
use App\Filament\Resources\WalkIns\Pages\EditWalkIn;
use App\Filament\Resources\WalkIns\Pages\ListWalkIns;
use App\Filament\Resources\WalkIns\Pages\ViewWalkIn;
use App\Filament\Resources\WalkIns\Schemas\WalkInForm;
use App\Filament\Resources\WalkIns\Schemas\WalkInInfolist;
use App\Filament\Resources\WalkIns\Tables\WalkInsTable;
use App\Models\Order;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as SchemaFacade;

class WalkInResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Walk In';

    protected static ?string $modelLabel = 'Walk In';

    protected static ?string $pluralModelLabel = 'Walk Ins';

    protected static string|UnitEnum|null $navigationGroup = 'Walk Ins';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = static::getEloquentQuery()->count();
        } catch (\Throwable $e) {
            $count = 0;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $hasType = SchemaFacade::hasColumn('orders', 'type');
        $hasSource = SchemaFacade::hasColumn('orders', 'source');
        $hasAppointmentType = SchemaFacade::hasColumn('orders', 'appointment_type');
        $hasIsWalkIn = SchemaFacade::hasColumn('orders', 'is_walk_in');
        $hasMeta = SchemaFacade::hasColumn('orders', 'meta');

        return $query
            ->where(function (Builder $query) use ($hasType, $hasSource, $hasAppointmentType, $hasIsWalkIn, $hasMeta) {
                if ($hasType) {
                    $query->orWhere('type', 'walk_in');
                }

                if ($hasSource) {
                    $query->orWhere('source', 'walk_in');
                }

                if ($hasAppointmentType) {
                    $query->orWhere('appointment_type', 'walk_in');
                }

                if ($hasIsWalkIn) {
                    $query->orWhere('is_walk_in', 1);
                }

                if ($hasMeta) {
                    $query->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))) = 'walk_in'")
                        ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source'))) = 'walk_in'")
                        ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.appointment_type'))) = 'walk_in'")
                        ->orWhereRaw("JSON_EXTRACT(meta, '$.is_walk_in') = true")
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.is_walk_in')) = '1'");
                }
            })
            ->latest('id');
    }

    public static function form(Schema $schema): Schema
    {
        return WalkInForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WalkInInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WalkInsTable::configure($table)
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalkIns::route('/'),
            'create' => CreateWalkIn::route('/create'),
            'view' => ViewWalkIn::route('/{record}'),
            'edit' => EditWalkIn::route('/{record}/edit'),
        ];
    }
}
