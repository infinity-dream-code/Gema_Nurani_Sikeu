<?php

namespace App\Support;

use App\Models\mst_tagihan;
use App\Models\scctcust;
use Illuminate\Support\Facades\DB;

class EmailBroadcastMapper
{
    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function fromExcelRow(array $row, ?scctcust $siswa = null): array
    {
        $nama = self::str($row['nama'] ?? $siswa?->NMCUST ?? $siswa?->nmcust ?? '');
        $email = self::str($row['email'] ?? $siswa?->EMAIL ?? '');
        $va = self::str($row['va'] ?? $row['nomorpembayaran'] ?? $siswa?->NOVA ?? '');
        $nis = self::str($row['nis'] ?? $siswa?->NOCUST ?? $siswa?->nocust ?? $va);
        $orderNumber = self::str($row['nomortagihan'] ?? $row['orderNumber'] ?? $row['billcd'] ?? '');
        $kelas = self::kelasLabel(
            $row['atribut1'] ?? $row['unit'] ?? $siswa?->CODE02 ?? '',
            $row['atribut2'] ?? $row['kelas'] ?? $siswa?->DESC02 ?? '',
            $row['atribut3'] ?? $row['kelompok'] ?? $siswa?->DESC03 ?? '',
        );

        $total = self::int($row['totalnominal'] ?? $row['nominal'] ?? $row['totalAmount'] ?? 0);
        $outstanding = self::int($row['outstanding'] ?? $row['outstandinguangpangkal'] ?? $row['outstandingAmount'] ?? 0);
        $itemName = self::itemName($row['jenistagihan'] ?? $row['nmtagihan'] ?? null);
        if ($total <= 0 && $outstanding > 0) {
            $total = $outstanding;
        }

        $isOpen = $outstanding > 0;
        $payload = [
            'nama' => $nama,
            'email' => $email,
            'recipientName' => self::str($siswa?->GENUS ?? '') ?: $nama,
            'nis' => $nis,
            'kelas' => $kelas,
            'va' => $va,
            'orderNumber' => $orderNumber,
            'billType' => $isOpen ? 'OPEN' : 'CLOSE',
            'invoiceDate' => self::apiDate($row['tanggalaktif'] ?? null) ?? date('d F Y'),
            'activeDate' => self::apiDate($row['tanggalaktif'] ?? null),
            'expireDate' => self::apiDate($row['tanggalberakhir'] ?? $row['expired'] ?? null),
            'totalAmount' => $total,
            'items' => [
                ['name' => $itemName, 'amount' => $total > 0 ? $total : $outstanding],
            ],
            'organizationName' => (string) config('app.nama_instansi'),
            'paymentLink' => (string) config('services.email_broadcaster.payment_link'),
        ];

        if ($isOpen) {
            $payload['outstandingAmount'] = $outstanding;
        }

        return self::compact($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromSiswa(scctcust $siswa): array
    {
        $nama = self::str($siswa->NMCUST ?? $siswa->nmcust ?? '');
        $email = self::str($siswa->EMAIL ?? '');
        $nis = self::str($siswa->NOCUST ?? $siswa->nocust ?? '');
        $va = self::str($siswa->NOVA ?? '');
        if ($va === '' && $nis !== '' && $nis !== '-') {
            $va = scctcust::showVA($nis);
        }

        $bills = self::unpaidBills((int) $siswa->CUSTID);
        $items = [];
        $total = 0;
        $outstanding = 0;
        $orderNumber = '';

        foreach ($bills as $bill) {
            $name = self::str($bill->BILLNM ?? $bill->billnm ?? 'Tagihan');
            $amount = self::int($bill->BILLAM ?? $bill->BILLAMT ?? 0);
            $left = self::int($bill->PAYMENTLEFT ?? max(0, $amount - self::int($bill->BILLPAID ?? 0)));
            $items[] = ['name' => $name !== '' ? $name : 'Tagihan', 'amount' => $amount];
            $total += $amount;
            $outstanding += $left;
            if ($orderNumber === '') {
                $orderNumber = self::str($bill->BILLCD ?? $bill->BILLCODE ?? '');
            }
        }

        $isOpen = $outstanding > 0 && $outstanding < $total;

        $payload = [
            'nama' => $nama,
            'email' => $email,
            'recipientName' => self::str($siswa->GENUS ?? '') ?: $nama,
            'nis' => $nis,
            'kelas' => self::kelasLabel($siswa->CODE02 ?? '', $siswa->DESC02 ?? '', $siswa->DESC03 ?? ''),
            'va' => $va,
            'orderNumber' => $orderNumber,
            'billType' => $isOpen ? 'OPEN' : 'CLOSE',
            'invoiceDate' => date('d F Y'),
            'totalAmount' => $total,
            'items' => $items !== [] ? $items : [['name' => 'Invoice Bulanan', 'amount' => 0]],
            'organizationName' => (string) config('app.nama_instansi'),
            'paymentLink' => (string) config('services.email_broadcaster.payment_link'),
        ];

        if ($isOpen) {
            $payload['outstandingAmount'] = $outstanding;
        }

        return self::compact($payload);
    }

    /** @return list<object> */
    public static function unpaidBills(int $custId): array
    {
        if ($custId <= 0) {
            return [];
        }

        return DB::connection('DATA_MYSQL')
            ->table('scctbill')
            ->where('CUSTID', $custId)
            ->where(function ($query) {
                $query->where('PAIDST', 0)->orWhereNull('PAIDST');
            })
            ->get()
            ->all();
    }

    public static function itemName(mixed $jenis): string
    {
        $jenis = trim((string) $jenis);
        if ($jenis === '' || $jenis === '0') {
            return 'Invoice Bulanan';
        }

        $master = mst_tagihan::query()
            ->where('kode', $jenis)
            ->orWhere('tagihan', $jenis)
            ->first();

        return $master ? trim((string) $master->tagihan) : $jenis;
    }

    public static function kelasLabel(mixed ...$parts): string
    {
        $label = trim(implode(' ', array_filter(array_map(
            static fn ($part) => trim((string) $part),
            $parts
        ), static fn ($part) => $part !== '' && $part !== '-')));

        return $label;
    }

    /** @param  array<string, mixed>  $payload */
    public static function compact(array $payload): array
    {
        return array_filter($payload, static function ($value) {
            if (is_array($value)) {
                return $value !== [];
            }

            return $value !== null && $value !== '';
        });
    }

    public static function str(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_numeric($value) && (float) $value == floor((float) $value)) {
            return sprintf('%.0f', $value);
        }

        return trim((string) $value);
    }

    public static function int(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $raw = is_string($value) ? str_replace(['.', ',', ' '], '', $value) : $value;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    public static function apiDate(mixed $value): ?string
    {
        $value = self::str($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            $ts = strtotime(substr($value, 0, 10));

            return $ts ? date('d F Y', $ts) : $value;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            $ts = strtotime($value . '-01');

            return $ts ? date('d F Y', $ts) : $value;
        }

        $ts = strtotime($value);

        return $ts ? date('d F Y', $ts) : $value;
    }
}
