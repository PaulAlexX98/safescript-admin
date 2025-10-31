<?php

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Pages\Page;
use Filament\Forms\Components\Select;
use App\Support\Settings;

class AppearanceSettings extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-swatch';
    protected static string | \UnitEnum | null $navigationGroup = 'Front';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.pages.appearance-settings';
    protected static ?string $title = 'Appearance';
    protected ?string $heading = 'Appearance';
    protected ?string $subheading = 'Choose site-wide display options';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'card_theme' => Settings::get('card_theme', 'sky'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Cards')
                    ->schema([
                        Select::make('card_theme')
                            ->label('Card colour')
                            ->options([
                                'sky'   => 'Blue',
                                'amber' => 'Amber',
                                'green' => 'Green',
                                'gray'  => 'Gray',
                            ])
                            ->default('sky')
                            ->helperText('Controls the colour of “Card” blocks across the site.')
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        Settings::set('card_theme', $state['card_theme'] ?? 'sky');
        $this->dispatch('notify', status: 'success', title: 'Saved', body: 'Appearance updated.');
    }
}
