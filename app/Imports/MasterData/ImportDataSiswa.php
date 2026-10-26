<?php

namespace App\Imports\MasterData;

use App\Models\scctcust;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ImportDataSiswa implements WithMultipleSheets, ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public function __construct(private int $headingRow = 1)
    {
    }

    public function sheets(): array
    {
        return [
            0 => $this,
        ];
    }

    public function collection(Collection $collection): void
    {
        $cacheKey = 'import_data_siswa';
        $requiredKeys = ['nama', 'unit', 'kelas', 'angkatan'];
        $parsedRows = [];

        foreach ($collection as $row) {
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $rowData = $this->mapRow($row->toArray());
            $rowData['unit'] = trim((string) ($rowData['unit'] ?? ''));
            $rowData['kelas'] = trim((string) ($rowData['kelas'] ?? ''));
            $rowData['kelompok'] = trim((string) ($rowData['kelompok'] ?? '')) ?: '-';
            $rowData['nama'] = trim((string) ($rowData['nama'] ?? ''));
            $rowData['angkatan'] = trim((string) ($rowData['angkatan'] ?? ''));
            $rowData['email'] = trim((string) ($rowData['email'] ?? '')) ?: null;
            $rowData['nova'] = trim((string) ($rowData['nova'] ?? $rowData['nomorpembayaran'] ?? '')) ?: null;

            $nis = trim((string) ($rowData['nis'] ?? $rowData['nova'] ?? ''));
            $nodaftar = trim((string) ($rowData['nodaftar'] ?? ''));
            $rowData['nis'] = $nis !== '' ? $nis : null;
            $rowData['nodaftar'] = $nodaftar !== '' ? $nodaftar : null;
            $rowData['ortu'] = trim((string) ($rowData['ortu'] ?? $rowData['genus'] ?? $rowData['ayah'] ?? '')) ?: null;

            $parsedRows[] = $rowData;
        }

        if (empty($parsedRows)) {
            Cache::forget($cacheKey);
            return;
        }

        $nisList = array_values(array_filter(array_column($parsedRows, 'nis')));
        $nodaftarList = array_values(array_filter(array_column($parsedRows, 'nodaftar')));

        $existingNis = $nisList !== []
            ? scctcust::whereIn('NOCUST', $nisList)
                ->orWhereIn('NOVA', $nisList)
                ->pluck('NOCUST')
                ->merge(
                    scctcust::whereIn('NOVA', $nisList)->pluck('NOVA')
                )
                ->flip()
                ->all()
            : [];
        $existingNodaftar = $nodaftarList !== []
            ? scctcust::whereIn('NUM2ND', $nodaftarList)->pluck('NUM2ND')->flip()->all()
            : [];

        $processedData = [];

        foreach ($parsedRows as $rowData) {
            $rowData['status'] = 1;
            $statusKet = null;

            if (!$rowData['nis'] && !$rowData['nodaftar']) {
                $rowData['status'] = 0;
                $statusKet = 'NIS atau Nomor Pendaftaran wajib diisi';
            }

            foreach ($requiredKeys as $column) {
                if (trim((string) ($rowData[$column] ?? '')) === '') {
                    $rowData['status'] = 0;
                    $statusKet = $this->appendKet($statusKet, strtoupper($column) . ' wajib diisi');
                }
            }

            if ($rowData['nis'] && !preg_match('/^[0-9\-]+$/', (string) $rowData['nis'])) {
                $rowData['status'] = 0;
                $statusKet = $this->appendKet($statusKet, 'Nomor Pembayaran / NIS harus berupa angka');
            } elseif ($rowData['nis'] && isset($existingNis[$rowData['nis']]) && (int) $rowData['status'] !== 0) {
                $rowData['status'] = 2;
                $statusKet = $this->appendKet(
                    $statusKet,
                    "Siswa dengan NIS {$rowData['nis']} sudah ada, data akan diupdate"
                );
            }

            if ($rowData['nodaftar'] && !is_numeric($rowData['nodaftar'])) {
                $rowData['status'] = 0;
                $statusKet = $this->appendKet($statusKet, 'Nomor Pendaftaran harus berupa angka');
            } elseif ($rowData['nodaftar'] && isset($existingNodaftar[$rowData['nodaftar']]) && (int) $rowData['status'] !== 0) {
                $rowData['status'] = 2;
                $statusKet = $this->appendKet(
                    $statusKet,
                    "Siswa dengan nomor pendaftaran {$rowData['nodaftar']} sudah ada, data akan diupdate"
                );
            }

            $rowData['keterangan'] = $statusKet;
            $processedData[] = $rowData;
        }

        Cache::put($cacheKey, $processedData, now()->addMinutes(60));
    }

    private function mapRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $key) ?? '')] = $value;
        }

        $aliases = [
            'nama' => ['nama'],
            'unit' => ['unit', 'atribut2', 'attribute2', 'atribute2'],
            'kelas' => ['kelas', 'atribut1', 'attribute1', 'atribute1'],
            'angkatan' => ['angkatan', 'atribut3', 'attribute3', 'atribute3', 'tahunajaran', 'tahunakademik'],
            'kelompok' => ['kelompok'],
            'nis' => ['nis', 'nomorpembayaran', 'nova', 'nopembayaran'],
            'nodaftar' => ['nodaftar', 'nomorpendaftaran'],
            'email' => ['email'],
            'nova' => ['nova', 'nomorpembayaran', 'nopembayaran'],
            'nomorpembayaran' => ['nomorpembayaran', 'nova', 'nopembayaran'],
            'nomortagihan' => ['nomortagihan', 'billcd'],
        ];

        foreach ($aliases as $canonical => $keys) {
            foreach ($keys as $key) {
                if (isset($normalized[$key]) && $normalized[$key] !== null && $normalized[$key] !== '') {
                    $normalized[$canonical] = $normalized[$key];
                    break;
                }
            }
        }

        return $normalized;
    }

    private function appendKet(?string $current, string $message): string
    {
        if ($current === null || $current === '') {
            return $message;
        }

        return $current . ', ' . $message;
    }

    public function headingRow(): int
    {
        return $this->headingRow > 0 ? $this->headingRow : 1;
    }
}
