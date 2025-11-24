<?php

namespace App\Support;

use Carbon\Carbon;
use App\Models\Order;

class ZplLabelBuilder
{
    public function forOrder(array|Order $data): string
    {
        // If an Order model is passed, map it to the expected data array
        if ($data instanceof Order) {
            $order = $data;

            // Attempt to pull common fields from order meta, with safe fallbacks
            $line1 = (string) (
                data_get($order->meta, 'line1')
                ?? data_get($order->meta, 'medication')
                ?? ''
            );

            $directions = (string) (
                data_get($order->meta, 'directions')
                ?? data_get($order->meta, 'sig')
                ?? ''
            );

            $warning = (string) (data_get($order->meta, 'warning') ?? '');

            $patient = trim(
                (string)($order->shipping_address->first_name ?? '') . ' ' .
                (string)($order->shipping_address->last_name ?? '')
            );

            // Sender details configurable with sensible defaults
            $pharmacy = (string) (config('app.pharmacy.sender')
                ?? 'SafeScript Pharmacy  Unit 4  WF1 2UY');
            $phone = (string) (config('app.pharmacy.phone') ?? '01924971414');

            $dateText = Carbon::now('Europe/London')->format('d/m/y');
            $qr = $order->reference ?? null;

            $data = [
                'line1'      => $line1,
                'directions' => $directions,
                'warning'    => $warning,
                'patient'    => $patient,
                'pharmacy'   => $pharmacy,
                'phone'      => $phone,
                'date_text'  => $dateText,
                'qr'         => $qr,
            ];
        }

        // expected keys for array input: line1, directions, warning, patient, pharmacy, phone, date_text, qr(optional)
        $line1      = mb_strtoupper((string)($data['line1'] ?? ''));
        $directions = (string)($data['directions'] ?? '');
        $warning    = (string)($data['warning'] ?? '');
        $patient    = (string)($data['patient'] ?? '');
        $pharmacy   = (string)($data['pharmacy'] ?? '');
        $phone      = (string)($data['phone'] ?? '');
        $dateText   = (string)($data['date_text'] ?? Carbon::now('Europe/London')->format('d/m/y'));
        $qr         = $data['qr'] ?? null;

        // 600 dpi width 600, height ~400. Adjust to your label.
        return "^XA
^PW600
^LH0,0
^CF0,28
^FO30,20^FB540,2,0,L,0^FD{$line1}^FS
^CF0,36
^FO30,100^FB540,1,0,C,0^FD" . mb_strtoupper($directions) . "^FS
^CF0,24
^FO30,150^FB540,2,0,L,0^FD{$warning}^FS
^CF0,30
^FO30,230^FD{$patient}^FS
^CF0,24
^FO30,270^FB540,2,0,L,0^FD{$pharmacy}\\& Tel: {$phone}^FS
^CF0,28
^FO480,310^FD{$dateText}^FS
" . ($qr ? "^BQN,2,6^FO480,200^FDLA,{$qr}^FS\n" : '') . "^XZ";
    }
}