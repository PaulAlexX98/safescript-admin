<?php

namespace App\Support;

use Carbon\Carbon;

class ZplLabelBuilder
{
    public function forOrder(array $data): string
    {
        $line1      = mb_strtoupper(trim($data['line1'] ?? ''));
        $directions = trim($data['directions'] ?? 'Use once a week same day as directed');
        $warning    = trim((string) ($data['warning'] ?? ''));
        $bottomWarning = 'Keep out of the reach and sight of children';
        $patient    = trim($data['patient'] ?? '');
        $pharmacy   = trim($data['pharmacy'] ?? 'Pharmacy Express FME51  Unit 4  WF1 2UY');
        $phone      = trim($data['phone'] ?? '01924971414');
        $dateText   = trim($data['date_text'] ?? Carbon::now('Europe/London')->format('d/m/y'));
        $qr         = $data['qr'] ?? null;

        // 76 x 36 mm at 203 dpi  width 609  height 288
        return "^XA
    ^CI28
    ^PW609
    ^LL288
    ^LH0,0

    ^CF0,22
    ^FO32,24^FB560,2,0,C,10^FD{$line1}^FS

    ^CF0,20
    ^FO32,80^FB560,1,0,C,20^FD" . mb_strtoupper($directions) . "^FS

    ^CF0,18
    ^FO32,105^FB560,2,0,C,0^FDWarning. Read the additional information given with this medicine^FS

    ^CF0,22
    ^FO32,170^FD{$patient}^FS

    ^CF0,18
    ^FO32,194^FB420,2,0,L,0^FD{$pharmacy}\\& Tel: {$phone}^FS

    ^CF0,20
    ^FO450,135^FD{$dateText}^FS

    " . ($qr ? "^BQN,2,4
    ^FO450,150^FDLA,{$qr}^FS
    " : '') . "
    ^CF0,18
    ^FO32,230^FB560,2,0,L,0^FD{$bottomWarning}^FS

    ^XZ";
    }
}