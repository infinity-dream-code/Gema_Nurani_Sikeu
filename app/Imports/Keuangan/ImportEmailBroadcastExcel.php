<?php

namespace App\Imports\Keuangan;

use App\Models\scctcust;
use App\Support\EmailBroadcastMapper;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ImportEmailBroadcastExcel implements ToCollection, WithHeadingRow
{
    public const CACHE_KEY = 'import_email_broadcast';

    public function collection(Collection $collection): void
    {
        $processed = [];

        foreach ($collection as $row) {
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $mapped = $this->normalizeRow($row->toArray());
            $va = EmailBroadcastMapper::str($mapped['va'] ?? '');
            $nama = EmailBroadcastMapper::str($mapped['nama'] ?? '');
            $email = EmailBroadcastMapper::str($mapped['email'] ?? '');

            $siswa = null;
            if ($va !== '') {
                $siswa = scctcust::query()
                    ->where('NOCUST', $va)
                    ->orWhere('NOVA', $va)
                    ->first();
            }

            if ($email === '' && $siswa) {
                $email = EmailBroadcastMapper::str($siswa->EMAIL ?? '');
                $mapped['email'] = $email;
            }
            if ($nama === '' && $siswa) {
                $nama = EmailBroadcastMapper::str($siswa->NMCUST ?? '');
                $mapped['nama'] = $nama;
            }

            $status = 1;
            $ket = [];
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $status = 0;
                $ket[] = 'Email kosong / tidak valid — baris dilewati';
            }
            if ($nama === '') {
                $status = 0;
                $ket[] = 'Nama wajib diisi';
            }

            $payload = EmailBroadcastMapper::fromExcelRow($mapped, $siswa);

            $processed[] = [
                'nama' => $payload['nama'] ?? $nama,
                'email' => $payload['email'] ?? $email,
                'va' => $payload['va'] ?? $va,
                'nis' => $payload['nis'] ?? '',
                'orderNumber' => $payload['orderNumber'] ?? '',
                'kelas' => $payload['kelas'] ?? '',
                'billType' => $payload['billType'] ?? 'CLOSE',
                'totalAmount' => $payload['totalAmount'] ?? 0,
                'status' => $status,
                'keterangan' => $ket === [] ? null : implode(', ', $ket),
                'payload' => $status === 1 ? $payload : null,
            ];
        }

        Cache::put(self::CACHE_KEY, $processed, now()->addMinutes(60));
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
            $normalized[strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $key) ?? '')] = $this->cell($value);
        }

        $aliases = [
            'nama' => ['nama', 'name', 'studentname'],
            'email' => ['email', 'recipientemail', 'mail'],
            'va' => ['nomorpembayaran', 'nova', 'nopembayaran', 'va', 'vanumber'],
            'nis' => ['nis', 'nocust'],
            'nomortagihan' => ['nomortagihan', 'billcd', 'billcode', 'ordernumber', 'invoicenumber'],
            'atribut1' => ['atribut1', 'attribute1', 'atribute1', 'unit'],
            'atribut2' => ['atribut2', 'attribute2', 'atribute2', 'kelas'],
            'atribut3' => ['atribut3', 'attribute3', 'atribute3', 'kelompok', 'tahunajaran'],
            'totalnominal' => ['totalnominal', 'nominal', 'totalamount', 'total'],
            'outstanding' => ['outstanding', 'outstandinguangpangkal', 'outstandingamount', 'sisacicilan'],
            'jenistagihan' => ['jenistagihan', 'jenis', 'namatagihan'],
            'tanggalaktif' => ['tanggalaktif', 'activedate', 'ftgltagihan'],
            'tanggalberakhir' => ['tanggalberakhir', 'expiredate', 'expired', 'tanggalexpire'],
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

    private function cell(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            $unix = ((int) $value - 25569) * 86400;

            return $unix > 0 ? date('Y-m-d', $unix) : $value;
        }

        return $value;
    }
}
