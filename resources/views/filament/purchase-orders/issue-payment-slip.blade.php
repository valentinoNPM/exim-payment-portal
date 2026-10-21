<x-filament-panels::page>
    @php($po = $this->getRecord()->loadMissing(['items.unit', 'supplier']))
    <form method="POST" enctype="multipart/form-data" action="{{ route('purchase-orders.issue-payment-slip', $po) }}" class="ps-editor">
        @csrf
        <section class="ps-editor__section">
            <h2>Invoice supplier</h2>
            <p>Setelah draft disimpan, PO dan data sumber dikunci permanen. Nomor invoice di bawah adalah nomor dari supplier, bukan nomor PO.</p>
            @if ($errors->any())<div class="ps-editor__alert" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="ps-editor__invoice-grid">
                <div class="ps-editor__field"><label for="po-slip-description">Keterangan Transaksi</label><input id="po-slip-description" name="transaction_description" value="{{ old('transaction_description') }}" placeholder="Contoh: biaya operasional kantor" required maxlength="255"></div>
                <div class="ps-editor__field"><label for="po-slip-number">Nomor Invoice Supplier</label><input id="po-slip-number" name="invoice_number" value="{{ old('invoice_number') }}" required maxlength="255"></div>
                <div class="ps-editor__field"><label for="po-slip-date">Tanggal Invoice</label><input id="po-slip-date" type="date" name="invoice_date" value="{{ old('invoice_date', today()->toDateString()) }}" required></div>
                <div class="ps-editor__field"><label for="po-slip-file">PDF Invoice (opsional)</label><input id="po-slip-file" type="file" name="document_pdf" accept="application/pdf,.pdf"><small>Maksimal 100 MB. File tidak dikirim ke Gemini.</small></div>
            </div>
        </section>
        @include('filament.payment-slips.partials.general-approval-fields', ['record' => null])
        <div class="ps-editor__toolbar-actions">
            <button class="ps-editor__button ps-editor__button--primary" type="submit">Simpan Draft Payment Slip</button>
            <a class="ps-editor__button" href="{{ \App\Filament\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('view', ['record' => $po]) }}">Kembali ke PO</a>
        </div>
        @include('filament.purchase-orders.partials.slip-source', ['po' => $po])
    </form>
</x-filament-panels::page>
