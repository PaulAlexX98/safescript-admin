<?php

namespace App\Filament\Resources\ClinicForms\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

class ClinicFormForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(12) // 8/4 layout
            ->schema([
                // ===== LEFT COLUMN (8) =====
                \Filament\Schemas\Components\Section::make()
                    ->schema([
                        Builder::make('schema')
                            ->label('Form canvas')
                            ->addActionLabel('Add to form structure')
        
                            ->blocks([
                                // Sections
                                Block::make('section')->label('Section')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Section title')
                                        ->reactive()
                                        ->afterStateUpdated(function ($set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\Textarea::make('summary')->label('Summary (optional)')->rows(2),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Section key')
                                        ->helperText('All fields after this block belong to this section until the next Section block')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                // Inputs
                                Block::make('text_input')->label('Text Input')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive()
                                        ->afterStateUpdated(function ($set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\TextInput::make('placeholder')->label('Placeholder Text'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\Toggle::make('hidden')->label('Hidden')->default(false),
                                    Forms\Components\Toggle::make('disabled')->label('Disabled')->default(false),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('email')->label('Email')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive()
                                        ->afterStateUpdated(function ($set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\TextInput::make('placeholder')->label('Placeholder Text'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('number')->label('Number')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive()
                                        ->afterStateUpdated(function ($set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\TextInput::make('placeholder')->label('Placeholder Text'),
                                    Forms\Components\TextInput::make('min')->numeric()->label('Min'),
                                    Forms\Components\TextInput::make('max')->numeric()->label('Max'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('textarea')->label('Textarea')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive()
                                        ->afterStateUpdated(function ($set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\Textarea::make('placeholder')->label('Placeholder Text')->rows(2),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\Toggle::make('hidden')->label('Hidden')->default(false),
                                    Forms\Components\Toggle::make('disabled')->label('Disabled')->default(false),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('date')->label('Date')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive()
                                        ->afterStateUpdated(function ($set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\DatePicker::make('date')->label('Select Date')->native(false)->displayFormat('d-m-Y'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                // Choices
                                Block::make('select')->label('Select')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive()
                                        ->afterStateUpdated(function ($set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\Repeater::make('options')->label('Options')->schema([
                                        Forms\Components\TextInput::make('value')->label('Value')->required(),
                                        Forms\Components\TextInput::make('label')->label('Label'),
                                    ])->addActionLabel('Add option')->reorderable()->minItems(1),
                                    Forms\Components\Toggle::make('multiple')->label('Allow multiple')->default(false),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('radio')->label('Radio Buttons')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive()
                                        ->afterStateUpdated(function ($set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\Repeater::make('options')->label('Options')->schema([
                                        Forms\Components\TextInput::make('value')->label('Value')->required(),
                                        Forms\Components\TextInput::make('label')->label('Label')->required(),
                                    ])->addActionLabel('Add option')->reorderable()->minItems(1),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('checkbox')->label('Checkbox')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Label')
                                        ->reactive()
                                        ->afterStateUpdated(function (\Filament\Forms\Set $set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                // Uploads & Signature
                                Block::make('file_upload')->label('File Upload')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive()
                                        ->afterStateUpdated(function (\Filament\Forms\Set $set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\Toggle::make('multiple')->label('Allow multiple')->default(false),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(false),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2),
                                    Forms\Components\TextInput::make('accept')
                                        ->label('Allowed file types')
                                        ->placeholder('image/*,application/pdf')
                                        ->helperText('Comma-separated, e.g. application/pdf,image/*')
                                        ->default('image/*,application/pdf'),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('signature')->label('Signature')->schema([
                                    // Live signature preview canvas (mouse / touch drawing)
                                    Forms\Components\TextInput::make('label')
                                        ->label('Field Label')
                                        ->reactive()
                                        ->afterStateUpdated(function (\Filament\Forms\Set $set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\ViewField::make('signature_pad')
                                        ->view('forms.components.signature-pad'),
                                    Forms\Components\Textarea::make('help')->label('Help Text')->rows(2)->default('Draw your signature above'),
                                    Forms\Components\Toggle::make('required')->label('Required')->default(true),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                // Content
                                Block::make('text_block')->label('Text Block')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Block label')
                                        ->reactive()
                                        ->afterStateUpdated(function (\Filament\Forms\Set $set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\RichEditor::make('content')
                                        ->label('Content')
                                        ->toolbarButtons([
                                            'bold',
                                            'italic',
                                            'underline',
                                            'strike',
                                            'h2',
                                            'h3',
                                            'blockquote',
                                            'bulletList',
                                            'orderedList',
                                            'link',
                                            'undo',
                                            'redo',
                                        ])
                                        ->required(),
                                    Forms\Components\Select::make('align')->options([
                                        'left' => 'Left',
                                        'center' => 'Center',
                                        'right' => 'Right',
                                    ])->default('left')->label('Alignment'),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('divider')->label('Divider')->schema([]),
                                Block::make('image')->label('Image')->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->label('Block label')
                                        ->reactive()
                                        ->afterStateUpdated(function (\Filament\Forms\Set $set, $state) {
                                            $set('key', \Illuminate\Support\Str::slug((string) $state));
                                        }),
                                    Forms\Components\FileUpload::make('image')->image()->directory('clinic-forms/blocks')->required(),
                                    Forms\Components\TextInput::make('alt')->label('Alt text'),
                                    Forms\Components\TextInput::make('key')
                                        ->label('Field key')
                                        ->helperText('Stable key used for conditions')
                                        ->default(function ($get) {
                                            $lbl = (string) ($get('label') ?? '');
                                            return $lbl !== '' ? \Illuminate\Support\Str::slug($lbl) : null;
                                        }),
                                    Forms\Components\Toggle::make('showIf.enabled')->label('Conditional logic')->reactive(),
                                    Forms\Components\TextInput::make('showIf.field')->label('Depends on field key')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.equals')->label('Equals value')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TagsInput::make('showIf.in')->label('Any of values')->placeholder('Add value and press Enter')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\TextInput::make('showIf.notEquals')->label('Not equals')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                    Forms\Components\Toggle::make('showIf.truthy')->label('Truthy')->hidden(fn ($get) => ! (bool) $get('showIf.enabled')),
                                ]),
                                Block::make('page_break')->label('Page Break')->schema([]),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpan(8),

                // ===== RIGHT COLUMN (4) =====
                \Filament\Schemas\Components\Section::make()
                    ->schema([
                        \Filament\Schemas\Components\Section::make('Information')
                            ->collapsible()
                            ->collapsed(false)
                            ->schema([
                                Forms\Components\TextInput::make('name')->label('Form title')->required(),
                                Forms\Components\Select::make('form_type')
                                    ->label('Form type')
                                    ->options([
                                        'raf' => 'RAF',
                                        'advice' => 'Consultation Advice',
                                        'pharmacist_declaration' => 'Pharmacist Declaration',
                                        'clinical_notes' => 'Clinical Notes',
                                        'reorder' => 'Reorder Form',
                                    ])
                                    ->required()
                                    ->native(false)
                                    ->helperText('Choose what kind of clinic form this is.'),
                                Forms\Components\Textarea::make('description')->label('Description')->rows(3),
                            ]),
                        \Filament\Schemas\Components\Section::make('Form Structure')
                            ->collapsible()
                            ->collapsed(false)
                            ->headerActions([
                                \Filament\Actions\Action::make('import_json')
                                    ->label('Import JSON')
                                    ->icon('heroicon-o-arrow-down-tray')
                                    ->modalHeading('Import form from JSON')
                                    ->modalSubmitActionLabel('Import')
                                    ->form([
                                        \Filament\Forms\Components\Textarea::make('json')
                                            ->label('Paste JSON here')
                                            ->rows(14)
                                            ->required()
                                            ->helperText('Paste the exported array (e.g. travelClinicRafForm).'),
                                    ])
                                    ->action(function (array $data, \Filament\Schemas\Components\Utilities\Set $set) {
                                        try {
                                            $raw = $data['json'] ?? '';
                                            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                                            // Accept either an array of sections or direct fields
                                            $sections = [];
                                            if (is_array($decoded)) {
                                                if (isset($decoded[0]['fields'])) {
                                                    $sections = $decoded; // sections array
                                                } else {
                                                    $sections = [[
                                                        'id' => 'import', 'title' => 'Imported', 'fields' => $decoded,
                                                    ]]; // fields array
                                                }
                                            }

                                            $blocks = [];
                                            $slug = function (string $s) {
                                                $s = strtolower(trim($s));
                                                $s = preg_replace('/[^a-z0-9]+/', '-', $s);
                                                return trim($s, '-');
                                            };
                                            $sectionIndex = 1;

                                            foreach ($sections as $section) {
                                                $fields = $section['fields'] ?? [];
                                                if (!is_array($fields)) continue;

                                                $sectionTitle = (string)($section['title'] ?? $section['name'] ?? ('Section ' . $sectionIndex));
                                                $sectionKey = $slug($sectionTitle);

                                                // Insert a Section header block
                                                $blocks[] = [
                                                    'type' => 'section',
                                                    'data' => [
                                                        'label' => $sectionTitle,
                                                        'key' => $sectionKey,
                                                        'summary' => $section['summary'] ?? ($section['description'] ?? null),
                                                    ],
                                                ];

                                                foreach ($fields as $f) {
                                                    $type = $f['type'] ?? 'text_input';
                                                    $label = $f['label'] ?? ($f['id'] ?? ucfirst($type));
                                                    $required = (bool)($f['required'] ?? false);
                                                    $help = $f['help'] ?? null;

                                                    switch ($type) {
                                                        case 'multiselect':
                                                            $options = [];
                                                            foreach (($f['options'] ?? []) as $opt) {
                                                                if (is_array($opt)) {
                                                                    $val = $opt['value'] ?? ($opt['label'] ?? '');
                                                                    $lab = $opt['label'] ?? $val;
                                                                } else {
                                                                    $val = $slug((string)$opt);
                                                                    $lab = (string)$opt;
                                                                }
                                                                if ($val !== '') {
                                                                    $options[] = ['value' => (string)$val, 'label' => (string)$lab];
                                                                }
                                                            }
                                                            $blocks[] = [
                                                                'type' => 'select',
                                                                'data' => [
                                                                    'label' => $label,
                                                                    'key' => $slug($label),
                                                                    'section' => $sectionKey,
                                                                    'options' => $options,
                                                                    'multiple' => true,
                                                                    'required' => $required,
                                                                    'help' => $help,
                                                                ],
                                                            ];
                                                            break;

                                                        case 'date':
                                                            $blocks[] = [
                                                                'type' => 'date',
                                                                'data' => [
                                                                    'label' => $label,
                                                                    'key' => $slug($label),
                                                                    'section' => $sectionKey,
                                                                    'required' => $required,
                                                                    'help' => $help,
                                                                ],
                                                            ];
                                                            break;

                                                        case 'textarea':
                                                            $blocks[] = [
                                                                'type' => 'textarea',
                                                                'data' => [
                                                                    'label' => $label,
                                                                    'key' => $slug($label),
                                                                    'section' => $sectionKey,
                                                                    'placeholder' => $f['placeholder'] ?? null,
                                                                    'required' => $required,
                                                                    'help' => $help,
                                                                ],
                                                            ];
                                                            break;

                                                        case 'radio':
                                                            $options = [];
                                                            foreach (($f['options'] ?? []) as $opt) {
                                                                if (is_array($opt)) {
                                                                    $lab = (string) ($opt['label'] ?? ($opt['value'] ?? ''));
                                                                    $val = (string) ($opt['value'] ?? $slug($lab));
                                                                } else {
                                                                    $lab = (string) $opt;
                                                                    $val = (string) $slug($lab);
                                                                }
                                                                if ($val !== '') {
                                                                    $options[] = ['value' => $val, 'label' => $lab];
                                                                }
                                                            }
                                                            $blocks[] = [
                                                                'type' => 'radio',
                                                                'data' => [
                                                                    'label' => $label,
                                                                    'key' => $slug($label),
                                                                    'section' => $sectionKey,
                                                                    'options' => $options,
                                                                    'required' => $required,
                                                                    'help' => $help,
                                                                ],
                                                            ];
                                                            if (!empty($f['details']['label'] ?? null)) {
                                                                $parentKey = $slug($label);
                                                                $showIfIn = (array) ($f['details']['showIfIn'] ?? []);
                                                                $showVals = [];
                                                                foreach ($showIfIn as $sv) {
                                                                    $showVals[] = is_string($sv) ? $slug($sv) : $slug((string) $sv);
                                                                }
                                                                $blocks[] = [
                                                                    'type' => 'textarea',
                                                                    'data' => [
                                                                        'label' => (string) $f['details']['label'],
                                                                        'key' => $slug((string) $f['details']['label']),
                                                                        'section' => $sectionKey,
                                                                        'placeholder' => $f['details']['placeholder'] ?? null,
                                                                        'required' => false,
                                                                        'help' => $showVals ? ('Shown if selected: ' . implode(', ', $showVals)) : null,
                                                                        'showIf' => [
                                                                            'field' => $parentKey,
                                                                            'in' => $showVals,
                                                                        ],
                                                                    ],
                                                                ];
                                                            }
                                                            break;

                                                        case 'file':
                                                            $blocks[] = [
                                                                'type' => 'file_upload',
                                                                'data' => [
                                                                    'label' => $label,
                                                                    'key' => $slug($label),
                                                                    'section' => $sectionKey,
                                                                    'multiple' => (bool)($f['multiple'] ?? false),
                                                                    'required' => $required,
                                                                    'help' => $help,
                                                                    'accept' => $f['accept'] ?? 'image/*,application/pdf',
                                                                ],
                                                            ];
                                                            break;

                                                        default:
                                                            $blocks[] = [
                                                                'type' => 'text_input',
                                                                'data' => [
                                                                    'label' => $label,
                                                                    'key' => $slug($label),
                                                                    'section' => $sectionKey,
                                                                    'placeholder' => $f['placeholder'] ?? null,
                                                                    'required' => $required,
                                                                    'help' => $help,
                                                                ],
                                                            ];
                                                            break;
                                                    }
                                                }

                                                $sectionIndex++;
                                            }

                                            if (empty($blocks)) {
                                                throw new \RuntimeException('No fields found to import.');
                                            }

                                            $set('schema', $blocks);
                                            \Filament\Notifications\Notification::make()->title('Form imported')->success()->send();
                                        } catch (\Throwable $e) {
                                            \Filament\Notifications\Notification::make()->title('Import failed')->body($e->getMessage())->danger()->send();
                                        }
                                    }),
                            ])
                            ->schema([
                                Forms\Components\Placeholder::make('structure_help')
                                    ->label('Structure')
                                    ->hiddenLabel()
                                    ->content(function (Get $get) {
                                        $blocks = $get('schema') ?? [];
                                        if (! is_array($blocks) || empty($blocks)) {
                                            return 'No fields yet.';
                                        }
                                    
                                        $lines = [];
                                        $n = 1;
                                        foreach (array_values($blocks) as $block) {
                                            $type = \Illuminate\Support\Arr::get($block, 'type', 'field');
                                            $label = trim((string) \Illuminate\Support\Arr::get($block, 'data.label', ''));
                                            if ($label === '') {
                                                $label = \Illuminate\Support\Str::headline((string) $type) . ' ' . $n;
                                            }
                                            $isReq = (bool) \Illuminate\Support\Arr::get($block, 'data.required', false);
                                            $reqBadge = $isReq ? ' <span style="display:inline-block;padding:.05rem .35rem;border-radius:.25rem;background:rgba(239,68,68,.12);color:#f87171;font-weight:600;margin-left:.35rem;">required</span>' : '';
                                            $lines[] = $n . '. ' . e($label) . $reqBadge . ' <small style="opacity:.7">(' . e((string) $type) . ')</small>';
                                            $n++;
                                        }
                                    
                                        return new \Illuminate\Support\HtmlString(implode('<br>', $lines));
                                    }),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpan(4),
            ]);
    }
}
