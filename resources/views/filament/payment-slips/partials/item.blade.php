@php $item = is_array($item) ? $item : []; @endphp
<div class="ps-editor__item" data-ps-item data-ps-addition="{{ $item['tax_addition_amount'] ?? 0 }}" data-ps-deduction="{{ $item['tax_deduction_amount'] ?? 0 }}">
    @if (filled($item['id'] ?? null))
        <input type="hidden" name="invoices[{{ $invoiceIndex }}][items][{{ $itemIndex }}][id]" value="{{ $item['id'] }}">
    @endif
    <div class="ps-editor__item-title"><strong data-ps-item-number>Item</strong><button type="button" class="ps-editor__button ps-editor__button--remove" data-ps-remove-item>Hapus</button></div>
    <div class="ps-editor__item-grid">
        <div class="ps-editor__field ps-editor__field--item-name">
            <label>Item name *</label>
            <input name="invoices[{{ $invoiceIndex }}][items][{{ $itemIndex }}][item_name]" required maxlength="255" value="{{ $item['item_name'] ?? '' }}">
        </div>
        <div class="ps-editor__field">
            <label>Quantity *</label>
            <input type="number" step="any" name="invoices[{{ $invoiceIndex }}][items][{{ $itemIndex }}][quantity]" required data-ps-quantity value="{{ $item['quantity'] ?? 1 }}">
        </div>
        @if ($general)
            <div class="ps-editor__field"><label>Satuan @if (blank($item['id'] ?? null))<span>*</span>@endif</label><select name="invoices[{{ $invoiceIndex }}][items][{{ $itemIndex }}][unit_id]" data-ps-master-select="unit" @required(blank($item['id'] ?? null))>
                <option value="">Pilih satuan</option>
                @foreach ($units as $unit)<option value="{{ $unit->id }}" @selected((string) ($item['unit_id'] ?? '') === (string) $unit->id)>{{ $unit->name }}</option>@endforeach
            </select>@if (auth()->user()?->hasRole('maker'))<button type="button" class="ps-editor__button ps-editor__button--inline" data-ps-create-master="unit">Buat Satuan</button>@endif</div>
        @endif
        <div class="ps-editor__field">
            <label>Unit price amount *</label>
            <input type="number" step="0.01" name="invoices[{{ $invoiceIndex }}][items][{{ $itemIndex }}][unit_price_amount]" required data-ps-price value="{{ $item['unit_price_amount'] ?? '' }}">
        </div>
        @if (! $general)
            <div class="ps-editor__field"><label>Supplier Pendukung<input maxlength="255" name="invoices[{{ $invoiceIndex }}][items][{{ $itemIndex }}][source_supplier_name]" value="{{ $item['source_supplier_name'] ?? '' }}"></label></div>
        @endif
        @unless ($general)
            <div class="ps-editor__field"><label>VAT Invoice No. (Item)</label><input maxlength="255" name="invoices[{{ $invoiceIndex }}][items][{{ $itemIndex }}][vat_invoice_number]" value="{{ $item['vat_invoice_number'] ?? '' }}"></div>
        @endunless
        <div class="ps-editor__field ps-editor__item-tax" data-ps-item-tax><label>PPN</label><select name="invoices[{{ $invoiceIndex }}][items][{{ $itemIndex }}][ppn_tax_id]" data-ps-item-tax-select="addition">
            <option value="">Tanpa PPN</option>
            @foreach ($additionTaxes as $tax)<option value="{{ $tax->id }}" data-rate="{{ $tax->rate }}" @selected((string) ($item['ppn_tax_id'] ?? '') === (string) $tax->id)>{{ $tax->name }}</option>@endforeach
        </select><small data-ps-item-addition>PPN Nominal: —</small></div>
        <div class="ps-editor__field ps-editor__item-tax" data-ps-item-tax><label>PPh</label><select name="invoices[{{ $invoiceIndex }}][items][{{ $itemIndex }}][pph_tax_id]" data-ps-item-tax-select="deduction">
            <option value="">Tanpa PPh</option>
            @foreach ($deductionTaxes as $tax)<option value="{{ $tax->id }}" data-rate="{{ $tax->rate }}" @selected((string) ($item['pph_tax_id'] ?? '') === (string) $tax->id)>{{ $tax->name }}</option>@endforeach
        </select><small data-ps-item-deduction>PPh Nominal: —</small></div>
        <div class="ps-editor__field ps-editor__field--amount"><label>Amount Dibayar</label><output data-ps-item-total>—</output></div>
    </div>
</div>
