<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InputSiswaProcedure
{
    /**
     * Excel → InputSiswaV2
     * NIS → p_NIMRAW, Nama → p_NMCUSTRAW, Kelas → p_DESC02,
     * Unit → p_CODE02, Kode sekolah → p_CODE01, Kelompok → p_DESC03,
     * Angkatan → p_DESC04, Alamat → p_DESC05, Gender → p_CODE04,
     * Ortu → p_GENUS, VA → p_NOVA, Email → p_EMAIL, No daftar → p_NUM2ND.
     */
    public static function call(
        string $nimRaw,
        string $namaRaw,
        string $desc02,
        string $code02,
        string $code01,
        string $desc03,
        string $angkatan,
        ?string $alamat = null,
        ?string $gender = null,
        ?string $ortu = null,
        ?string $code05 = null,
        ?string $nova = null,
        ?string $email = null,
        ?string $num2nd = null,
    ): void {
        $pdo = DB::connection('DATA_MYSQL')->getPdo();
        $stmt = $pdo->prepare('CALL InputSiswaV2(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            self::limit($nimRaw, 32),
            self::limit($namaRaw, 70),
            self::limit($desc02, 50),
            self::limit($code02, 50),
            self::limit($code01, 10),
            self::limit($desc03 !== '' ? $desc03 : '-', 50),
            self::limit($angkatan, 50),
            self::limit((string) ($alamat ?? ''), 250),
            self::limit((string) ($gender ?? ''), 5),
            self::limit((string) ($ortu ?? ''), 50),
            self::limit((string) ($code05 ?? ''), 5),
            self::limit((string) ($nova ?? $nimRaw), 32),
            self::limit((string) ($email ?? ''), 100),
            self::limit((string) ($num2nd ?? ''), 20),
        ]);

        do {
            $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } while ($stmt->nextRowset());

        Log::info('input-siswa.procedure.ok', [
            'procedure' => 'InputSiswaV2',
            'nis' => $nimRaw,
            'nova' => $nova,
            'unit' => $code02,
            'kelas' => $desc02,
            'kelompok' => $desc03,
            'code01' => $code01,
            'angkatan' => $angkatan,
        ]);
    }

    private static function limit(string $value, int $max): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }
}
