<?php

namespace App\Console\Commands;

use App\Support\AdminNavigationCounts;
use Illuminate\Console\Command;

class WarmAdminNavigationCounts extends Command
{
    protected $signature = 'admin:warm-navigation-counts';

    protected $description = 'Warm cached Pending, Approved, and Completed admin counts';

    public function handle(): int
    {
        $counts = AdminNavigationCounts::refresh();

        $this->info(
            "Admin navigation counts warmed: pending {$counts['pending']}, approved {$counts['approved']}, completed {$counts['completed']}."
        );

        return self::SUCCESS;
    }
}
