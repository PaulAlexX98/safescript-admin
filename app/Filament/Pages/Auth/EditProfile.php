<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Illuminate\Support\Facades\Storage;
use Filament\Forms\Components\Hidden;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),

                TextInput::make('pharmacist_display_name')
                    ->label('Pharmacist Name')
                    ->required(),

                TextInput::make('gphc_number')
                    ->label('GPhC Number')
                    ->regex('/^\d{5,}$/')
                    ->nullable(),

                Hidden::make('signature_path')
                    ->dehydrated(true),

                ViewField::make('signature_path')
                    ->label('Signature')
                    ->view('components.signature-pad')
                    ->dehydrated(false)
                    ->columnSpan('full'),
            ]);
    }

    // Persist the canvas drawing as a PNG on the public disk
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['signature_path']) && is_string($data['signature_path']) && str_starts_with($data['signature_path'], 'data:image')) {
            [$meta, $b64] = explode(',', $data['signature_path'], 2) + [null, null];
            if ($b64) {
                $png = base64_decode($b64);
                $rel = 'signatures/user-'.auth()->id().'-'.now()->timestamp.'.png';
                \Illuminate\Support\Facades\Storage::disk('public')->put($rel, $png, 'public');
                $data['signature_path'] = $rel;
            }
        }

        if (($data['signature_path'] ?? null) === null || $data['signature_path'] === '') {
            unset($data['signature_path']); // don’t wipe an existing signature on empty canvas
        }

        return $data;
    }
}