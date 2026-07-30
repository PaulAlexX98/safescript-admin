<?php

namespace App\Console\Commands;

use App\Support\AdminNavigationCounts;
use Illuminate\Console\Command;

class WarmAdminNavigationCounts extends Command
{
    protected $signature = 'admin:warm-navigation-counts';

    protected $description = 'Warm cached private and NHS admin navigation counts';

    public function handle(): int
    {
        $counts = AdminNavigationCounts::refresh();

        $this->info(
            "Admin navigation counts warmed: pending {$counts['pending']}, approved {$counts['approved']}, completed {$counts['completed']}, NHS pending {$counts['nhs_pending']}, prescription approvals {$counts['prescription_approvals']}."
        );

        return self::SUCCESS;
    }
}
