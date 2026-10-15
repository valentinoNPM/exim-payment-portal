@php
    $invoice = is_array($invoice) ? $invoice : [];
    $mode = $invoice['tax_calculation_mode'] ?? 'itemized';
    $items = $invoice['items'] ?? [['quantity' => 1]];
    $items = is_array($items) && $items !== [] ? array_values($items) : [['quantity' => 1]];
    $showInvoiceBuyer = ! $general && ($type === 'import' || ! isset($record) || ! $record || $record->usesItemizedTaxes());
@endphp
<article class="ps-editor__invoice" data-ps-invoice data-ps-invoice-key="{{ $invoiceIndex }}">
    <header class="ps-editor__invoice-heading">
        <h3>Invoice <span data-ps-invoice-number></span></h3>
        <button type="button" class="ps-editor__button ps-editor__button--remove" data-ps-remove-invoice>Hapus Invoice</button>
    </header>
    @if (filled($invoice['id'] ?? null))
        <input type="hidden" name="invoices[{{ $invoiceIndex }}][id]" value="{{ $invoice['id'] }}">
    @endif
    <input type="hidden" name="invoices[{{ $invoiceIndex }}][document_file_id]" value="{{ $invoice['document_file_id'] ?? '' }}" data-ps-document-id>
    <div class="ps-editor__invoice-grid">
        @if ($showInvoiceBuyer)
            <div class="ps-editor__field">
                <label for="ps-buyer-{{ $invoiceIndex }}">Buyer <span>*</span></label>
                <select id="ps-buyer-{{ $invoiceIndex }}" name="invoices[{{ $invoiceIndex }}][buyer_id]" data-ps-master-select="buyer">
                    <option value="">Pilih Buyer untuk invoice ini</option>
                    @foreach ($buyers as $buyer)
                        <option value="{{ $buyer->id }}" @selected((string) ($invoice['buyer_id'] ?? '') === (string) $buyer->id)>{{ $buyer->name }}</option>
                    @endforeach
                </select><button type="button" class="ps-editor__button ps-editor__button--inline" data-ps-create-master="buyer">Buat Buyer</button>
            </div>
        @endif
        <div class="ps-editor__field">
            <label for="ps-number-{{ $invoiceIndex }}">{{ $general ? 'Nomor Nota' : 'Invoice number' }} <span>*</span></label>
            <input id="ps-number-{{ $invoiceIndex }}" name="invoices[{{ $invoiceIndex }}][invoice_number]" maxlength="255" required value="{{ $invoice['invoice_number'] ?? '' }}">
        </div>
        <div class="ps-editor__field">
            <label for="ps-date-{{ $invoiceIndex }}">Invoice date <span>*</span></label>
            <input id="ps-date-{{ $invoiceIndex }}" type="date" name="invoices[{{ $invoiceIndex }}][invoice_date]" required value="{{ $invoice['invoice_date'] ?? today()->format('Y-m-d') }}">
        </div>
        <div class="ps-editor__field">
            <label for="ps-vat-{{ $invoiceIndex }}">VAT Invoice No. Utama</label>
            <input id="ps-vat-{{ $invoiceIndex }}" name="invoices[{{ $invoiceIndex }}][vat_invoice_number]" maxlength="255" value="{{ $invoice['vat_invoice_number'] ?? '' }}">
            <small>Nomor faktur pajak invoice utama; digunakan pada baris AP ERP.</small>
        </div>
        @unless ($general)
            <div class="ps-editor__field">
                <label for="ps-tax-mode-{{ $invoiceIndex }}">Mode pajak</label>
                <select id="ps-tax-mode-{{ $invoiceIndex }}" name="invoices[{{ $invoiceIndex }}][tax_calculation_mode]" data-ps-tax-mode>
                    <option value="itemized" @selected($mode === 'itemized')>Pajak per item</option>
                    <option value="invoice_legacy" @selected($mode === 'invoice_legacy')>Pajak seluruh invoice</option>
                </select>
            </div>
        @else
            <input type="hidden" name="invoices[{{ $invoiceIndex }}][tax_calculation_mode]" value="{{ $mode }}" data-ps-tax-mode>
        @endunless
        <div class="ps-editor__field ps-editor__field--wide">
            <label for="ps-file-{{ $invoiceIndex }}">Document file</label>
            <div class="ps-editor__file-row">
                <input id="ps-file-{{ $invoiceIndex }}" type="file" name="invoices[{{ $invoiceIndex }}][document_pdf]" accept="application/pdf" data-ps-file>
                <button type="button" class="ps-editor__button" data-ps-preview="{{ $invoice['document_url'] ?? '' }}">Preview PDF</button>
            </div>
            <small data-ps-document-name>@if (filled($invoice['document_name'] ?? null))File saat ini: {{ $invoice['document_name'] }}@endif</small>
        </div>
    </div>

    <div class="ps-editor__invoice-taxes" data-ps-invoice-taxes @if ($mode !== 'invoice_legacy') hidden @endif>
        <p>Pajak dihitung sekali dari subtotal invoice. Pajak per item tidak digunakan pada mode ini.</p>
        <div class="ps-editor__invoice-grid">
            <div class="ps-editor__field">
                <label>PPN (Penambahan)</label>
                <select name="invoices[{{ $invoiceIndex }}][ppn_tax_id]" data-ps-invoice-tax="addition">
                    <option value="">Tanpa PPN</option>
                    @foreach ($additionTaxes as $tax)
                        <option value="{{ $tax->id }}" data-rate="{{ $tax->rate }}" @selected((string) ($invoice['ppn_tax_id'] ?? '') === (string) $tax->id)>{{ $tax->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ps-editor__field">
                <label>PPh (Pengurangan)</label>
                <select name="invoices[{{ $invoiceIndex }}][pph_tax_id]" data-ps-invoice-tax="deduction">
                    <option value="">Tanpa PPh</option>
                    @foreach ($deductionTaxes as $tax)
                        <option value="{{ $tax->id }}" data-rate="{{ $tax->rate }}" @selected((string) ($invoice['pph_tax_id'] ?? '') === (string) $tax->id)>{{ $tax->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="ps-editor__items-heading"><h4>Items</h4><button type="button" class="ps-editor__button ps-editor__button--add" data-ps-add-item>Tambah Item</button></div>
    <div class="ps-editor__items" data-ps-items>
        @foreach ($items as $itemIndex => $item)
            @include('filament.payment-slips.partials.item', compact('invoiceIndex', 'itemIndex', 'item', 'general', 'type', 'units', 'additionTaxes', 'deductionTaxes'))
        @endforeach
    </div>
    @if ($general)
        <div class="ps-editor__invoice-grid">
            @foreach (['discount_amount' => 'Diskon', 'shipping_amount' => 'Ongkos Kirim'] as $field => $label)
                <div class="ps-editor__field">
                    <label for="ps-{{ $field }}-{{ $invoiceIndex }}">{{ $label }}</label>
                    <input id="ps-{{ $field }}-{{ $invoiceIndex }}" type="number" min="0" step="any" name="invoices[{{ $invoiceIndex }}][{{ $field }}]" value="{{ $invoice[$field] ?? '' }}" data-ps-adjustment="{{ $field }}" placeholder="0">
                </div>
            @endforeach
        </div>
    @endif
    <dl class="ps-editor__invoice-summary">
        <div><dt>Subtotal</dt><dd data-ps-invoice-subtotal>—</dd></div>
        <div><dt>PPN</dt><dd data-ps-invoice-addition>—</dd></div>
        <div><dt>PPh</dt><dd data-ps-invoice-deduction>—</dd></div>
        <div><dt>Amount Dibayar</dt><dd data-ps-invoice-total>—</dd></div>
    </dl>
</article>
