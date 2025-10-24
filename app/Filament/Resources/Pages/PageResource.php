<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Pages;
use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Models\Page;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;   // v4 uses Schema here
use Filament\Tables;
use Filament\Actions;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema as DBSchema;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static \UnitEnum|string|null $navigationGroup = 'Front';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $recordTitleAttribute = 'title';
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return PageForm::configure($schema);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => url('/private-services/' . $record->slug))
                    ->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('template')->badge()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Created At')->date('d-m-Y')->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => fn ($state) => $state === 'draft',
                        'success' => fn ($state) => $state === 'published',
                    ])
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->label('Status')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'draft' => 'Draft', 'published' => 'Published',
                ]),
            ])
            ->actionsColumnLabel('Operations')
            ->actions([
                Actions\Action::make('view')
                    ->label('View')
                    ->url(fn ($record) => url('/private-services/' . $record->slug))
                    ->openUrlInNewTab(),
                Actions\Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-square-2-stack')
                    ->color('gray')
                    ->form([
                        Forms\Components\TextInput::make('title')
                            ->label('New title')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('slug')
                            ->label('New slug (optional)')
                            ->helperText('Leave blank to auto-generate from title')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data, Page $record): void {
                        // Clone model including JSON meta
                        $clone = $record->replicate();

                        // Title
                        $clone->title = trim($data['title'] ?? ($record->title . ' Copy'));

                        // Slug: provided or generated from title, then ensure uniqueness
                        $baseSlug = trim($data['slug'] ?? '') !== ''
                            ? Str::slug($data['slug'])
                            : Str::slug($clone->title);

                        $slug = $baseSlug !== '' ? $baseSlug : ('page-' . uniqid());
                        $i = 2;
                        while (Page::where('slug', $slug)->exists()) {
                            $slug = $baseSlug . '-' . $i;
                            $i++;
                        }
                        $clone->slug = $slug;

                        // Copy editor content fields so formatting/tables are preserved
                        $clone->content = $record->content;
                        if (DBSchema::hasColumn('pages', 'content_rich')) {
                            $clone->content_rich = $record->content_rich;
                        }
                        if (DBSchema::hasColumn('pages', 'edit_mode')) {
                            $clone->edit_mode = $record->edit_mode;
                        }
                        if (DBSchema::hasColumn('pages', 'rendered_html')) {
                            $clone->rendered_html = $record->rendered_html;
                        } else {
                            unset($clone->rendered_html);
                        }

                        // Persist
                        $clone->save();

                        // Redirect to edit the new page
                        redirect(static::getUrl('edit', ['record' => $clone]));
                    }),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}