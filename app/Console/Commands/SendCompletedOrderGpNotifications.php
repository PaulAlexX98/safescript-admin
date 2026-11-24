<?php

namespace App\Console\Commands;

use App\Models\ConsultationSession;
use App\Models\Order;
use App\Services\GpTreatmentNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SendCompletedOrderGpNotifications extends Command
{
    protected $signature = 'gp-notifications:send-completed
        {--from= : First completion date to include (YYYY-MM-DD)}
        {--to= : Last completion date to include (YYYY-MM-DD)}
        {--dry-run : Report what would happen without sending emails}
        {--chunk=50 : Number of orders to read at a time}';

    protected $description = 'Send missing GP treatment notifications for completed orders in a date range';

    public function handle(GpTreatmentNotification $notifications): int
    {
        $from = $this->dateOption('from', false);
        $to = $this->dateOption('to', true);

        if (! $from || ! $to) {
            return self::FAILURE;
        }

        if ($from->greaterThan($to)) {
            $this->error('The --from date must be on or before the --to date.');

            return self::FAILURE;
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);

        if ($chunk === false) {
            $this->error('The --chunk value must be between 1 and 500.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $query = Order::query()
            ->with('user')
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to]);
        $matched = (clone $query)->count();

        $this->info(sprintf(
            '%s completed orders from %s through %s.',
            number_format($matched),
            $from->toDateString(),
            $to->toDateString(),
        ));

        if (! $dryRun && ! $this->confirm('Send GP treatment notifications for eligible orders?', false)) {
            $this->warn('No emails were sent.');

            return self::SUCCESS;
        }

        $totals = [
            'eligible' => 0,
            'sent' => 0,
            'already_sent' => 0,
            'missing_gp_email' => 0,
            'missing_consultation' => 0,
            'failed' => 0,
        ];

        $query->lazyById($chunk)->each(function (Order $order) use ($notifications, $dryRun, &$totals): void {
            $meta = is_array($order->meta)
                ? $order->meta
                : (json_decode($order->meta ?? '[]', true) ?: []);

            if (filled(data_get($meta, 'gp_treatment_notification.sent_at'))) {
                $totals['already_sent']++;

                return;
            }

            if (! filter_var($notifications->recipientFor($order), FILTER_VALIDATE_EMAIL)) {
                $totals['missing_gp_email']++;

                return;
            }

            $session = $this->consultationFor($order, $meta);

            if (! $session) {
                $totals['missing_consultation']++;

                return;
            }

            $totals['eligible']++;

            if ($dryRun) {
                return;
            }

            try {
                if ($notifications->sendForCompletedOrder($order, $session)) {
                    $totals['sent']++;
                } else {
                    $totals['already_sent']++;
                }
            } catch (Throwable $e) {
                report($e);
                $totals['failed']++;
                $this->error(sprintf(
                    'Failed order %s: %s',
                    $order->reference ?: $order->getKey(),
                    $e->getMessage(),
                ));
            }
        });

        $this->table(
            ['Result', 'Orders'],
            [
                ['Eligible', $totals['eligible']],
                ['Sent', $totals['sent']],
                ['Already sent', $totals['already_sent']],
                ['Missing GP email', $totals['missing_gp_email']],
                ['Missing consultation', $totals['missing_consultation']],
                ['Failed', $totals['failed']],
            ],
        );

        if ($dryRun) {
            $this->warn('Dry run only: no emails were sent.');
        }

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function dateOption(string $name, bool $endOfDay): ?CarbonImmutable
    {
        $value = trim((string) $this->option($name));

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->error("The --{$name} option is required in YYYY-MM-DD format.");

            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (Throwable) {
            $date = null;
        }

        if (! $date || $date->toDateString() !== $value) {
            $this->error("The --{$name} option is not a valid date.");

            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    protected function consultationFor(Order $order, array $meta): ?ConsultationSession
    {
        $sessionId = data_get($meta, 'consultation_session_id');

        if ($sessionId && $session = ConsultationSession::query()->find($sessionId)) {
            return $session;
        }

        return ConsultationSession::query()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->first();
    }
}
