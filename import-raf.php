<?php
use App\Models\ClinicForm;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$path = __DIR__.'/backups/raf_builder.json';
$payload = json_decode(file_get_contents($path), true);

$form = ClinicForm::find(4);
$form->update([
    'raf_schema' => $payload,
    'raf_version' => ($form->raf_version ?? 0) + 1,
]);

echo "✅ RAF schema imported successfully!\n";