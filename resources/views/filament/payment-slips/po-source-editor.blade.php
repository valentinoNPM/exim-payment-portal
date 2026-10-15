<x-filament-panels::page>
    <form method="POST" enctype="multipart/form-data" action="{{ route('payment-slips.editor.update', $record) }}" class="ps-editor">
        @csrf @method('PUT')
        <section class="ps-editor__section">
            <h2>Lengkapi invoice supplier</h2><p>Data sumber PO terkunci. Koreksi hanya tersedia untuk identitas invoice dan lampiran.</p>
            @if ($errors->any())<div class="ps-editor__alert" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="ps-editor__field"><label for="po-slip-description">Keterangan Transaksi</label><input id="po-slip-description" name="transaction_description" value="{{ old('transaction_description', $record->transaction_description) }}" placeholder="Contoh: biaya operasional kantor" required maxlength="255"></div>
            @foreach ($record->invoices as $index => $invoice)
                <input type="hidden" name="invoices[{{ $index }}][id]" value="{{ $invoice->id }}">
                <div class="ps-editor__invoice-grid">
                    <div class="ps-editor__field"><label for="po-inv-{{ $index }}">Nomor Invoice Supplier</label><input id="po-inv-{{ $index }}" name="invoices[{{ $index }}][invoice_number]" value="{{ old('invoices.'.$index.'.invoice_number', $invoice->invoice_number) }}" required maxlength="255"></div>
                    <div class="ps-editor__field"><label for="po-date-{{ $index }}">Tanggal Invoice</label><input id="po-date-{{ $index }}" type="date" name="invoices[{{ $index }}][invoice_date]" value="{{ old('invoices.'.$index.'.invoice_date', $invoice->invoice_date?->toDateString()) }}" required></div>
                    <div class="ps-editor__field"><label for="po-vat-{{ $index }}">VAT Invoice No. Utama</label><input id="po-vat-{{ $index }}" name="invoices[{{ $index }}][vat_invoice_number]" value="{{ old('invoices.'.$index.'.vat_invoice_number', $invoice->vat_invoice_number) }}" maxlength="255"></div>
                    <div class="ps-editor__field"><label for="po-file-{{ $index }}">Ganti PDF Invoice (opsional)</label><input id="po-file-{{ $index }}" type="file" name="invoices[{{ $index }}][document_pdf]" accept="application/pdf,.pdf"></div>
                </div>
            @endforeach
        </section>
        <button class="ps-editor__button ps-editor__button--primary" type="submit">Simpan</button>
        <button class="ps-editor__button" type="submit" name="submit_after_save" value="1">Simpan dan Submit</button>
    </form>
    @include('filament.payment-slips.read-only.general', ['slip' => $record, 'poSourceEditor' => true])
</x-filament-panels::page>
