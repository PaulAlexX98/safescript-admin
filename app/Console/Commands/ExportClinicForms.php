<?php

namespace App\Console\Commands;

use Storage;
use Illuminate\Console\Command;
use App\Models\ClinicForm;

class ExportClinicForms extends Command
{
    protected $signature = 'clinic-forms:export {path=storage/app/clinic_forms_backup.json}';
    protected $description = 'Export all clinic_forms to a JSON file';

    public function handle(): int
    {
        $data = ClinicForm::orderBy('id')->get()->toArray();
        $path = $this->argument('path');
        Storage::put(str_starts_with($path, 'storage/') ? substr($path, 8) : $path, json_encode($data, JSON_PRETTY_PRINT));
        $this->info("Exported ".count($data)." forms to {$path}");
        return self::SUCCESS;
    }
}