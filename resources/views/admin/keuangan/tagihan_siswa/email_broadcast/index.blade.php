@extends('layouts.admin_new')
@section('title',$dataTitle??$mainTitle??$title??'')
@section('style')
    <link rel="stylesheet" href="{{asset('main/libs/datatables-bs5/datatables.bootstrap5.css')}}">
    <link rel="stylesheet" href="{{asset('main/libs/datatables-responsive-bs5/responsive.bootstrap5.css')}}">
    <link rel="stylesheet" href="{{asset('main/libs/datatables-buttons-bs5/buttons.bootstrap5.css')}}">
@endsection
@section('content')
    <h3 class="page-heading d-flex text-gray-900 fw-bold flex-column justify-content-center my-0">
        {{$mainTitle??$title??''}}{{ isset($dataTitle) && isset($mainTitle) && $mainTitle != $dataTitle ? ' - '.$dataTitle : '' }}
    </h3>
    <ul class="breadcrumb breadcrumb-style2">
        <li class="breadcrumb-item">
            <a href="{{route('admin.index')}}" class="text-hover-primary">Beranda</a>
        </li>
        <li class="breadcrumb-item">{{$title ?? 'Keuangan'}}</li>
        <li class="breadcrumb-item">{{$mainTitle ?? 'Tagihan Siswa'}}</li>
        <li class="breadcrumb-item active">{{$dataTitle ?? 'Email Broadcast'}}</li>
    </ul>

    <div class="card">
        <div class="card-header header-elements">
            <div class="card-title">
                <h5 class="mb-0">{{ $dataTitle ?? $mainTitle }}</h5>
                <div class="text-muted small mt-1">
                    Upload Excel → preview → masuk antrian API (bukan kirim SMTP dari SIKEU)
                </div>
            </div>
            <div class="card-header-elements ms-auto">
                <button type="button" class="btn btn-whatsapp" data-bs-toggle="modal"
                        data-bs-target="#modal-import" title="Import Excel">
                    <span class="ri-file-excel-2-line me-2"></span>
                    Import Excel
                </button>
            </div>
        </div>

        <div class="card-datatable table-responsive text-nowrap">
            <table class="table table-sm table-bordered table-hover mb-0" id="main_table">
                <thead class="table-light"></thead>
                <tbody></tbody>
            </table>
        </div>

        <div class="card-footer d-flex justify-content-end border-top">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                    data-bs-target="#modal-queue">
                <span class="ri-mail-send-line me-2"></span>
                Masukkan Antrian
            </button>
        </div>
    </div>
@endsection

@section('script')
    <script src="{{asset('main/libs/datatables-bs5/datatables-bootstrap5.js')}}"></script>
    <script src="{{asset('js/datatableCustom/Datatable-0-4.min.js')}}"></script>

    <form id="formImport" enctype="multipart/form-data" class="mainForm" method="POST">
        <div class="modal modal-blur fade" id="modal-import" tabindex="-1" role="dialog" aria-hidden="true"
             data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Import Email Broadcast</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="list-group list-group-timeline mb-3">
                            <li class="list-group-item list-group-timeline-danger">File XLS/XLSX, maks 2MB.</li>
                            <li class="list-group-item list-group-timeline-danger">Kolom: <span class="fw-bold">Nomor Tagihan, Nomor Pembayaran, Nama, Email, Tanggal Aktif, Tanggal Berakhir, Jenis Tagihan, Attribute 1–3, Total Nominal</span>.</li>
                            <li class="list-group-item list-group-timeline-danger">Baris tanpa email dilewati. File tidak dikirim ke server email — hanya diubah jadi JSON antrian.</li>
                            <li class="list-group-item list-group-timeline-danger">Contoh:
                                <a class="btn btn-sm btn-outline-primary fw-bolder"
                                   href="{{ asset('contoh_excel/TEMPLATE EMAIL BROADCAST.xlsx') }}?v={{ file_exists(public_path('contoh_excel/TEMPLATE EMAIL BROADCAST.xlsx')) ? filemtime(public_path('contoh_excel/TEMPLATE EMAIL BROADCAST.xlsx')) : time() }}"
                                   download="TEMPLATE EMAIL BROADCAST.xlsx">
                                    <i class="ri ri-file-excel-line me-2"></i>Contoh File
                                </a>
                            </li>
                        </ul>
                        <fieldset class="form-fieldset">
                            <div class="mb-3">
                                <label class="form-label required" for="file">File (.XLS, .XLSX)</label>
                                <input type="file" id="file" class="form-control" name="fileImport" required>
                            </div>
                        </fieldset>
                    </div>
                    <div class="modal-footer">
                        <div class="w-100">
                            <div class="row">
                                <div class="col">
                                    <input type="reset" value="Batal" class="btn btn-outline-secondary w-100" data-bs-dismiss="modal">
                                </div>
                                <div class="col">
                                    <input type="submit" value="Import Data" class="btn btn-primary w-100">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <form id="formQueue" class="mainForm" method="POST">
        <div class="modal modal-blur fade" id="modal-queue" tabindex="-1" role="dialog" aria-hidden="true"
             data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-status bg-primary"></div>
                    <div class="modal-header">
                        <div class="modal-title">Masukkan Antrian Email</div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body py-4 text-center">
                        <span class="ri-mail-send-line ri-48px"></span>
                        <h3>Kirim baris valid ke queue API?</h3>
                        <div>Status akan menjadi PENDING. Cron Email Broadcaster yang mengirim ke inbox.</div>
                    </div>
                    <div class="modal-footer">
                        <div class="w-100">
                            <div class="row">
                                <div class="col">
                                    <input type="reset" class="btn btn-outline-secondary w-100" value="Batal" data-bs-dismiss="modal">
                                </div>
                                <div class="col">
                                    <input type="submit" value="Masukkan Antrian" class="btn btn-primary w-100">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <link rel="stylesheet" href="{{asset('libs/filepond/dist/filepond.min.css')}}">
    <link rel="stylesheet" href="{{asset('libs/filepond/dist/custom.css')}}">
    <script src="{{asset('libs/filepond/plugin/filepond-plugin-file-validate-type/filepond-plugin-file-validate-type.min.js')}}"></script>
    <script src="{{asset('libs/filepond/plugin/filepond-plugin-file-validate-size/filepond-plugin-file-validate-size.min.js')}}"></script>
    <script src="{{asset('libs/filepond/dist/filepond.min.js')}}"></script>
    <script src="{{asset('libs/filepond/dist/filepond.jquery.js')}}"></script>
    <script src="{{asset('js/helper/errorInputHelper.min.js')}}"></script>
    <script>
        let filePondElements = [];
        let dtOptions = {
            tableId: 'main_table',
            columnUrl: '{{($columnsUrl??null)}}',
            dataUrl: '{{($datasUrl??null)}}',
            dataColumns: [],
            thead: true,
            tfoot: false,
            paging: true,
            searching: true,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 75, 100],
            info: false,
            serverSide: true,
            select: false,
        };

        function initializeFilePond(id) {
            filePondElements[id] = FilePond.create(document.querySelector('input#' + id), {
                credits: null,
                acceptedFileTypes: [
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/wps-office.xlsx',
                    'application/wps-office.xls'
                ],
                storeAsFile: true,
                labelIdle: 'Klik atau seret file Excel ke sini.',
                allowFileTypeValidation: true,
                allowFileSizeValidation: true,
                maxFileSize: 2048000,
            });
        }

        async function parseJsonResponse(response) {
            const raw = await response.text();
            try {
                return JSON.parse(raw);
            } catch (error) {
                throw {status: response.status, message: 'Respons server tidak valid.'};
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            FilePond.registerPlugin(FilePondPluginFileValidateType, FilePondPluginFileValidateSize);
            initializeFilePond('file');
            if (dtOptions.dataUrl && dtOptions.columnUrl) {
                getDT(dtOptions);
            }

            document.querySelectorAll('.mainForm').forEach(form => {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const formId = this.id;
                    let url = '';
                    let formData = new FormData(this);

                    if (formId === 'formImport') {
                        loadingAlert('Membaca file broadcast...');
                        url = '{{ route('admin.keuangan.tagihan-siswa.email-broadcast.store') }}';
                        const pondFile = filePondElements.file?.getFiles()[0]?.file;
                        if (pondFile) {
                            formData.delete('fileImport');
                            formData.append('fileImport', pondFile, pondFile.name);
                        }
                    } else if (formId === 'formQueue') {
                        formData = new FormData();
                        loadingAlert('Memasukkan antrian email...');
                        url = '{{ route('admin.keuangan.tagihan-siswa.email-broadcast.queue') }}';
                    }

                    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    formData.append('_token', csrfToken);

                    fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData
                    })
                        .then(async response => {
                            const payload = await parseJsonResponse(response);
                            if (!response.ok) {
                                throw {status: response.status, payload};
                            }
                            return payload;
                        })
                        .then(data => {
                            successAlert(data.message);
                            dataReload('main_table');
                            document.querySelector(`#${formId} [data-bs-dismiss="modal"]`)?.click();
                        })
                        .catch(error => {
                            if (error.status === 401 || error.status === 419) {
                                return;
                            }
                            const message = error.payload?.message || error.message || 'Terjadi kesalahan.';
                            errorAlert(message);
                        });
                });
            });
        });
    </script>
@endsection
