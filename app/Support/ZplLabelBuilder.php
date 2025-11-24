<?php

namespace App\Support;

use Carbon\Carbon;

class ZplLabelBuilder
{
    /**
     * Build a 102 x 150 mm 4x6 in label ZPL string UTF-8 safe.
     *
     * Expected keys in $data
     *  - line1 product line eg "1 x Hepatitis A"
     *  - directions big centred line defaults to WEEKLY AS DIRECTED
     *  - warning small multi-line defaults to Keep out of the reach and sight of children
     *  - patient full name string
     *  - pharmacy sender string defaults to Pharmacy Express FME51  Unit 4  WF1 2UY
     *  - phone defaults to 01924971414
     *  - date_text dd/mm/yy defaults to today Europe/London
     *  - qr optional order reference string
     */
    public function forOrder(array $data): string
    {
        // Pull fields with sensible fallbacks
        $line1      = mb_strtoupper(trim($data['line1'] ?? ''));
        $directions = trim($data['directions'] ?? 'WEEKLY AS DIRECTED');
        $warning    = trim((string) ($data['warning'] ?? ''));
        $bottomWarning = 'Keep out of the reach and sight of children';
        $patient    = trim($data['patient'] ?? '');
        $pharmacy   = trim($data['pharmacy'] ?? 'Pharmacy Express FME51  Unit 4  WF1 2UY');
        $phone      = trim($data['phone'] ?? '01924971414');
        $dateText   = trim($data['date_text'] ?? Carbon::now('Europe/London')->format('d/m/y'));
        $qr         = $data['qr'] ?? null;

        // 4x6 inch at 203 dpi width 812 dots height 1218 dots
        // ^CI28 ensures UTF-8 handling for text
        // Layout tuned to resemble the provided example label
        return "^XA
^CI28
^PW812
^LL1218
^LH0,0

^CF0,30
^FO30,40^FB752,2,0,L,0^FD{$line1}^FS

^CF0,44
^FO30,170^FB752,1,0,C,0^FD" . mb_strtoupper($directions) . "^FS

^CF0,28
^FO30,230^FB752,2,0,L,0^FDWarning. Read the additional information given with this medicine^FS

^CF0,32
^FO30,330^FD{$patient}^FS

^CF0,26
^FO30,372^FB620,2,0,L,0^FD{$pharmacy}\\& Tel: {$phone}^FS

^CF0,30
^FO690,280^FD{$dateText}^FS

" . ($qr ? "^BQN,2,6
^FO690,320^FDLA,{$qr}^FS
" : '') . "
^CF0,26
^FO30,430^FB752,2,0,L,0^FD{$bottomWarning}^FS

^XZ";
    }
}