<?php

namespace App\Http\Controllers\Admin\Keuangan;

use App\Http\Controllers\Controller;
use App\Imports\Keuangan\ImportEmailBroadcastExcel;
use App\Models\ValidationMessage;
use App\Support\EmailBroadcasterClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class EmailBroadcastController extends Controller
{
    public string $title = 'Keuangan';
    public string $mainTitle = 'Tagihan Siswa';
    public string $dataTitle = 'Email Broadcast';
    public string $cacheKey = ImportEmailBroadcastExcel::CACHE_KEY;

    public function index()
    {
        return view('admin.keuangan.tagihan_siswa.email_broadcast.index', [
            'title' => $this->title,
            'mainTitle' => $this->mainTitle,
            'dataTitle' => $this->dataTitle,
            'columnsUrl' => route('admin.keuangan.tagihan-siswa.email-broadcast.get-column'),
            'datasUrl' => route('admin.keuangan.tagihan-siswa.email-broadcast.get-data'),
        ]);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'No', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'nama', 'name' => 'Nama', 'searchable' => true, 'orderable' => true],
            ['data' => 'email', 'name' => 'Email', 'searchable' => true, 'orderable' => true],
            ['data' => 'va', 'name' => 'Nomor Pembayaran', 'searchable' => true, 'orderable' => true],
            ['data' => 'orderNumber', 'name' => 'Nomor Tagihan', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelas', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true],
            ['data' => 'billType', 'name' => 'Tipe', 'searchable' => true, 'orderable' => true, 'className' => 'text-center'],
            ['data' => 'totalAmount', 'name' => 'Nominal', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency'],
            ['data' => 'status', 'name' => 'Status', 'searchable' => true, 'orderable' => true, 'columnType' => 'importstatus'],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
        ];
    }

    public function getData(Request $request)
    {
        $cached = Cache::get($this->cacheKey, []);

        return response()->json([
            'draw' => (int) $request->get('draw'),
            'recordsTotal' => count($cached),
            'recordsFiltered' => count($cached),
            'data' => collect($cached)->map(fn ($item) => [
                'nama' => $item['nama'] ?? null,
                'email' => $item['email'] ?? null,
                'va' => $item['va'] ?? null,
                'orderNumber' => $item['orderNumber'] ?? null,
                'kelas' => $item['kelas'] ?? null,
                'billType' => $item['billType'] ?? null,
                'totalAmount' => $item['totalAmount'] ?? 0,
                'status' => $item['status'] ?? 0,
                'keterangan' => $item['keterangan'] ?? null,
            ]),
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
                    'max:2048',
                ],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        try {
            Cache::forget($this->cacheKey);
            Excel::import(new ImportEmailBroadcastExcel(), $request->file('fileImport'));
            $data = Cache::get($this->cacheKey, []);
            if ($data === []) {
                return response()->json(['message' => 'File terbaca, tetapi tidak ada baris yang dapat diproses.'], 422);
            }

            $ready = collect($data)->where('status', 1)->count();

            return response()->json([
                'message' => 'File dibaca. ' . $ready . ' baris siap masuk antrian email, ' . (count($data) - $ready) . ' dilewati.',
            ]);
        } catch (Throwable $e) {
            Log::error('email-broadcast.import.failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'Gagal membaca file: ' . $e->getMessage()], 422);
        }
    }

    public function queue(EmailBroadcasterClient $client)
    {
        $data = Cache::get($this->cacheKey, []);
        $messages = collect($data)
            ->where('status', 1)
            ->pluck('payload')
            ->filter(fn ($payload) => is_array($payload) && ($payload['email'] ?? '') !== '')
            ->values()
            ->all();

        if ($messages === []) {
            return response()->json(['message' => 'Tidak ada baris valid untuk dimasukkan ke antrian. Upload Excel dulu.'], 422);
        }

        try {
            $result = $client->billingBulk($messages);
            Cache::forget($this->cacheKey);
            $queued = (int) ($result['queued'] ?? count($messages));
            $failed = (int) ($result['failed'] ?? 0);

            return response()->json([
                'message' => "Masuk antrian Email Broadcaster: {$queued} pesan PENDING" . ($failed ? ", gagal {$failed}." : '.'),
                'data' => $result,
            ], 201);
        } catch (Throwable $e) {
            Log::error('email-broadcast.queue.failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
