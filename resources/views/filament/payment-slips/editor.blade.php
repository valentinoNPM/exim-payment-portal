<x-filament-panels::page>
    @if ($isEdit && $record->status !== 'draft')
        <section class="ps-editor__notice" role="status">
            <h2>Accounting verification</h2>
            <p>Slip ini sudah disubmit. Gunakan halaman Check untuk mengisi VAT dan COA; perubahan draft tidak lagi diizinkan.</p>
            <a class="ps-editor__button ps-editor__button--primary" href="{{ route('payment-slips.checker-edit', $record) }}">Buka Check</a>
            <a class="ps-editor__button" href="{{ \App\Filament\Resources\PaymentSlips\PaymentSlipResource::getUrl('view', ['record' => $record]) }}">Lihat Slip</a>
        </section>
    @else
        @php
            $general = $type === \App\Models\PaymentSlip::TYPE_GENERAL;
            $currency = old('currency', $record?->currency ?? 'IDR');
            $selectedSupplier = old('supplier_id', $record?->supplier_id);
            $selectedCustomer = old('customer_id', $record?->customer_id);
            $selectedBuyer = old('buyer_id', $record?->buyer_id);
        @endphp

        <div class="ps-editor__workspace" data-payment-slip-editor data-type="{{ $type }}" data-currency="{{ $currency }}"
             data-master-url="{{ route('payment-slips.editor.master', ['kind' => '__KIND__']) }}" data-csrf="{{ csrf_token() }}" data-record-id="{{ $record?->id }}">
            <div class="ps-editor__toolbar">
                <div class="ps-editor__toolbar-actions">
                    @unless ($general)
                        <button type="button" class="ps-editor__button ps-editor__button--primary" data-ps-toggle-extract aria-expanded="false" aria-controls="ps-extraction-panel">Tambah dari PDF</button>
                    @endunless
                    <button type="button" class="ps-editor__button" data-ps-add-invoice>Tambah Invoice Manual</button>
                    <span class="ps-editor__toolbar-status" role="status" data-ps-toolbar-status></span>
                </div>
                <div class="ps-editor__view-modes" role="group" aria-label="Tampilan editor">
                    <button type="button" class="ps-editor__button" data-ps-view="form" aria-pressed="true">Form</button>
                    <button type="button" class="ps-editor__button" data-ps-view="split" aria-pressed="false">Split</button>
                    <button type="button" class="ps-editor__button" data-ps-view="pdf" aria-pressed="false">PDF</button>
                </div>
                @unless ($general)
                    <section id="ps-extraction-panel" class="ps-editor__extract ps-editor__extract-panel" aria-label="Ekstraksi invoice" data-ps-extract-url="{{ route('payment-slips.editor.extract', ['type' => $type]) }}" hidden>
                        <header class="ps-editor__section-heading"><h3>Extract Invoices via Gemini</h3><button type="button" class="ps-editor__button" data-ps-toggle-extract aria-label="Tutup panel ekstraksi">×</button></header>
                        <label for="ps-extract-files">Pilih PDF invoice</label>
                        <input id="ps-extract-files" type="file" accept="application/pdf,.pdf" multiple data-ps-extract-files aria-describedby="ps-extract-help">
                        <p id="ps-extract-help">Bisa pilih beberapa PDF, maksimal 100 MB per file. Satu PDF untuk satu invoice utama; faktur pajak dan kuitansi boleh berada di file yang sama.</p>
                        <p>File dikirim ke Gemini. Periksa hasil melalui split view dan lengkapi item seperti materai sebelum menyimpan.</p>
                        <ul class="ps-editor__file-list" data-ps-extract-list hidden></ul>
                        <button type="button" class="ps-editor__button ps-editor__button--primary" data-ps-extract>Ekstrak Invoice</button>
                        <progress class="ps-editor__progress" max="100" aria-label="Progres unggah dan ekstraksi" data-ps-extract-progress hidden></progress>
                        <p role="status" aria-live="polite" aria-atomic="true" data-ps-extract-status></p>
                    </section>
                @endunless
            </div>
            <form class="ps-editor" method="POST" enctype="multipart/form-data"
                  action="{{ $isEdit ? route('payment-slips.editor.update', $record) : route('payment-slips.editor.store', ['type' => $type]) }}"
                  novalidate wire:ignore>
                @csrf
                @if ($isEdit) @method('PUT') @endif

                @if ($errors->any())
                    <div class="ps-editor__alert" role="alert">
                        <strong>Payment Slip belum dapat disimpan.</strong>
                        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <section class="ps-editor__section" aria-labelledby="ps-header-heading">
                    <header class="ps-editor__section-heading">
                        <div><h2 id="ps-header-heading">Transaction Details</h2><p>Data utama payment slip.</p></div>
                    </header>
                    <div class="ps-editor__header-grid">
                        @if ($isEdit)
                            <div class="ps-editor__field"><label>Slip Number</label><output class="ps-editor__readonly ps-editor__code">{{ $record->slip_number }}</output></div>
                        @endif
                        <div class="ps-editor__field"><label for="ps-type">Transaction type</label>
                            @if (! $general && ! $isEdit)
                                <select id="ps-type" name="transaction_type" data-ps-type>
                                    <option value="export" @selected(old('transaction_type', $type) === 'export')>Export</option>
                                    <option value="import" @selected(old('transaction_type', $type) === 'import')>Import</option>
                                </select>
                            @else
                                <output class="ps-editor__readonly">{{ \App\Models\PaymentSlip::TRANSACTION_TYPE_LABELS[$type] }}</output>
                            @endif
                        </div>
                        @if ($general)
                            <div class="ps-editor__field">
                                <label for="ps-currency">Currency <span>*</span></label>
                                <select id="ps-currency" name="currency" required data-ps-currency>
                                    <option value="IDR" @selected($currency === 'IDR')>IDR - Rupiah</option>
                                    <option value="USD" @selected($currency === 'USD')>USD - US Dollar</option>
                                    <option value="EUR" @selected($currency === 'EUR')>EUR - Euro</option>
                                </select>
                            </div>
                            <div class="ps-editor__field">
                                <label for="ps-description">Keterangan Transaksi <span>*</span></label>
                                <input id="ps-description" name="transaction_description" maxlength="255" required value="{{ old('transaction_description', $record?->transaction_description) }}" placeholder="Contoh: biaya operasional kantor">
                                <small>Tampil pada PDF sebagai “CHARGE [KETERANGAN]”.</small>
                            </div>
                        @else
                            <div class="ps-editor__field">
                                <label for="ps-receipt">Nomor Tanda Terima Invoice</label>
                                <input id="ps-receipt" name="invoice_receipt_number" maxlength="255" value="{{ old('invoice_receipt_number', $record?->invoice_receipt_number) }}" placeholder="HIJ / 001 / VIII / 2026">
                            </div>
                        @endif
                        <div class="ps-editor__field">
                            <label for="ps-supplier">Supplier <span>*</span></label>
                            <select id="ps-supplier" name="supplier_id" required data-ps-master-select="supplier">
                                <option value="">Pilih Supplier</option>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" @selected((string) $selectedSupplier === (string) $supplier->id)>{{ $supplier->name }}</option>
                                @endforeach
                            </select><button type="button" class="ps-editor__button ps-editor__button--inline" data-ps-create-master="supplier">Buat Supplier</button>
                        </div>
                        @if ($general)
                            <div class="ps-editor__field">
                                <label for="ps-customer">Customer</label>
                                <select id="ps-customer" name="customer_id" data-ps-master-select="customer">
                                    <option value="">Pilih Customer (opsional)</option>
                                    @foreach ($customers as $customer)
                                        <option value="{{ $customer->id }}" @selected((string) $selectedCustomer === (string) $customer->id)>{{ $customer->name }}</option>
                                    @endforeach
                                </select><button type="button" class="ps-editor__button ps-editor__button--inline" data-ps-create-master="customer">Buat Customer</button>
                            </div>
                        @elseif ($type === 'export' && $record && ! $record->usesItemizedTaxes())
                            <div class="ps-editor__field">
                                <label for="ps-buyer">Buyer</label>
                                <select id="ps-buyer" name="buyer_id" data-ps-master-select="buyer">
                                    <option value="">Pilih Buyer</option>
                                    @foreach ($buyers as $buyer)
                                        <option value="{{ $buyer->id }}" @selected((string) $selectedBuyer === (string) $buyer->id)>{{ $buyer->name }}</option>
                                    @endforeach
                                </select><button type="button" class="ps-editor__button ps-editor__button--inline" data-ps-create-master="buyer">Buat Buyer</button>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="ps-editor__section" aria-labelledby="ps-invoices-heading">
                    <header class="ps-editor__section-heading">
                        <div><h2 id="ps-invoices-heading">Invoices</h2><p>Tambah invoice dan item, lalu periksa nominal sebelum menyimpan.</p></div>
                    </header>
                    @unless ($general)
                        <p class="ps-editor__verification-help">Periksa hasil dengan <strong>Preview PDF</strong> pada invoice. Koreksi isian atau gunakan <strong>Tambah Item</strong> untuk melengkapi biaya seperti materai.</p>
                    @endunless
                    <div class="ps-editor__invoices" data-ps-invoices>
                        @foreach ($invoices as $invoiceIndex => $invoice)
                            @include('filament.payment-slips.partials.invoice', compact('invoiceIndex', 'invoice', 'general', 'type', 'buyers', 'units', 'additionTaxes', 'deductionTaxes'))
                        @endforeach
                    </div>
                </section>

                <section class="ps-editor__section" aria-labelledby="ps-overview-heading">
                    <header class="ps-editor__section-heading"><h2 id="ps-overview-heading">Overview</h2></header>
                    <dl class="payment-slip-detail__overview" data-ps-overview>
                        <div class="payment-slip-detail__overview-total" data-ps-overview-total><dt>Total Amount</dt><dd class="payment-slip-detail__money" data-ps-total>{{ \App\Support\CurrencyFormatter::format($record?->grand_total_amount ?? 0, $currency) }}</dd></div>
                        <div class="payment-slip-detail__overview-status"><dt>Status</dt><dd><span class="payment-slip-detail__status payment-slip-detail__status--warning">Draft</span></dd></div>
                    </dl>
                </section>

                <div class="ps-editor__actions">
                    <button type="submit" class="ps-editor__button ps-editor__button--primary" data-ps-submit="save">{{ $isEdit ? 'Simpan' : 'Buat' }}</button>
                    @if (! $isEdit || \App\Filament\Resources\PaymentSlips\PaymentSlipResource::canSubmit($record))
                        <button type="submit" class="ps-editor__button ps-editor__button--submit" data-ps-submit="{{ $isEdit ? 'submit' : 'another' }}">{{ $isEdit ? 'Submit' : 'Buat & buat lainnya' }}</button>
                    @endif
                    <a class="ps-editor__button" href="{{ \App\Filament\Resources\PaymentSlips\PaymentSlipResource::getUrl('index') }}">Batal</a>
                </div>
                <input type="hidden" name="submit_after_save" value="0" data-ps-submit-value>
                <input type="hidden" name="create_another" value="0" data-ps-create-another>

                <template data-ps-invoice-template>
                    @include('filament.payment-slips.partials.invoice', [
                        'invoiceIndex' => '__INVOICE__', 'invoice' => ['invoice_date' => today()->format('Y-m-d'), 'tax_calculation_mode' => 'itemized', 'items' => [['quantity' => 1]]],
                        'general' => $general, 'type' => $type, 'buyers' => $buyers, 'units' => $units,
                        'additionTaxes' => $additionTaxes, 'deductionTaxes' => $deductionTaxes,
                    ])
                </template>
                <template data-ps-item-template>
                    @include('filament.payment-slips.partials.item', [
                        'invoiceIndex' => '__INVOICE__', 'itemIndex' => '__ITEM__', 'item' => ['quantity' => 1],
                        'general' => $general, 'type' => $type, 'units' => $units,
                        'additionTaxes' => $additionTaxes, 'deductionTaxes' => $deductionTaxes,
                    ])
                </template>
            </form>
            <div class="ps-editor__divider" role="separator" tabindex="0" aria-label="Lebar form dan PDF; gunakan panah kiri atau kanan" aria-orientation="vertical" aria-valuemin="35" aria-valuemax="65" aria-valuenow="55" data-ps-divider hidden></div>
            <aside class="ps-editor__viewer" aria-label="Invoice PDF viewer" hidden>
                <header><h2>Invoice PDF Viewer</h2><button type="button" data-ps-close-preview aria-label="Tutup preview">×</button></header>
                <p data-ps-preview-empty>No invoice PDF selected. Click “Preview PDF” on an invoice to view it.</p>
                <iframe title="Invoice PDF preview" data-ps-preview-frame hidden></iframe>
            </aside>
        </div>
        <dialog class="ps-editor__dialog" data-ps-master-dialog aria-labelledby="ps-master-title">
            <form method="dialog" class="ps-editor__dialog-form" data-ps-master-form>
                <header><h2 id="ps-master-title" data-ps-master-title>Buat data</h2><button type="button" data-ps-master-close aria-label="Tutup">×</button></header>
                <label>Kode <span>*</span><input name="code" required maxlength="255"></label>
                <label>Nama <span>*</span><input name="name" required maxlength="255"></label>
                <label>Alamat<textarea name="address" rows="3"></textarea></label>
                <p role="alert" data-ps-master-error hidden></p>
                <footer><button type="button" class="ps-editor__button" data-ps-master-close>Batal</button><button type="submit" class="ps-editor__button ps-editor__button--primary">Simpan</button></footer>
            </form>
        </dialog>
        <dialog class="ps-editor__dialog ps-editor__picker" data-ps-picker aria-labelledby="ps-picker-title">
            <header><h2 id="ps-picker-title">Pilih data</h2><button type="button" class="ps-editor__button" data-ps-picker-close aria-label="Tutup pilihan">×</button></header>
            <div class="ps-editor__picker-search"><label for="ps-picker-query">Cari pilihan</label><input id="ps-picker-query" type="search" placeholder="Ketik untuk mencari…" autocomplete="off" data-ps-picker-query aria-controls="ps-picker-results"></div>
            <p class="ps-editor__picker-status" role="status" data-ps-picker-status></p>
            <div id="ps-picker-results" class="ps-editor__picker-results" data-ps-picker-results></div>
        </dialog>
        <script src="{{ asset('js/payment-slip-editor.js') }}?v={{ filemtime(public_path('js/payment-slip-editor.js')) }}" defer></script>
    @endif
</x-filament-panels::page>
