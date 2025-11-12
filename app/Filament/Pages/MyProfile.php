<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Notifications\Notification;

class MyProfile extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user';
    protected static ?string $navigationLabel = 'My Profile';
    protected static ?string $title = 'My Profile';
    protected static ?string $slug = 'my-profile';
    protected static ?int $navigationSort = 999;

    public ?array $data = [];

    public function mount(): void
    {
        $u = auth()->user();
        $this->form->fill([
            'pharmacist_display_name' => $u->pharmacist_display_name ?? $u->name,
            'gphc_number'             => $u->gphc_number,
            'signature_path'          => $u->signature_path,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('pharmacist_display_name')
                    ->label('Pharmacist Name')
                    ->required(),
                Forms\Components\TextInput::make('gphc_number')
                    ->label('GPhC Number')
                    ->regex('/^\d{5,}$/') // tweak as needed
                    ->nullable(),
                Forms\Components\FileUpload::make('signature_path')
                    ->label('Signature')
                    ->image()
                    ->imageEditor()
                    ->directory('signatures')
                    ->disk('public')
                    ->nullable(),
            ])
            ->statePath('data');
    }

    protected static string $view = 'filament.pages.my-profile';

    public function save(): void
    {
        $u = auth()->user();
        $u->pharmacist_display_name = $this->data['pharmacist_display_name'] ?? $u->pharmacist_display_name;
        $u->gphc_number             = $this->data['gphc_number'] ?? $u->gphc_number;
        $u->signature_path          = $this->data['signature_path'] ?? $u->signature_path;
        $u->save();

        Notification::make()->title('Profile updated')->success()->send();
    }
}