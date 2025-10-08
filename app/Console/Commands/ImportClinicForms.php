<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ClinicForm;

class ImportClinicForms extends Command
{
    protected $signature = 'clinic-forms:import {path=storage/app/clinic_forms_backup.json} {--truncate}';
    protected $description = 'Import clinic_forms from a JSON file';

    public function handle(): int
    {
        $path = $this->argument('path');
        $contents = \Storage::get(str_starts_with($path, 'storage/') ? substr($path, 8) : $path);
        $rows = json_decode($contents, true) ?? [];
        if ($this->option('truncate')) {
            ClinicForm::truncate();
        }
        foreach ($rows as $row) {
            ClinicForm::updateOrCreate(
                ['id' => $row['id'] ?? null],
                collect($row)->only([
                    'name','description','schema','form_type','service_slug','treatment_slug','version','is_active','created_at','updated_at'
                ])->toArray()
            );
        }
        $this->info('Imported '.count($rows).' forms from '.$path);
        return self::SUCCESS;
    }
}