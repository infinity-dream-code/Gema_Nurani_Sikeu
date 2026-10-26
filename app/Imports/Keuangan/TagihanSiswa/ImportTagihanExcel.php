<?php

namespace App\Imports\Keuangan\TagihanSiswa;

use App\Models\mst_tagihan;
use App\Models\scctcust;
use App\Support\SchoolScope;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ImportTagihanExcel implements ToCollection, WithHeadingRow
{
    public const CACHE_KEY = 'import_tagihan_excel';

    public const REQUIRED_COLUMNS = [
        'nis',
        'nominal',
        'namatagihan',
        'periode',
        'tahunakademik',
        'cicil',
    ];

    public const COLUMN_LABELS = [
        'nis' => 'NIS / Nomor Pembayaran',
        'nominal' => 'Nominal / Total Nominal',
        'namatagihan' => 'Nama Tagihan / Jenis Tagihan',
        'periode' => 'Periode / Urutan / Tanggal Aktif',
        'tahunakademik' => 'Tahun Akademik / Atribut 3',
        'cicil' => 'Cicil',
    ];

    public const HEADING_ALIASES = [
        'nis' => ['nis', 'nocust', 'nomorpembayaran', 'nova', 'nopembayaran'],
        'nominal' => ['nominal', 'totalnominal', 'total'],
        'namatagihan' => ['namatagihan', 'nmtagihan', 'jenistagihan', 'jenis'],
        'periode' => ['periode', 'billperiod', 'urutan', 'tanggalaktif', 'ftgltagihan'],
        'tahunakademik' => ['tahunakademik', 'bta', 'atribut3', 'attribute3', 'atribute3', 'tahunajaran'],
        'cicil' => ['cicil', 'isnyicil'],
    ];

    public function __construct(public ?string $sekolah = null)
    {
    }

    public static function headingMatches(array $headings, string $column): bool
    {
        $aliases = self::HEADING_ALIASES[$column] ?? [$column];

        return array_intersect($headings, $aliases) !== [];
    }

    public function collection(Collection $collection): void
    {
        $processedData = [];
        $masters = mst_tagihan::query()->get();

        foreach ($collection as $row) {
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $rowData = $this->normalizeRow($row->toArray());
            $rowData['status'] = 1;
            $statusKet = [];

            $nocust = $this->excelString($rowData['nocust'] ?? null);
            $rowData['nocust'] = $nocust;
            $rowData['nis'] = $nocust;
            $rowData['billcd'] = $this->excelString($rowData['billcd'] ?? null);

            $siswa = null;
            if ($nocust === '') {
                $rowData['status'] = 0;
                $statusKet[] = 'Nomor Pembayaran / NIS tidak boleh kosong';
            } else {
                $siswa = $this->findSiswa($nocust);
                if (!$siswa) {
                    $rowData['status'] = 0;
                    $statusKet[] = "Siswa dengan Nomor Pembayaran / NIS {$nocust} tidak ditemukan";
                } else {
                    $rowData['nocust'] = (string) $siswa->NOCUST;
                    $rowData['nis'] = (string) $siswa->NOCUST;
                }
            }

            $nominal = $this->excelInteger($rowData['nominal'] ?? null);
            $rowData['nominal'] = $nominal;
            if ($nominal === null || $nominal <= 0) {
                $rowData['status'] = 0;
                $statusKet[] = 'Nominal / Total Nominal tidak boleh kosong / harus lebih dari 0';
            }

            $jenis = $this->excelString($rowData['jenistagihan'] ?? null);
            $nmTagihan = $this->excelString($rowData['nmtagihan'] ?? null);
            $master = $this->matchMaster($masters, $jenis !== '' ? $jenis : $nmTagihan);
            if ($master) {
                $nmTagihan = trim((string) $master->tagihan);
                if ($this->excelString($rowData['isnyicil'] ?? null) === '') {
                    $rowData['isnyicil'] = ((int) $master->isINSTALLMENT) === 1 ? '1' : '0';
                }
            }
            $rowData['nmtagihan'] = $nmTagihan;
            if ($nmTagihan === '') {
                $rowData['status'] = 0;
                $statusKet[] = 'Jenis / Nama tagihan tidak boleh kosong';
            } elseif (mb_strlen($nmTagihan) > 30) {
                $rowData['status'] = 0;
                $statusKet[] = 'Nama tagihan maksimal 30 karakter';
            }

            $billPeriod = $this->excelString($rowData['billperiod'] ?? null);
            if ($billPeriod === '') {
                $billPeriod = $this->excelString($rowData['urutan'] ?? null);
            }
            if ($billPeriod === '') {
                $billPeriod = $this->excelDate($rowData['tanggalaktif'] ?? null);
            }
            $rowData['billperiod'] = $billPeriod;
            if ($billPeriod === '') {
                $rowData['status'] = 0;
                $statusKet[] = 'Periode / Urutan / Tanggal Aktif tidak boleh kosong';
            }

            $bta = $this->excelString($rowData['bta'] ?? null);
            $rowData['bta'] = $bta;
            if ($bta === '') {
                $rowData['status'] = 0;
                $statusKet[] = 'Tahun akademik / Atribut 3 tidak boleh kosong';
            }

            $isNyicil = $this->excelString($rowData['isnyicil'] ?? null);
            if ($isNyicil === '') {
                $isNyicil = '0';
            }
            $rowData['isnyicil'] = $isNyicil;

            $rowData['keterangan'] = $statusKet === [] ? null : implode(', ', $statusKet);
            $processedData[] = $rowData;
        }

        if (!empty($processedData)) {
            Cache::put(self::CACHE_KEY, $processedData, now()->addMinutes(60));
        }
    }

    public function headingRow(): int
    {
        return 1;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[$this->normalizeKey((string) $key)] = $value;
        }

        $aliases = [
            'nocust' => ['nomorpembayaran', 'nova', 'nopembayaran', 'nis', 'nocust'],
            'nominal' => ['totalnominal', 'nominal', 'total'],
            'nmtagihan' => ['namatagihan', 'nmtagihan'],
            'jenistagihan' => ['jenistagihan', 'jenis'],
            'billperiod' => ['periode', 'billperiod'],
            'urutan' => ['urutan', 'furutan'],
            'tanggalaktif' => ['tanggalaktif', 'ftgltagihan'],
            'bta' => ['atribut3', 'attribute3', 'atribute3', 'tahunajaran', 'tahunakademik', 'bta'],
            'isnyicil' => ['cicil', 'isnyicil'],
            'billcd' => ['nomortagihan', 'billcd'],
        ];

        $mapped = [];
        foreach ($aliases as $canonical => $keys) {
            $mapped[$canonical] = $this->firstValue($normalized, $keys);
        }

        return array_merge($normalized, $mapped);
    }

    private function findSiswa(string $key): ?scctcust
    {
        $query = scctcust::query()->where(function ($inner) use ($key) {
            $inner->where('NOCUST', $key)->orWhere('NOVA', $key);
        });
        SchoolScope::apply($query, 'scctcust', $this->sekolah);

        return $query->first();
    }

    private function matchMaster(Collection $masters, string $value): ?mst_tagihan
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $upper = strtoupper($value);

        return $masters->first(function ($item) use ($value, $upper) {
            return strcasecmp((string) ($item->kode ?? ''), $value) === 0
                || strtoupper(trim((string) ($item->tagihan ?? ''))) === $upper;
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private function firstValue(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $key) ?? $key);
    }

    private function excelString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_numeric($value)) {
            $number = (float) $value;
            if ($number == floor($number)) {
                return sprintf('%.0f', $number);
            }
        }

        return trim((string) $value);
    }

    private function excelDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m');
        }

        if (is_numeric($value) && (float) $value > 20000) {
            $unix = ((int) $value - 25569) * 86400;

            return $unix > 0 ? date('Y-m', $unix) : $this->excelString($value);
        }

        return $this->excelString($value);
    }

    private function excelInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = is_string($value)
            ? str_replace(['.', ',', ' '], '', $value)
            : $value;

        if (!is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }
}
