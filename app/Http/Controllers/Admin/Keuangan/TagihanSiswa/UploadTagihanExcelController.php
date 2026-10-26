<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Imports\Keuangan\TagihanSiswa\ImportTagihanExcel;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use App\Support\InputTagihanProcedure;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Maatwebsite\Excel\Validators\ValidationException;

class UploadTagihanExcelController extends Controller
{
    public string $title = 'Keuangan';
    public string $mainTitle = 'Tagihan Siswa';
    public string $dataTitle = 'Buat Tagihan Excel';
    public string $cacheKey = ImportTagihanExcel::CACHE_KEY;

    public ?string $sekolah = null;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $this->sekolah = Auth::user()->sekolah;
            }

            return $next($request);
        });
    }

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-excel.get-column');
        $data['datasUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-excel.get-data');

        return view('admin.keuangan.tagihan_siswa.upload_tagihan_excel.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'No', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'nis', 'name' => 'NIS', 'searchable' => true, 'orderable' => true],
            ['data' => 'name', 'name' => 'Nama', 'searchable' => true, 'orderable' => true],
            ['data' => 'nmtagihan', 'name' => 'Nama Tagihan', 'searchable' => true, 'orderable' => true],
            ['data' => 'billperiod', 'name' => 'Periode', 'searchable' => true, 'orderable' => true],
            ['data' => 'bta', 'name' => 'Tahun Akademik', 'searchable' => true, 'orderable' => true],
            ['data' => 'isnyicil', 'name' => 'Cicil', 'searchable' => true, 'orderable' => true, 'className' => 'text-center'],
            ['data' => 'nominal', 'name' => 'Nominal', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency'],
            ['data' => 'status', 'name' => 'Status', 'searchable' => true, 'orderable' => true, 'columnType' => 'importstatus'],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
            ['data' => 'unit', 'name' => 'Unit', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelas', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true],
        ];
    }

    public function getData(Request $request)
    {
        $draw = $request->get('draw');

        $cachedData = Cache::get($this->cacheKey, []);
        $nisCount = count($cachedData);

        $select = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
            'scctcust.NOVA',
            'scctcust.CODE02',
            'scctcust.DESC02',
        ];

        $records = collect($cachedData)->map(function ($item) use ($select) {
            $nis = (string) ($item['nocust'] ?? $item['nis'] ?? '');
            $siswa = scctcust::select($select)->where(function ($query) use ($nis) {
                $query->where('scctcust.NOCUST', $nis)->orWhere('scctcust.NOVA', $nis);
            });
            SchoolScope::apply($siswa, 'scctcust', $this->sekolah);
            $siswa = $siswa->first();

            return [
                'nis' => $nis,
                'name' => $siswa->NMCUST ?? null,
                'nmtagihan' => $item['nmtagihan'] ?? null,
                'billperiod' => $item['billperiod'] ?? null,
                'bta' => $item['bta'] ?? null,
                'isnyicil' => $item['isnyicil'] ?? null,
                'unit' => $siswa->CODE02 ?? null,
                'kelas' => $siswa->DESC02 ?? null,
                'nominal' => $item['nominal'] ?? null,
                'status' => $item['status'] ?? 0,
                'keterangan' => $item['keterangan'] ?? null,
            ];
        });

        return response()->json([
            'draw' => intval($draw),
            'recordsTotal' => $nisCount,
            'recordsFiltered' => $nisCount,
            'data' => $records,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(
            [
                'fileImport' => [
                    'required',
                    'file',
                    'mimes:xls,xlsx',
                    'mimetypes:application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream',
                    'max:1024',
                ],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        $file = $request->file('fileImport');

        try {
            $headingsData = (new HeadingRowImport)->toArray($file);
            if (empty($headingsData) || !isset($headingsData[0][0])) {
                throw new \Exception('Tidak dapat membaca judul kolom dari file. Pastikan file memiliki header yang sesuai.');
            }

            $headings = array_map(
                static fn ($heading) => strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $heading) ?? (string) $heading),
                $headingsData[0][0]
            );

            $requiredColumns = ImportTagihanExcel::REQUIRED_COLUMNS;
            $missingColumns = [];
            foreach ($requiredColumns as $column) {
                if ($column === 'cicil') {
                    continue;
                }
                if (!ImportTagihanExcel::headingMatches($headings, $column)) {
                    $missingColumns[] = $column;
                }
            }

            if (!empty($missingColumns)) {
                $formattedMissingColumns = implode(', ', array_map([$this, 'displayColumn'], $missingColumns));
                $formattedRequiredColumns = implode(', ', array_map([$this, 'displayColumn'], $requiredColumns));
                throw new \Exception("Kolom $formattedMissingColumns tidak ditemukan.<br><hr> pastikan kolom berikut ada dan terisi pada file import yang akan diproses: $formattedRequiredColumns.");
            }

            Cache::forget($this->cacheKey);
            Excel::import(new ImportTagihanExcel($this->sekolah), $file);

            $data = Cache::get($this->cacheKey, []);
            if (empty($data)) {
                throw new \Exception('File berhasil dibaca, tetapi tidak ada baris data yang dapat diproses. Pastikan file berisi NIS dan Nominal.');
            }

            Log::info('Upload tagihan excel berhasil', [
                'user_id' => auth()->id(),
                'file_name' => $file->getClientOriginalName(),
                'row_count' => count($data),
            ]);

            return response()->json(['message' => 'Sukses, data tagihan telah diimport, silahkan periksa kembali', 'data' => $data], 200);
        } catch (ValidationException $e) {
            $errorMessages = $e->errors();
            $errorMessage = $errorMessages['error'][0] ?? 'Terjadi kesalahan saat melakukan import data.';

            Log::warning('Upload tagihan excel gagal validasi excel', [
                'user_id' => auth()->id(),
                'file_name' => $file?->getClientOriginalName(),
                'errors' => $errorMessages,
            ]);

            return response()->json(['message' => $errorMessage, 'error' => $errorMessages], 422);
        } catch (\Throwable $e) {
            Log::error('Upload tagihan excel gagal', [
                'user_id' => auth()->id(),
                'file_name' => $file?->getClientOriginalName(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            $error = $e->getMessage();

            return response()->json([
                'message' => "Gagal!<br> tidak dapat melakukan {$this->mainTitle}.<hr> {$error}",
                'error' => $error,
            ], 422);
        }
    }

    public function validateExcel()
    {
        $data = Cache::get($this->cacheKey);
        if (empty($data)) {
            return response()->json(['message' => 'Silahkan import data tagihan terlebih dahulu'], 422);
        }

        try {
            $skippedInactive = [];
            $skippedInvalid = [];
            $insertedCount = 0;

            foreach ($data as $item) {
                if ((int) ($item['status'] ?? 0) !== 1) {
                    $skippedInvalid[] = trim(($item['nocust'] ?? $item['nis'] ?? '-') . ' - ' . ($item['keterangan'] ?? 'Data tidak valid'));
                    continue;
                }

                $nocust = trim((string) ($item['nocust'] ?? $item['nis'] ?? ''));
                $siswa = scctcust::where(function ($query) use ($nocust) {
                    $query->where('NOCUST', $nocust)->orWhere('NOVA', $nocust);
                });
                SchoolScope::apply($siswa, 'scctcust', $this->sekolah);
                $siswa = $siswa->first();
                if ($siswa) {
                    $nocust = (string) $siswa->NOCUST;
                }
                if (!$siswa) {
                    return response()->json(['message' => "Siswa dengan NIS {$nocust} tidak ditemukan."], 422);
                }

                if ((int) ($siswa->STCUST ?? 0) === 0) {
                    $skippedInactive[] = trim($nocust . ' - ' . ($siswa->NMCUST ?? 'Tanpa Nama'));
                    continue;
                }

                InputTagihanProcedure::call(
                    $nocust,
                    (int) ($item['nominal'] ?? 0),
                    (string) ($item['nmtagihan'] ?? ''),
                    (string) ($item['billperiod'] ?? ''),
                    (string) ($item['bta'] ?? ''),
                    (string) ($item['isnyicil'] ?? ''),
                    $item['billcd'] ?? null,
                    isset($siswa->CUSTID) ? (int) $siswa->CUSTID : null,
                    $item['tanggalaktif'] ?? null,
                    $item['tanggalberakhir'] ?? $item['expired'] ?? null,
                );
                $insertedCount++;
            }

            Cache::forget($this->cacheKey);

            $message = "Data tagihan disimpan. Berhasil dibuat untuk {$insertedCount} siswa.";
            if (!empty($skippedInactive)) {
                $message .= '<hr>Tagihan tidak dibuat untuk siswa nonaktif: ' . count($skippedInactive) . ' siswa.<br>' .
                    implode('<br>', $skippedInactive);
            }
            if (!empty($skippedInvalid)) {
                $message .= '<hr>Baris tidak diproses karena data tidak valid: ' . count($skippedInvalid) . ' baris.';
            }

            return response()->json(['message' => $message], 200);
        } catch (\Throwable $e) {
            Log::error('Simpan tagihan excel gagal', [
                'user_id' => auth()->id(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Terjadi kesalahan saat menyimpan data. Silakan coba lagi.',
            ], 422);
        }
    }

    private function displayColumn(string $column): string
    {
        return ImportTagihanExcel::COLUMN_LABELS[$column]
            ?? match ($column) {
                'nocust' => 'NIS',
                'nmtagihan' => 'Nama Tagihan',
                'billperiod' => 'Periode',
                'bta' => 'Tahun Akademik',
                'isnyicil' => 'Cicil',
                default => ucwords(str_replace('_', ' ', $column)),
            };
    }
}
