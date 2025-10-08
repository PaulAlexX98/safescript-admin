<?php

namespace App\Filament\Resources\ClinicLogs\Tables;

use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

class ClinicLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\BadgeColumn::make('type')
                    ->colors([
                        'success' => 'info',
                        'warning' => 'warning',
                        'danger'  => 'error',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('subject')->limit(30)->searchable(),
                Tables\Columns\TextColumn::make('message')->limit(60)->tooltip(fn ($record) => $record->message),
                Tables\Columns\TextColumn::make('user_id')->label('User')->toggleable(),
                Tables\Columns\TextColumn::make('patient_id')->label('Patient')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(['info' => 'Info', 'warning' => 'Warning', 'error' => 'Error']),
                Tables\Filters\Filter::make('created_today')
                    ->label('Today')
                    ->query(fn (Builder $q) => $q->whereDate('created_at', now()->toDateString())),
            ])
            ->actions([
                
            ])
            ->bulkActions([
             
               
            ]);
    }
}