<?php

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use Filament\Forms\Components\RichEditor\TextColor;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(12) // 8/4 layout
            ->schema([

                // ===== LEFT COLUMN (8) =====
                Section::make()
                    ->schema([

                        Forms\Components\TextInput::make('title')
                            ->label('Title')
                            ->required()
                            ->maxLength(190)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                                $set('slug', $get('slug') ?: \Illuminate\Support\Str::slug($state ?? ''));
                            }),

                        Forms\Components\TextInput::make('slug')
                            ->label('Permalink')
                            ->prefix(url('/'))
                            ->helperText('Used in the page URL')
                            ->unique(ignoreRecord: true)
                            ->required(),

                        Forms\Components\Textarea::make('description')
                            ->rows(4)
                            ->helperText('Short summary used in listings.'),

                        Forms\Components\Hidden::make('content')
                            ->dehydrated(true),

                        // Editor mode switch (form-only state, not persisted)
                        Forms\Components\ToggleButtons::make('edit_mode')
                            ->label('Editor mode')
                            ->inline()
                            ->options([
                                'raw'  => 'Raw HTML',
                                'rich' => 'Rich editor',
                            ])
                            ->icons([
                                'raw'  => 'heroicon-m-code-bracket',
                                'rich' => 'heroicon-m-pencil-square',
                            ])
                            ->default('raw')
                            ->live()
                            ->helperText('Use Raw HTML for shortcodes like [youtube-video]...; use Rich editor for plain text formatting.')
                            ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                // When switching to Rich, seed the rich editor from the canonical content
                                if ($state === 'rich') {
                                    $set('content_rich', (string) ($get('content') ?? ''));
                                }
                                // When switching back to Raw, ensure the latest rich value is in content
                                if ($state === 'raw') {
                                    $rich = $get('content_rich');
                                    if (is_string($rich) && $rich !== '') {
                                        $set('content', $rich);
                                    }
                                }
                            }),

                        // Raw HTML (best for shortcodes)
                        Forms\Components\Textarea::make('content')
                            ->label('Content (HTML + shortcodes)')
                            ->rows(22)
                            ->visible(fn (Get $get) => $get('edit_mode') === 'raw')
                            ->formatStateUsing(function ($state) {
                                // Ensure the textarea always receives a string
                                if (is_string($state)) return $state;
                                if (is_array($state)) {
                                    // If legacy editors stored structured content, fall back to empty string
                                    // or try common keys:
                                    return $state['html'] ?? $state['content'] ?? '';
                                }
                                return (string) ($state ?? '');
                            })
                            ->dehydrateStateUsing(fn ($state) => is_string($state) ? $state : (is_array($state) ? ($state['html'] ?? $state['content'] ?? '') : (string) $state))
                            ->helperText('Paste HTML here. Supports [youtube-video]… and [google-map]… shortcodes.'),

                        // Rich editor (stores HTML) bound to a separate key; syncs into `content`
                        Forms\Components\RichEditor::make('content_rich')
                            ->label('Content (rich)')
                            // initialise from the canonical `content` string
                            ->formatStateUsing(fn ($state, Get $get) => is_string($state) ? $state : (string) ($get('content') ?? ''))
                            ->afterStateHydrated(function (Set $set, Get $get, $state) {
                                if (! is_string($state) || $state === '') {
                                    $set('content_rich', (string) ($get('content') ?? ''));
                                }
                            })
                            // whenever the rich editor changes, push the HTML into `content`
                            ->afterStateUpdated(function (Set $set, $state) {
                                $set('content', is_string($state) ? $state : (is_array($state) ? ($state['html'] ?? $state['content'] ?? '') : (string) $state));
                            })
                            ->visible(fn (Get $get) => $get('edit_mode') === 'rich')
                            ->columnSpanFull()
                            ->extraAttributes([
                                'style' => 'height: 70vh; max-height: 820px; overflow: auto;',
                            ])
                            ->placeholder('Write content here (no raw HTML or shortcodes).')
                            ->fileAttachmentsDisk('public')
                            ->fileAttachmentsDirectory('clinic-forms/content')
                            ->fileAttachmentsVisibility('public')
                            ->fileAttachmentsAcceptedFileTypes(['image/png','image/jpeg','image/gif','image/webp'])
                            ->fileAttachmentsMaxSize(12 * 1024)
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript'],
                                ['h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
                                ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                                ['table', 'attachFiles', 'textColor'],
                                ['undo', 'redo'],
                            ])
                            // contextual toolbars for tables, headings, and paragraphs
                            ->floatingToolbars([
                                'table' => [
                                    'tableAddColumnBefore', 'tableAddColumnAfter', 'tableDeleteColumn',
                                    'tableAddRowBefore', 'tableAddRowAfter', 'tableDeleteRow',
                                    'tableMergeCells', 'tableSplitCell',
                                    'tableToggleHeaderRow',
                                    'tableDelete',
                                ],
                                'heading' => ['h1', 'h2', 'h3'],
                                'paragraph' => ['bold', 'italic', 'underline', 'strike', 'textColor'],
                            ])
                            
                            // color picker (and allow custom colors)
                            ->textColors([
                                '#111827' => 'Gray-900',
                                '#ef4444' => 'Red',
                                '#f59e0b' => 'Amber',
                                '#10b981' => 'Green',
                                '#0ea5e9' => 'Sky',
                                '#6366f1' => 'Indigo',
                                '#ec4899' => 'Pink',
                            ])
                            ->customTextColors()
                            ->helperText('Tip: Use “Attach” to insert images/files. Use the table button for tables and alignment buttons for layout. For custom HTML, shortcodes, or image width/height, switch to “Raw HTML” mode.'),

                        Forms\Components\FileUpload::make('gallery')
                            ->label('Gallery images')
                            ->image()
                            ->multiple()
                            ->downloadable()
                            ->reorderable()
                            ->directory('clinic-forms/gallery')
                            ->helperText('Optional image gallery.')
                            ->columnSpanFull(),

                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpan(8),

                // ===== RIGHT COLUMN (4) =====
                Section::make()
                    ->schema([

                        Section::make('Publish')
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->options([
                                        'draft'     => 'Draft',
                                        'published' => 'Published',
                                    ])
                                    ->default('published')
                                    ->required(),

                                Forms\Components\Select::make('template')
                                    ->options([
                                        'default' => 'Default',
                                        'WeightLossV1' => 'Weight Loss (V1)',
                                    ])
                                    ->default('default')
                                    ->required(),

                                Forms\Components\Select::make('visibility')
                                    ->options([
                                        'public'   => 'Public',
                                        'internal' => 'Internal',
                                        'private'  => 'Private',
                                    ])
                                    ->required(),

                                Forms\Components\Toggle::make('active')
                                    ->label('Active')
                                    ->default(true),
                            ])
                            ->collapsible(),

                        Section::make('Search Engine Optimize')
                            ->schema([
                                Forms\Components\TextInput::make('meta_title')
                                    ->label('Meta title')
                                    ->maxLength(60),

                                Forms\Components\Textarea::make('meta_description')
                                    ->label('Meta description')
                                    ->rows(3)
                                    ->maxLength(160),
                            ])
                            ->collapsible(),
                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpan(4),
            ]);
    }
}