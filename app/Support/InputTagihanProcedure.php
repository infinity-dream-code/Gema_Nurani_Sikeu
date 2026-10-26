<?php

namespace App\Support;

use App\Models\scctcust;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class InputTagihanProcedure
{
    /**
     * Simpan tagihan ke scctbill.
     * Tabel Gema Nurani memakai BILLCODE/BILLACC/BILLAMT (bukan BILLCD/BILLAC/BILLAM).
     * InputTagihan lama sering "sukses" tanpa insert, jadi penulisan dilakukan langsung.
     */
    public static function call(
        string $nocust,
        int $nominal,
        string $nmTagihan,
        string $billPeriod,
        string $bta,
        string $isNyicil,
        ?string $billCd = null,
        ?int $custId = null,
        ?string $tglAktif = null,
        ?string $tglExpired = null,
    ): void {
        $siswa = self::findSiswa($nocust, $custId);
        if (!$siswa) {
            throw new \RuntimeException("Siswa {$nocust} tidak ditemukan saat simpan tagihan.");
        }

        $columns = self::tableColumns('scctbill');
        if ($columns === []) {
            throw new \RuntimeException('Struktur tabel scctbill tidak terbaca.');
        }

        $custId = (int) $siswa->CUSTID;
        $billCode = trim((string) $billCd);
        $urut = is_numeric($billPeriod) ? (int) $billPeriod : self::nextUrutan($custId, $columns);
        if ($billCode === '') {
            $billCode = date('Y') . '/i' . date('m') . '-' . str_pad((string) ($urut + 1), 3, '0', STR_PAD_LEFT);
        }

        $billAcc = self::billAccount($billPeriod, $tglAktif, $bta);
        $cicil = in_array(strtoupper(trim($isNyicil)), ['1', 'Y', 'YA', 'YES'], true) ? 1 : 0;
        $aktif = self::sqlDate($tglAktif) ?? date('Y-m-d H:i:s');
        $expired = self::sqlDate($tglExpired);

        $payload = [];
        self::put($payload, $columns, ['AA'], self::nextAa($columns));
        self::put($payload, $columns, ['CUSTID'], $custId);
        self::put($payload, $columns, ['BILLCD', 'BILLCODE'], $billCode);
        self::put($payload, $columns, ['BILLAC', 'BILLACC'], $billAcc);
        self::put($payload, $columns, ['BILLNM'], $nmTagihan);
        self::put($payload, $columns, ['BILLAM', 'BILLAMT'], $nominal);
        self::put($payload, $columns, ['BILLPAID'], 0);
        self::put($payload, $columns, ['PAYMENTLEFT'], $nominal);
        self::put($payload, $columns, ['BILLTOT'], $nominal);
        self::put($payload, $columns, ['FLPART'], 0);
        self::put($payload, $columns, ['PAIDST'], 0);
        self::put($payload, $columns, ['INSTALLMENT'], 0);
        self::put($payload, $columns, ['isINSTALLABLE', 'ISINSTALLABLE'], $cicil);
        self::put($payload, $columns, ['FSTSBolehBayar'], 1);
        self::put($payload, $columns, ['FUrutan', 'FURUTAN'], $urut);
        self::put($payload, $columns, ['FTGLTagihan', 'FIGL'], $aktif);
        self::put($payload, $columns, ['EXPIRED'], $expired);
        self::put($payload, $columns, ['BTA', 'B1TA'], $bta);

        if ($payload === [] || !self::hasAny($payload, ['BILLCD', 'BILLCODE'])) {
            throw new \RuntimeException('Kolom nomor tagihan (BILLCD/BILLCODE) tidak ada di scctbill.');
        }

        DB::connection('DATA_MYSQL')->table('scctbill')->insert($payload);

        Log::info('input-tagihan.saved', [
            'custid' => $custId,
            'nocust' => $siswa->NOCUST,
            'nova' => $siswa->NOVA ?? null,
            'bill' => $billCode,
            'nominal' => $nominal,
            'columns' => array_keys($payload),
        ]);
    }

    private static function findSiswa(string $nocust, ?int $custId): ?scctcust
    {
        if ($custId) {
            $found = scctcust::query()->where('CUSTID', $custId)->first();
            if ($found) {
                return $found;
            }
        }

        return scctcust::query()
            ->where(function ($query) use ($nocust) {
                $query->where('NOCUST', $nocust)->orWhere('NOVA', $nocust);
            })
            ->first();
    }

    /** @return list<string> */
    private static function tableColumns(string $table): array
    {
        try {
            $rows = DB::connection('DATA_MYSQL')->select(
                'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            );
        } catch (Throwable $e) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($row) => (string) ($row->name ?? $row->NAME ?? ''),
            $rows
        )));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $columns
     * @param  list<string>  $names
     */
    private static function put(array &$payload, array $columns, array $names, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $actual = [];
        foreach ($columns as $column) {
            $actual[strtoupper($column)] = $column;
        }

        foreach ($names as $name) {
            $key = strtoupper($name);
            if (isset($actual[$key])) {
                $payload[$actual[$key]] = $value;

                return;
            }
        }
    }

    /** @param  array<string, mixed>  $payload */
    private static function hasAny(array $payload, array $names): bool
    {
        $keys = array_map('strtoupper', array_keys($payload));

        foreach ($names as $name) {
            if (in_array(strtoupper($name), $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $columns */
    private static function nextAa(array $columns): ?int
    {
        if (!self::hasColumn($columns, 'AA')) {
            return null;
        }

        $max = DB::connection('DATA_MYSQL')->table('scctbill')->max('AA');

        return ((int) $max) + 1;
    }

    /** @param  list<string>  $columns */
    private static function nextUrutan(int $custId, array $columns): int
    {
        $orderCol = self::hasColumn($columns, 'FUrutan') ? 'FUrutan' : (self::hasColumn($columns, 'FURUTAN') ? 'FURUTAN' : null);
        if (!$orderCol) {
            return 1;
        }

        $max = DB::connection('DATA_MYSQL')->table('scctbill')->where('CUSTID', $custId)->max($orderCol);

        return ((int) $max) + 1;
    }

    /** @param  list<string>  $columns */
    private static function hasColumn(array $columns, string $name): bool
    {
        foreach ($columns as $column) {
            if (strcasecmp($column, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function billAccount(string $billPeriod, ?string $tglAktif, string $bta): string
    {
        $period = preg_replace('/\D/', '', $billPeriod);
        if (is_string($period) && strlen($period) >= 6) {
            return substr($period, 0, 6);
        }

        $fromDate = self::sqlDate($tglAktif);
        if ($fromDate) {
            return date('Ym', strtotime($fromDate));
        }

        $fromBta = preg_replace('/\D/', '', $bta);
        if (is_string($fromBta) && strlen($fromBta) >= 4) {
            return substr($fromBta, 0, 4) . date('m');
        }

        return date('Ym');
    }

    private static function sqlDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10) . ' 00:00:00';
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            return $value . '-01 00:00:00';
        }

        $ts = strtotime($value);

        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
