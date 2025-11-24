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

        // 76mm x 36mm label at 203 dpi
        // 203 dpi is ~8 dots per mm
        $dotsPerMm = 203 / 25.4; // ≈ 8.0
        $PW = (int) round(76 * $dotsPerMm);  // ≈ 608 dots width
        $LL = (int) round(36 * $dotsPerMm);  // ≈ 288 dots height

        // Left column width leaves space at right for date + QR
        $leftColWidth = $PW - 150; // reserve ~150 dots for the right column
        $rightColX    = $PW - 120; // right column X anchor

        // Build ZPL (UTF-8 via ^CI28)
        // Keep left text roughly where it previously appeared, scaled to the new canvas
        return "^XA
^CI28
^PW{$PW}
^LL{$LL}
^LH0,0

^CF0,22
^FO10,12^FB{$leftColWidth},2,0,L,0^FD{$line1}^FS

^CF0,24
^FO10,52^FB" . ($PW - 20) . ",1,0,C,0^FD" . mb_strtoupper($directions) . "^FS

^CF0,18
^FO10,84^FB{$leftColWidth},2,0,L,0^FDWarning. Read the additional information given with this medicine^FS

^CF0,20
^FO10,116^FB{$leftColWidth},1,0,L,0^FD{$patient}^FS

^CF0,16
^FO10,148^FB{$leftColWidth},2,0,L,0^FD{$pharmacy}\\& Tel: {$phone}^FS

^CF0,18
^FO{$rightColX}," . ($LL - 122) . "^FB100,1,0,R,0^FD{$dateText}^FS
" . ($qr ? "
^BQN,2,4
^FO{$rightColX}," . ($LL - 100) . "^FDLA,{$qr}^FS
" : '') . "
^CF0,16
^FO10," . ($LL - 26) . "^FB" . ($PW - 20) . ",2,0,L,0^FD{$bottomWarning}^FS

^XZ";
    }
}