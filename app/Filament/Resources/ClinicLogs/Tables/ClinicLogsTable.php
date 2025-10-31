<?php

namespace App\Filament\Resources\ClinicLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

class ClinicLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                BadgeColumn::make('type')
                    ->colors([
                        'success' => 'info',
                        'warning' => 'warning',
                        'danger'  => 'error',
                    ])
                    ->sortable(),
                TextColumn::make('subject')->limit(30)->searchable(),
                TextColumn::make('message')->limit(60)->tooltip(fn ($record) => $record->message),
                TextColumn::make('user_id')->label('User')->toggleable(),
                TextColumn::make('patient_id')->label('Patient')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->options(['info' => 'Info', 'warning' => 'Warning', 'error' => 'Error']),
                Filter::make('created_today')
                    ->label('Today')
                    ->query(fn (Builder $q) => $q->whereDate('created_at', now()->toDateString())),
            ])
            ->recordActions([
                
            ])
            ->toolbarActions([
             
               
            ]);
    }
}