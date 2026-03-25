<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class GpSearchController extends Controller
{
    private static ?array $gpRows = null;
    private static ?array $gpDebug = null;

    public function index(Request $request): JsonResponse
    {
        try {
            $q = trim((string) $request->query('q', ''));
            $wantDebug = (string) $request->query('debug', '') === '1';

            $all = $this->loadGps();

            if (mb_strlen($q) < 2) {
                return response()->json($wantDebug
                    ? ['ok' => true, 'items' => [], 'debug' => self::$gpDebug]
                    : ['ok' => true, 'items' => []]
                );
            }

            $items = $this->searchGps($all, $q);

            return response()->json($wantDebug
                ? ['ok' => true, 'items' => $items, 'debug' => self::$gpDebug]
                : ['ok' => true, 'items' => $items]
            );
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage() ?: 'Unhandled error',
                'items' => [],
            ]);
        }
    }

    private function parseCsv(string $data): array
    {
        $rows = [];
        $row = [];
        $cell = '';
        $inQuotes = false;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $c = $data[$i];

            if ($inQuotes) {
                if ($c === '"') {
                    if (($data[$i + 1] ?? null) === '"') {
                        $cell .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $cell .= $c;
                }
                continue;
            }

            if ($c === '"') {
                $inQuotes = true;
                continue;
            }

            if ($c === ',') {
                $row[] = $cell;
                $cell = '';
                continue;
            }

            if ($c === "\r") {
                continue;
            }

            if ($c === "\n") {
                $row[] = $cell;
                $rows[] = $row;
                $row = [];
                $cell = '';
                continue;
            }

            $cell .= $c;
        }

        if ($cell !== '' || ! empty($row)) {
            $row[] = $cell;
            $rows[] = $row;
        }

        return $rows;
    }

    private function norm(string $value): string
    {
        return preg_replace('/[^A-Z0-9]+/', '', strtoupper(trim($value))) ?: '';
    }

    private function findIdx(array $header, array $candidates): int
    {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $header, true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }

        return -1;
    }

    private function joinAddress(array $parts): string
    {
        $filtered = array_values(array_filter(array_map(function ($part) {
            return trim((string) $part);
        }, $parts)));

        return preg_replace('/\s+/', ' ', implode(', ', $filtered)) ?: '';
    }

    private function loadGps(): array
    {
        if (self::$gpRows !== null) {
            return self::$gpRows;
        }

        $filePathCandidates = [
            public_path('data/epraccur.csv'),
            base_path('data/epraccur.csv'),
            base_path('epraccur.csv'),
        ];

        $csvRaw = null;
        $usedPath = null;

        foreach ($filePathCandidates as $path) {
            if (File::exists($path)) {
                $csvRaw = File::get($path);
                $usedPath = $path;
                break;
            }
        }

        if ($csvRaw === null) {
            throw new \RuntimeException('epraccur.csv not found. Put it in public/data/epraccur.csv or data/epraccur.csv');
        }

        $rows = $this->parseCsv($csvRaw);
        if (count($rows) < 2) {
            throw new \RuntimeException('epraccur.csv appears empty');
        }

        $headerRaw = $rows[0];
        $header = array_map(fn ($h) => $this->norm((string) $h), $headerRaw);

        $expectedTokens = [
            'ORGCODE','ORG_CODE','CODE','ODS_CODE','ODSCODE','ORGANISATIONCODE','ORGANISATIONODS',
            'NAME','ORGNAME','ORGANISATIONNAME','ORGANISATION',
            'ADDRESS1','ADDR1','ADDRESSLINE1','ADDRLINE1','ADDRESS_1',
            'POSTCODE','POST_CODE','PCODE','TOWN','POSTTOWN','CITY','COUNTY','DISTRICT',
        ];

        $hasHeaderWords = collect($header)->contains(fn ($h) => in_array($h, $expectedTokens, true));
        $looksLikeOdsCode = (bool) preg_match('/^[A-Z]\d{5}$/i', trim((string) ($headerRaw[0] ?? '')));
        $headerIsActuallyData = ! $hasHeaderWords && $looksLikeOdsCode;

        $idx = [];
        $startRow = 1;
        $mappingSource = 'header';

        if (! $headerIsActuallyData) {
            $idx = [
                'ORG_CODE' => $this->findIdx($header, ['ORGCODE','ORG_CODE','CODE','ODS_CODE','ODSCODE','ORGANISATIONCODE','ORGANISATIONODS']),
                'NAME' => $this->findIdx($header, ['NAME','ORGNAME','ORGANISATIONNAME','ORGANISATION']),
                'ADDR1' => $this->findIdx($header, ['ADDRESS1','ADDR1','ADDRESSLINE1','ADDRLINE1','ADDRESS_1']),
                'ADDR2' => $this->findIdx($header, ['ADDRESS2','ADDR2','ADDRESSLINE2','ADDRLINE2','ADDRESS_2']),
                'ADDR3' => $this->findIdx($header, ['ADDRESS3','ADDR3','ADDRESSLINE3','ADDRLINE3','ADDRESS_3']),
                'ADDR4' => $this->findIdx($header, ['ADDRESS4','ADDR4','ADDRESSLINE4','ADDRLINE4','ADDRESS_4']),
                'TOWN' => $this->findIdx($header, ['TOWN','POSTTOWN','CITY']),
                'COUNTY' => $this->findIdx($header, ['COUNTY','DISTRICT']),
                'POSTCODE' => $this->findIdx($header, ['POSTCODE','POST_CODE','PCODE']),
                'STATUS' => $this->findIdx($header, ['STATUS','STATUSCODE','RECSTATUS']),
                'SECTOR' => $this->findIdx($header, ['SECTOR','ORGTYPEDESC','TYPE','ORGTYPE']),
            ];
        } else {
            $idx = [
                'ORG_CODE' => 0,
                'NAME' => 1,
                'ADDR1' => 4,
                'ADDR2' => 5,
                'ADDR3' => 6,
                'ADDR4' => 7,
                'ADDR5' => 8,
                'POSTCODE' => 9,
                'STATUS' => 12,
                'PRESC' => 25,
            ];
            $startRow = 0;
            $mappingSource = 'fixed-index';
        }

        $items = [];
        $sampledRows = [];

        for ($i = $startRow, $count = count($rows); $i < $count; $i++) {
            $r = $rows[$i] ?? null;
            if (! is_array($r) || empty($r)) {
                continue;
            }

            $name = ($idx['NAME'] ?? -1) >= 0 ? (string) ($r[$idx['NAME']] ?? '') : '';
            if (trim($name) === '') {
                continue;
            }

            $status = ($idx['STATUS'] ?? -1) >= 0 ? strtoupper((string) ($r[$idx['STATUS']] ?? '')) : '';
            $presc = ($idx['PRESC'] ?? -1) >= 0 ? trim((string) ($r[$idx['PRESC']] ?? '')) : '';

            if ($status !== '' && $status !== 'A') {
                continue;
            }
            if ($presc !== '' && $presc !== '4') {
                continue;
            }

            $line1 = ($idx['ADDR1'] ?? -1) >= 0 ? (string) ($r[$idx['ADDR1']] ?? '') : '';
            $line2 = ($idx['ADDR2'] ?? -1) >= 0 ? (string) ($r[$idx['ADDR2']] ?? '') : '';
            $line3 = ($idx['ADDR3'] ?? -1) >= 0 ? (string) ($r[$idx['ADDR3']] ?? '') : '';
            $line4 = ($idx['ADDR4'] ?? -1) >= 0 ? (string) ($r[$idx['ADDR4']] ?? '') : '';
            $line5 = ($idx['ADDR5'] ?? -1) >= 0 ? (string) ($r[$idx['ADDR5']] ?? '') : '';
            $pc = ($idx['POSTCODE'] ?? -1) >= 0 ? (string) ($r[$idx['POSTCODE']] ?? '') : '';

            $address = $this->joinAddress([$line1, $line2, $line3, $line4, $line5, $pc]);

            if (count($sampledRows) < 3) {
                $sampledRows[] = [
                    'orgCode' => ($idx['ORG_CODE'] ?? -1) >= 0 ? (string) ($r[$idx['ORG_CODE']] ?? '') : '',
                    'name' => $name,
                    'status' => $status,
                    'presc' => $presc,
                    'addressPreview' => $address,
                    'raw' => compact('line1', 'line2', 'line3', 'line4', 'line5', 'pc'),
                ];
            }

            $id = trim((string) ((($idx['ORG_CODE'] ?? -1) >= 0 ? ($r[$idx['ORG_CODE']] ?? '') : '') ?: $name));

            $items[] = [
                'id' => $id,
                'name' => trim($name),
                'address' => $address,
            ];
        }

        self::$gpRows = $items;
        self::$gpDebug = [
            'file' => $usedPath,
            'headerRaw' => $headerRaw,
            'headerNorm' => $header,
            'idx' => $idx,
            'sample' => $sampledRows,
            'totalItems' => count($items),
            'headerIsActuallyData' => $headerIsActuallyData,
            'mappingSource' => $mappingSource,
        ];

        return self::$gpRows;
    }

    private function searchGps(array $all, string $q): array
    {
        $needle = mb_strtolower(trim($q));
        if (mb_strlen($needle) < 2) {
            return [];
        }

        $looksLikePc = (bool) preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/i', $needle);
        $scored = [];

        foreach ($all as $item) {
            $name = mb_strtolower((string) ($item['name'] ?? ''));
            $address = mb_strtolower((string) ($item['address'] ?? ''));

            $score = 0;
            if (str_contains($name, $needle)) {
                $score += 5;
            }
            if (str_contains($address, $needle)) {
                $score += 3;
            }
            if ($looksLikePc && str_contains(str_replace(' ', '', $address), str_replace(' ', '', $needle))) {
                $score += 6;
            }

            if ($score > 0) {
                $scored[] = ['item' => $item, 'score' => $score];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_values(array_map(fn ($row) => $row['item'], array_slice($scored, 0, 25)));
    }
}