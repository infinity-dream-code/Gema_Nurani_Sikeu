<?php

namespace App\Http\Controllers\Admin\MasterData;

use App\Http\Controllers\Controller;
use App\Imports\MasterData\ImportDataSiswa;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use App\Support\InputSiswaProcedure;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Maatwebsite\Excel\Validators\ValidationException;

class ExportImportDataController extends Controller
{
    public string $title = 'Master Data';
    public string $mainTitle = 'Export Import Data';
    public string $dataTitle = 'Export Import Data';
    public string $cacheKey = 'import_data_siswa';

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.master-data.export-import-data.get-column', [], false);
        $data['datasUrl'] = route('admin.master-data.export-import-data.get-data', [], false);

        return view('admin.master_data.export_import_data.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'nis', 'name' => 'NIS', 'searchable' => false, 'orderable' => false],
            ['data' => 'nodaftar', 'name' => 'No Pend', 'searchable' => false, 'orderable' => false],
//            ['data' => 'NOVA', 'name' => 'NO VA'],
            ['data' => 'name', 'name' => 'NAMA', 'searchable' => false, 'orderable' => false],
            ['data' => 'status', 'name' => 'Status', 'searchable' => true, 'orderable' => true, 'columnType' => 'importstatus'],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
            ['data' => 'unit', 'name' => 'Unit', 'searchable' => false, 'orderable' => false],
            ['data' => 'kelas', 'name' => 'Kelas', 'searchable' => false, 'orderable' => false],
            ['data' => 'kelompok', 'name' => 'Kelompok', 'searchable' => false, 'orderable' => false],
            ['data' => 'angkatan', 'name' => 'Angkatan', 'searchable' => false, 'orderable' => false],
            ['data' => 'gender', 'name' => 'Jenis Kelamin', 'searchable' => false, 'orderable' => false],
            ['data' => 'ortu', 'name' => 'Ortu / Wali', 'searchable' => false, 'orderable' => false],
            ['data' => 'alamat', 'name' => 'Alamat', 'searchable' => false, 'orderable' => false],
        ];
    }

    public function getData(Request $request)
    {
        $draw = $request->get('draw');
        $start = $request->get('start');
        $rowperpage = $request->get('length');

        $columnName_arr = $request->get('columns');
        $search_arr = $request->get('search');

        $defaultColumn = 'scctcust.nocust';
        $defaultOrder = 'asc';

        if ($request->has('order')) {
            $columnIndex_arr = $request->get('order');
            $columnIndex = $columnIndex_arr[0]['column'];
            $columnSortOrder = $columnIndex_arr[0]['dir'];
        } else {
            $columnIndex = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        $columnName = $columnName_arr[$columnIndex]['data'];
        $searchValue = $search_arr['value'];

        if (!$columnName || $columnName == 'no') {
            $columnName = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        $filters = [];
        $filterQuery = null;

        $cachedData = collect(Cache::get($this->cacheKey) ?? []);
        $paginatedData = $cachedData->slice($start, $rowperpage)->values();


        $nisList = collect($cachedData)->pluck('nis')->toArray();
        $nisCount = count($cachedData);

        $whereAny = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
        ];

        $select = array_unique(array_merge($whereAny, [
            'scctcust.NUM2ND',
            'scctcust.CODE02',
            'scctcust.DESC02',
            'scctcust.DESC03',
            'scctcust.DESC04',

        ]));

        $records = collect($paginatedData)->map(function ($item) {
            $nis = $item['nis'];
            return [
                'nis' => $nis,
                'nodaftar' => $item['nodaftar'] ?? null,
                'name' => $item['nama'] ?? null,
                'unit' => $item['unit'] ?? null,
                'kelas' => $item['kelas'] ?? null,
                'kelompok' => $item['kelompok'] ?? null,
                'angkatan' => $item['angkatan'] ?? null,
                'gender' => $item['gender'] ?? null,
                'ortu' => $item['ortu'] ?? $item['genus'] ?? null,
                'alamat' => $item['alamat'] ?? null,
                'status' => $item['status'] ?? 0,
                'keterangan' => $item['keterangan'],
            ];
        });

        $response = array(
            'draw' => intval($draw),
            'recordsTotal' => $nisCount,
            'recordsFiltered' => $nisCount,
            'data' => $records,
        );
        return response()->json($response);
    }

    public function store(Request $request)
    {
        $request->validate(
            [
                'fileImport' => [
                    'required',
                    'file',
                    'extensions:xls,xlsx',
                    'mimetypes:application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream,application/wps-office.xlsx,application/wps-office.xls',
                    'max:1024',
                ],
            ],
            array_merge(ValidationMessage::messages(), [
                'extensions' => ':attribute harus bertipe: :values',
            ]),
            ValidationMessage::attributes()
        );

        $file = $request->file('fileImport');

        try {
            $requiredColumns = [
                'nama', 'unit', 'kelas', 'angkatan',
            ];
            $conditionalColumns = ['nis', 'nodaftar', 'nomorpembayaran'];

            [$headingRow, $headings] = $this->resolveImportHeadings($file, $requiredColumns);

            if ($headings === []) {
                throw new Exception('Tidak dapat membaca judul kolom dari file. Pastikan file memiliki header yang sesuai di baris 1.');
            }

            $missingColumns = [];
            $hasIdentity = in_array('nis', $headings, true)
                || in_array('nodaftar', $headings, true)
                || in_array('nomorpembayaran', $headings, true);

            if (!$hasIdentity) {
                $missingColumns[] = 'Nomor Pembayaran / NIS / NODAFTAR';
            }
            foreach ($requiredColumns as $column) {
                if (!in_array($column, $headings, true)) {
                    $missingColumns[] = $column;
                }
            }

            if (!empty($missingColumns)) {
                throw new Exception(
                    "Kolom " . strtoupper(implode(', ', $missingColumns)) . " tidak ditemukan.<br><hr>
                    Pastikan file memakai template: Nomor Tagihan, Nomor Pembayaran, Nama, Email, Tanggal Aktif, Tanggal Berakhir, Urutan, Jenis Tagihan, Atribut 1, Atribut 2, Atribut 3, Total Nominal."
                );
            }

            Cache::forget($this->cacheKey);
            Excel::import(new ImportDataSiswa($headingRow), $file);

            $data = Cache::get($this->cacheKey) ?? [];
            $invalidCount = collect($data)->where('status', 0)->count();
            $message = 'Sukses, data siswa telah diimport, silahkan periksa kembali';
            if ($invalidCount > 0) {
                $message .= " ({$invalidCount} baris perlu diperbaiki, lihat kolom Keterangan)";
            }

            return response()->json(['message' => $message, 'data' => $data], 200);
        } catch (ValidationException $e) {
            $errorMessages = $e->errors();
            $errorMessage = $errorMessages['error'][0] ?? 'Terjadi kesalahan saat melakukan import data.';
            return response()->json(['message' => $errorMessage, 'error' => $errorMessages], 422);
        } catch (\Throwable $e) {
            Log::error('export_import_data.store.failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $error = $e->getMessage();
            return response()->json(['message' => "Gagal!<br> tidak dapat melakukan $this->mainTitle.<hr> $error", 'error' => $error], 422);
        }
    }

    public function validateData(Request $request)
    {
        $rules = [
            'metode' => ['required', 'in:1,2,3,4'],
        ];

        $request->validate(
            $rules,
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        $data = Cache::get($this->cacheKey);
        if (is_null($data) || (is_array($data) && empty($data))) {
            return response()->json(['message' => 'Tidak ada data yang dapat diproses, silahkan upload file terlebih dahulu'], 422);
        }

        $connection = DB::connection('DATA_MYSQL');

        try {
            if ($request->metode != '1') {
                $connection->beginTransaction();
            }

            if ($request->metode == '1') {
                $invalidCount = collect($data)->where('status', 0)->count();
                if ($invalidCount > 0) {
                    return response()->json([
                        'message' => "Ada {$invalidCount} baris bermasalah. Perbaiki data di kolom Keterangan terlebih dahulu.",
                    ], 422);
                }

                $rows = array_filter($data, fn ($item) => !empty($item['nis'] ?? null));
                if (empty($rows)) {
                    return response()->json(['message' => 'Tidak ada baris dengan NIS yang dapat disimpan'], 422);
                }

                $saved = 0;
                foreach ($rows as $item) {
                    $item = $this->normalizeImportItem($item);
                    $nis = (string) ($item['nis'] ?? '');
                    if ($nis === '') {
                        continue;
                    }

                    InputSiswaProcedure::call(
                        $nis,
                        (string) ($item['nama'] ?? ''),
                        (string) ($item['kelas'] ?? ''),
                        (string) ($item['unit'] ?? ''),
                        (string) ($item['unit'] ?? ''),
                        (string) ($item['kelompok'] ?? ''),
                        (string) ($item['angkatan'] ?? ''),
                        $item['alamat'] ?? null,
                        $item['gender'] ?? null,
                        $this->resolveOrtuForDb($item),
                    );

                    $saved++;
                }

                if ($saved === 0) {
                    return response()->json(['message' => 'Tidak ada data siswa yang berhasil diproses'], 422);
                }
            } elseif ($request->metode == '2') {
                $rows = array_filter($data, fn ($item) => !empty($item['nodaftar'] ?? null));

                foreach ($rows as $item) {
                    if ((int) ($item['status'] ?? 1) === 0) {
                        continue;
                    }
                    $item = $this->normalizeImportItem($item);
                    $lookupKey = $item['nodaftar'] ?? '';

                    if (strlen($lookupKey) > 10) {
                        continue;
                    }

                    $existingCust = scctcust::where('NUM2ND', $item['nodaftar'])->first();

                    if (!$existingCust) {
                        if (!empty($item['nis'])) {
                            $existingNis = scctcust::where('NOCUST', $item['nis'])->first();
                            if ($existingNis) {
                                $connection->rollBack();

                                return response()->json(['message' => 'Gagal, siswa dengan NIS :' . $item['nis'] . ' sudah ada!'], 422);
                            }
                        }

                        scctcust::create($this->buildScctcustPayload($item));
                    } else {
                        $existingCust->update($this->buildScctcustPayload(
                            $item,
                            true,
                            $existingCust,
                        ));
                    }
                }
            } elseif ($request->metode == '3') {
                $rows = array_filter($data, fn ($item) => !empty($item['nis'] ?? null));

                foreach ($rows as $item) {
                    if ((int) ($item['status'] ?? 1) === 0) {
                        continue;
                    }
                    $item = $this->normalizeImportItem($item);
                    if (strlen((string) ($item['nis'] ?? '')) > 10) {
                        continue;
                    }

                    $existingCust = scctcust::where('NOCUST', $item['nis'])->first();

                    if ($existingCust) {
                        $existingCust->update([
                            'CODE02' => $item['unit'] ?? null,
                            'DESC02' => $item['kelas'] ?? null,
                            'DESC03' => $item['kelompok'] ?? null,
                        ]);
                    }
                }
            } elseif ($request->metode == '4') {
                $rows = array_filter($data, fn ($item) => !empty($item['nodaftar'] ?? null) && !empty($item['nis'] ?? null));

                foreach ($rows as $item) {
                    $item = $this->normalizeImportItem($item);
                    if (strlen((string) ($item['nodaftar'] ?? '')) > 10) {
                        continue;
                    }
                    if (strlen((string) ($item['nis'] ?? '')) > 10) {
                        continue;
                    }

                    $existingNis = scctcust::where('NOCUST', $item['nis'])->first();
                    if ($existingNis) {
                        $connection->rollBack();

                        return response()->json(['message' => 'Gagal, NIS :' . $item['nis'] . ' sudah ada!'], 422);
                    }

                    $existingCust = scctcust::where('NUM2ND', $item['nodaftar'])->first();
                    if ($existingCust && in_array(trim((string) $existingCust->NOCUST), ['', '-'], true)) {
                        $existingCust->update([
                            'NOCUST' => $item['nis'],
                        ]);
                    }
                }
            }

            if ($request->metode != '1' && $connection->transactionLevel() > 0) {
                $connection->commit();
            }
            Cache::forget($this->cacheKey);

            return response()->json(['message' => 'Sukses, data siswa telah disimpan, silahkan periksa kembali'], 200);
        } catch (\Throwable $e) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            Log::error('export_import_data.validateData.failed', [
                'metode' => $request->metode,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $response = ['message' => 'Gagal, data tidak dapat disimpan'];
            if (config('app.debug')) {
                $response['error'] = $e->getMessage();
            }

            return response()->json($response, 422);
        }
    }

    public function clearData()
    {
        Cache::forget($this->cacheKey);
        return response()->json(['message' => 'Data dibersihkan'], 200);
    }

    /**
     * @return array{0: int, 1: array<int, string>}
     */
    private function resolveImportHeadings($file, array $requiredColumns): array
    {
        foreach ([1, 2] as $headingRow) {
            $raw = (new HeadingRowImport($headingRow))->toArray($file);
            $headings = $this->normalizeHeadings($raw[0][0] ?? null);
            if ($this->headingsLookValid($headings, $requiredColumns)) {
                return [$headingRow, $headings];
            }
        }

        $raw = (new HeadingRowImport(1))->toArray($file);

        return [1, $this->normalizeHeadings($raw[0][0] ?? null)];
    }

    /**
     * @param  mixed  $headings
     * @return array<int, string>
     */
    private function normalizeHeadings($headings): array
    {
        if (!is_array($headings) || $headings === []) {
            return [];
        }

        $normalized = [];
        foreach ($headings as $heading) {
            $value = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $heading) ?? '');
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        $aliases = [
            'nama' => ['nama'],
            'unit' => ['unit', 'atribut2', 'attribute2', 'atribute2'],
            'kelas' => ['kelas', 'atribut1', 'attribute1', 'atribute1'],
            'angkatan' => ['angkatan', 'atribut3', 'attribute3', 'atribute3', 'tahunajaran', 'tahunakademik'],
            'nis' => ['nis', 'nomorpembayaran', 'nova', 'nopembayaran'],
            'nodaftar' => ['nodaftar', 'nomorpendaftaran'],
            'nomorpembayaran' => ['nomorpembayaran', 'nova', 'nopembayaran'],
            'kelompok' => ['kelompok'],
        ];

        foreach ($aliases as $canonical => $keys) {
            if (array_intersect($normalized, $keys)) {
                $normalized[] = $canonical;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function headingsLookValid(array $headings, array $requiredColumns): bool
    {
        if ($headings === []) {
            return false;
        }

        $hasIdentity = in_array('nis', $headings, true)
            || in_array('nodaftar', $headings, true)
            || in_array('nomorpembayaran', $headings, true);
        if (!$hasIdentity) {
            return false;
        }

        foreach ($requiredColumns as $column) {
            if (!in_array($column, $headings, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeImportItem(array $item): array
    {
        $item['nis'] = isset($item['nis']) && $item['nis'] !== '' && $item['nis'] !== null
            ? (string) $item['nis']
            : null;
        $item['nodaftar'] = isset($item['nodaftar']) && $item['nodaftar'] !== '' && $item['nodaftar'] !== null
            ? (string) $item['nodaftar']
            : null;

        return $item;
    }

    /** Nama ortu/wali utama (kolom ortu / genus / ayah di Excel). */
    private function resolveOrtuForDb(array $item): ?string
    {
        $ortu = trim((string) ($item['ortu'] ?? $item['genus'] ?? $item['ayah'] ?? ''));

        return $ortu !== '' ? $ortu : null;
    }

    /** Nama ortu kedua — hanya untuk file lama (kolom ibu). */
    private function resolveOrtuSecondForDb(array $item): ?string
    {
        $second = trim((string) ($item['ibu'] ?? ''));

        return $second !== '' ? $second : null;
    }

    private function resolveSchoolCode(array $item): string
    {
        $unit = trim((string) ($item['unit'] ?? ''));
        if (stripos($unit, 'SDIT') !== false) {
            return 'SDIT';
        }

        return mb_substr($unit, 0, 5);
    }

    private function buildScctcustPayload(
        array $item,
        bool $metodeByNodaftar = false,
        ?scctcust $existingCust = null,
    ): array {
        $code01 = $this->resolveSchoolCode($item);

        $payload = [
            'NOCUST' => $item['nis'] ?? '-',
            'NMCUST' => $item['nama'],
            'NUM2ND' => $item['nodaftar'] ?? '-',
            'STCUST' => 1,
            'CODE01' => $code01,
            'DESC01' => $item['unit'] ?? $code01,
            'CODE02' => $item['unit'] ?? null,
            'DESC02' => $item['kelas'] ?? null,
            'DESC03' => $item['kelompok'] ?? '-',
            'CODE04' => $item['gender'] ?? null,
            'DESC04' => $item['angkatan'] ?? null,
            'DESC05' => $item['alamat'] ?? null,
            'EMAIL' => $item['email'] ?? null,
            'NOVA' => $item['nova'] ?? $item['nomorpembayaran'] ?? $item['nis'] ?? null,
            'GENUS' => $this->resolveOrtuForDb($item),
            'LastUpdate' => Carbon::now(),
        ];

        if ($existingCust) {
            $payload['NOCUST'] = $metodeByNodaftar ? ($item['nis'] ?? $existingCust->NOCUST) : $existingCust->NOCUST;
            $payload['NUM2ND'] = $metodeByNodaftar
                ? $existingCust->NUM2ND
                : ($item['nodaftar'] ?? $existingCust->NUM2ND ?? '-');

            return $payload;
        }

        $payload['CUSTID'] = scctcust::nextCustId();

        return $payload;
    }
}
